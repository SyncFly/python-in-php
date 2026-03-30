import os
import asyncio
import websockets
import json
import sys
import importlib
import argparse
import signal
import time
import logging
import traceback
import uuid
import weakref
from typing import Dict, Any, Optional, Union, Callable, Awaitable
from types import GeneratorType
from collections.abc import Iterable
import decimal
import inspect
import gc
import types
import ctypes
from module_inspector import ModuleInspector
# from numpy import integer as numpy_integer, floating as numpy_floating, bool_ as numpy_bool_
import math

libc = ctypes.CDLL("libc.so.6")
PR_SET_PDEATHSIG = 1
libc.prctl(PR_SET_PDEATHSIG, signal.SIGKILL)

if os.getppid() == 1:
    raise SystemExit("Parent already dead")

verbose = "--verbose" in sys.argv
logging.basicConfig(
    level=logging.INFO if verbose else logging.WARNING,
    format="%(asctime)s - %(levelname)s - %(message)s"
)
logger = logging.getLogger(__name__)

try:
    signal.signal(signal.SIGPIPE, signal.SIG_DFL)
except Exception as e:
    pass

class ObjectReference:
    """Class representing a reference to a Python object"""
    def __init__(self, obj_id: str, obj_type: str, is_callable: bool = False, is_async: bool = False, methods: list = None, is_generator: bool = False):
        self.obj_id = obj_id
        self.obj_type = obj_type
        self.is_callable = is_callable
        self.is_async = is_async
        self.methods = methods or []
        self.is_generator = is_generator

    def to_dict(self):
        return {
            '__python_ref__': True,
            'obj_id': self.obj_id,
            'obj_type': self.obj_type,
            'is_callable': self.is_callable,
            'is_async': self.is_async,
            'methods': self.methods,
            'is_generator': self.is_generator
        }


class PythonBridgeServer:
    def __init__(self, host: str = 'localhost', port: int = 8765):
        self.host = host
        self.port = port
        self.modules: Dict[str, Any] = {}
        self.globals_dict = {
            '__name__': '__main__',
            '__builtins__': __builtins__,
            'next': next,
            'iter': iter,
            'print': print
        }
        self.clients = set()
        self.server = None
        self.context_managers = {}

        # Object store with improved memory management
        self.object_store: Dict[str, Any] = {}
        self.object_refs: Dict[str, weakref.ref] = {}
        self._serialization_depth = 0  # Serialization depth counter
        self._max_serialization_depth = 1000  # Max depth to prevent recursion

        # Preload commonly used modules
        self.preload_common_modules()

        # Configure signal handlers for graceful shutdown
        if sys.platform != "win32":
            signal.signal(signal.SIGINT, self.signal_handler)
            signal.signal(signal.SIGTERM, self.signal_handler)

        # Periodic memory cleanup
        self._last_cleanup = time.monotonic()
        #self._last_cleanup = asyncio.get_event_loop().time()

    def signal_handler(self, signum, frame):
        """Signal handler for graceful shutdown"""
        logger.info(f"Received signal {signum}, shutting down...")
        try:
            loop = asyncio.get_event_loop()
            loop.create_task(self.shutdown())
        except RuntimeError:
            # If the event loop is not running, just exit
            sys.exit(0)

    def preload_common_modules(self):
        """Preload popular modules"""
        common_modules = [
            """ 'math', 'datetime', 'json', 'os', 'sys', 're',
            'random', 'time', 'base64', 'hashlib', 'urllib',
            'collections', 'itertools', 'functools', 'operator',
            'builtins' """
            'builtins', 'datetime', 'json', 'sys'
        ]

        for module_name in common_modules:
            try:
                module = importlib.import_module(module_name)
                self.modules[module_name] = module
                self.globals_dict[module_name] = module
                logger.debug(f"Preloaded module: {module_name}")
            except ImportError as e:
                logger.debug(f"Failed to preload module {module_name}: {e}")

    def cleanup_objects(self):
        """Periodic object cleanup"""
        try:
            current_time = asyncio.get_event_loop().time()
            if current_time - self._last_cleanup > 300:  # Cleanup every 5 minutes
                # Remove objects that no longer have weak references
                to_remove = []
                for obj_id, weak_ref in list(self.object_refs.items()):
                    if weak_ref() is None:
                        to_remove.append(obj_id)

                for obj_id in to_remove:
                    self.object_store.pop(obj_id, None)
                    self.object_refs.pop(obj_id, None)

                if to_remove:
                    logger.info(f"Cleaned up {len(to_remove)} objects")

                # Force garbage collection
                gc.collect()
                self._last_cleanup = current_time
        except Exception as e:
            logger.error(f"Error during object cleanup: {e}")

    def is_async_callable(self, obj: Any) -> bool:
        """Check whether an object is an async callable"""
        if not callable(obj):
            return False
        return inspect.iscoroutinefunction(obj) or inspect.isawaitable(obj)

    def store_object(self, obj: Any) -> str:
        """Store an object and return its ID"""
        obj_id = str(uuid.uuid4())
        self.object_store[obj_id] = obj

        # Create a weak reference for automatic cleanup
        def cleanup_callback(ref):
            if obj_id in self.object_store:
                self.object_store.pop(obj_id, None)
                logger.debug(f"Object {obj_id} was automatically removed from the store")

        try:
            self.object_refs[obj_id] = weakref.ref(obj, cleanup_callback)
        except TypeError:
            # Some objects do not support weak references
            pass

        logger.debug(f"Stored object with ID: {obj_id}")
        return obj_id

    def get_object(self, obj_id: str) -> Any:
        """Get an object from the store by ID"""
        return self.object_store.get(obj_id)

    def is_generator(self, obj: Any) -> bool:
        """Check whether an object is a generator or iterator"""
        try:
            # Check if it's a generator
            if inspect.isgenerator(obj) or inspect.isgeneratorfunction(obj):
                return True

            # Check if it's an iterator
            if hasattr(obj, '__iter__') and hasattr(obj, '__next__'):
                return True

            # Check if it's iterable
            if hasattr(obj, '__iter__'):
                # But not a list/tuple/set/dict/string/bytes
                if not isinstance(obj, (list, tuple, set, dict, str, bytes)):
                    return True

            return False
        except Exception:
            return False

    def get_object_methods(self, obj: Any) -> list:
        """Get a list of object methods (with special-case handling)"""
        methods = []
        try:
            # Get all object attributes
            for attr in dir(obj):
                # Skip private attributes except dunder methods
                #if attr.startswith('_') and not attr.startswith('__'):
                    #continue

                try:
                    method = getattr(obj, attr)
                    if callable(method):
                        # Check if method is async
                        is_async = self.is_async_callable(method)
                        methods.append({
                            'name': attr,
                            'is_async': is_async
                        })
                except Exception:
                    continue

        except Exception as e:
            logger.warning(f"Error getting methods for object {type(obj).__name__}: {str(e)}")

        return methods

    def create_object_reference(self, obj: Any) -> ObjectReference:
        """Create an object reference"""
        obj_id = self.store_object(obj)
        obj_type = type(obj).__name__
        is_callable = callable(obj)
        is_async = self.is_async_callable(obj)
        is_generator = self.is_generator(obj)

        # Get the list of methods for the object
        #methods = self.get_object_methods(obj)
        methods = []

        logger.debug(f"Created reference for object {obj_type} (ID: {obj_id}, is_generator: {is_generator})")
        return ObjectReference(obj_id, obj_type, is_callable, is_async, methods, is_generator)

    def should_return_reference(self, obj: Any) -> bool:
        """Decide whether to return a reference for an object"""
        # Return simple types as-is
        simple_types = (int, float, str, bool, type(None))

        if isinstance(obj, simple_types):
            return False

        # For small collections of simple types, return as-is
        if isinstance(obj, (list, tuple)):
            if len(obj) <= 100 and all(isinstance(item, simple_types) for item in obj):
                return False
        elif isinstance(obj, dict):
            if len(obj) <= 100 and all(isinstance(value, simple_types) for value in obj.values()):
                return False

        # Always return a reference for spaCy objects
        if hasattr(obj, '__module__') and str(obj.__module__).startswith('spacy'):
            return True

        # For other complex objects, return a reference
        return True

    async def handle_client(self, websocket):
        """Handle a client connection"""
        client_addr = websocket.remote_address
        logger.info(f"New connection: {client_addr}")
        self.clients.add(websocket)

        try:
            async for message in websocket:
                try:
                    logger.debug(f"Received message from {client_addr}: {message[:100]}...")

                    # Periodic object cleanup
                    self.cleanup_objects()

                    # Parse JSON
                    try:
                        data = json.loads(message)
                    except json.JSONDecodeError as e:
                        error_response = {
                            'error': f'JSON decode error: {str(e)}',
                            'string': message,
                            'result': None,
                            'id': None
                        }
                        await self.send_response(websocket, error_response)
                        continue

                    # Process command
                    response = await self.process_command(data)

                    # Check if this is a shutdown command
                    if data.get('command') == 'shutdown':
                        await self.send_response(websocket, response)
                        break

                    # Send response
                    await self.send_response(websocket, response)

                except websockets.exceptions.ConnectionClosed:
                    logger.info(f"Connection closed by client: {client_addr}")
                    break
                except websockets.exceptions.InvalidState:
                    logger.info(f"Invalid connection state: {client_addr}")
                    break
                except Exception as e:
                    logger.error(f"Error processing message from {client_addr}: {e}")
                    logging.exception(e)
                    error_response = {
                        'error': f'Message processing error: {str(e)}',
                        'result': None,
                        'id': data.get('id') if 'data' in locals() else None
                    }
                    try:
                        await self.send_response(websocket, error_response)
                    except:
                        logger.error("Failed to send an error message")
                        break

        except websockets.exceptions.ConnectionClosed as e:
            logger.debug(e.code, e.reason)
            logger.info(f"Connection closed: {client_addr}")
        except Exception as e:
            logger.error(f"Error handling client {client_addr}: {e}")
        finally:
            self.clients.discard(websocket)
            logger.info(f"Client {client_addr} disconnected")

    async def send_response(self, websocket, response):
        """Send a response to a client, handling object references"""
        logger.debug("Preparing the response")
        try:
            # Reset serialization depth counter
            self._serialization_depth = 0

            # Process the result to create references if needed
            if 'result' in response and response['result'] is not None:
                try:
                    response['result'] = self.serialize_for_json(response['result'])
                except Exception as e:
                    logger.error(f"Error while processing result: {str(e)}")
                    logging.exception(e)
                    response['error'] = f'Result processing error: {str(e)}'
                    response['result'] = None

            # Serialize and send response
            try:
                json_response = json.dumps(response, ensure_ascii=False, default=str)
                logger.debug("Response: " + json_response)
                await websocket.send(json_response)
                logger.debug("Response successfully sent")

            except websockets.exceptions.ConnectionClosed as e:
                logger.debug(e.code, e.reason)
                logger.error("Connection closed while sending")
                return
            except Exception as e:
                logger.error(f"Error sending response: {str(e)}")
                # Send a simplified error response
                error_response = {
                    'error': f'Response sending error: {str(e)}',
                    'result': None,
                    'id': response.get('id')
                }
                try:
                    await websocket.send(json.dumps(error_response))
                except:
                    logger.error("Failed to send even an error message")

        except Exception as e:
            logger.error(f"Critical error while sending response: {str(e)}")

    def serialize_for_json(self, obj: Any) -> Any:
        """
        Unified object serialization for JSON with recursion protection
        """
        if self._serialization_depth >= self._max_serialization_depth:
            logger.warning("Maximum serialization depth reached")
            return str(obj)

        self._serialization_depth += 1

        try:
            logger.info(f"Serializing: {type(obj).__name__}")

            # Primitive types
            if isinstance(obj, (int, float, str, bool, type(None))):
                if isinstance(obj, float) and (math.isinf(obj) or math.isnan(obj)):
                    return None  # TODO
                return obj

            logger.info(f"Serializing: {type(obj).__name__}")

            if getattr(type(obj), "__module__", "").startswith("spacy"):
                return str(obj)

            # Collections
            if isinstance(obj, (list, tuple)):
                return [self.serialize_for_json(item) for item in obj]

            if isinstance(obj, set):
                return [self.serialize_for_json(item) for item in obj]

            if isinstance(obj, dict):
                return {str(key): self.serialize_for_json(value) for key, value in obj.items()}

            # Complex objects → references
            if self.should_return_reference(obj):
                ref = self.create_object_reference(obj)
                return ref.to_dict()

            # Fallback: stringify
            return str(obj)

        except Exception as e:
            logger.error(f"Error serializing object {type(obj)}: {str(e)}")
            return str(obj)
        finally:
            self._serialization_depth -= 1

    def normalize_kwargs(self, kwargs):
        """Normalize kwargs to prevent unpacking errors"""
        if kwargs is None:
            return {}
        elif isinstance(kwargs, dict):
            return kwargs
        elif isinstance(kwargs, list):
            logger.warning(f"Received a list instead of a dict for kwargs: {kwargs}")
            return {}
        else:
            logger.warning(f"Unexpected type for kwargs: {type(kwargs)}, value: {kwargs}")
            return {}

    def normalize_args(self, args):
        """Normalize args to prevent errors"""
        if args is None:
            return []
        elif isinstance(args, list):
            return args
        elif isinstance(args, tuple):
            return list(args)
        else:
            return [args]

    def resolve_object_references(self, data):
        """Recursively resolve object references in data"""
        if isinstance(data, dict):
            if data.get('__python_ref__'):
                obj_id = data.get('obj_id')
                obj = self.get_object(obj_id)
                if obj is None:
                    raise ValueError(f'Object with ID {obj_id} not found')
                return obj
            else:
                return {key: self.resolve_object_references(value) for key, value in data.items()}
        elif isinstance(data, list):
            return [self.resolve_object_references(item) for item in data]
        else:
            return data

    async def process_command(self, data: Dict[str, Any]) -> Dict[str, Any]:
        """Process a command from PHP"""
        command = data.get('command')
        args = data.get('args', [])
        command_id = data.get('id')

        logger.info(f"Processing command: {command} (ID: {command_id})")

        try:
            if command == 'import':
                result = await self.handle_import(args)
            elif command == 'call':
                result = await self.handle_call(args)
            elif command == 'exec':
                result = await self.handle_exec(args)
            elif command == 'eval':
                result = await self.handle_eval(args)
            elif command == 'call_method':
                result = await self.handle_call_method(args)
            elif command == 'call_object':
                result = await self.handle_call_object(args)
            elif command == 'get_attribute':
                result = await self.handle_get_attribute(args)
            elif command == 'release_object':
                result = await self.handle_release_object(args)
            elif command == 'to_string':
                result = await self.handle_to_string(args)
            elif command == 'is_generator':
                result = await self.handle_is_generator(args)
            elif command == 'get_module_names_in_packages':
                result = await self.handle_get_module_names_in_packages(args)
            elif command == 'inspect_modules':
                result = await self.handle_inspect_modules(args)
            elif command == 'get_methods_and_properties':
                result = await self.handle_get_methods_and_properties(args)
            elif command == 'shutdown':
                result = await self.handle_shutdown()
            elif command == 'ping':
                result = {'error': None, 'result': 'pong'}
            elif command == 'list_modules':
                result = {'error': None, 'result': list(self.modules.keys())}
            elif command == 'list_objects':
                result = {'error': None, 'result': list(self.object_store.keys())}
            else:
                result = {
                    'error': f'Unknown command: {command}',
                    'result': None
                }

            result['id'] = command_id
            return result

        except Exception as e:
            logger.error(f"Error executing command {command}: {e}")
            return {
                'error': f'Command execution failed: {str(e)}',
                'result': None,
                'id': command_id
            }

    def safe_json(self, value):
        if isinstance(value, (bytes, bytearray)):
            return value.decode("utf-8", errors="ignore")

        try:
            json.dumps(value)
            return value
        except Exception:
            return repr(value)

    async def handle_call_method(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Call an object method by reference"""
        try:
            obj_id = args.get('obj_id')
            method_name = args.get('method')
            method_args = self.normalize_args(args.get('args', []))
            method_kwargs = self.normalize_kwargs(args.get('kwargs', {}))

            if not obj_id or not method_name:
                return {
                    'error': 'Object ID and method name are required',
                    'result': None
                }

            obj = self.get_object(obj_id)
            if obj is None:
                return {
                    'error': f'Object with ID {obj_id} not found',
                    'result': None
                }

            logger.info(f"Calling method {method_name} on object {obj_id}")

            # Get method
            try:
                method = getattr(obj, method_name)
            except AttributeError:
                return {
                    'error': f'Method {method_name} not found in object',
                    'result': None
                }

            if not callable(method):
                return {
                    'error': f'Attribute {method_name} is not callable',
                    'result': None
                }

            # Process arguments
            processed_args = self.resolve_object_references(method_args)
            processed_kwargs = self.resolve_object_references(method_kwargs)

            # Call method
            try:
                if asyncio.iscoroutinefunction(method):
                    result = await method(*processed_args, **processed_kwargs)
                else:
                    result = method(*processed_args, **processed_kwargs)

                return {
                    'error': None,
                    'result': result
                }
            except StopIteration:
                return {
                    'error': 'StopIteration',
                    'result': None
                }

        except Exception as e:
            logger.error(f"Error in handle_call_method: {str(e)}")
            tb = traceback.format_exc()

            data = {}
            if hasattr(e, "__dict__"):
                for k, v in e.__dict__.items():
                    data[k] = self.safe_json(v)

            return {
                'error': f'Method call failed: {str(e)}',
                'traceback': tb,
                'data': data,
                'result': None
            }

    async def handle_call_object(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Call an object like a function"""
        try:
            obj_id = args.get('obj_id')
            call_args = self.normalize_args(args.get('args', []))
            call_kwargs = self.normalize_kwargs(args.get('kwargs', {}))

            if not obj_id:
                return {
                    'error': 'Object ID is required',
                    'result': None
                }

            obj = self.get_object(obj_id)
            if obj is None:
                return {
                    'error': f'Object with ID {obj_id} not found',
                    'result': None
                }

            if not callable(obj):
                return {
                    'error': f'Object {obj_id} is not callable',
                    'result': None
                }

            logger.info(f"Calling object {obj_id} like a function")

            # Process arguments
            processed_args = self.resolve_object_references(call_args)
            processed_kwargs = self.resolve_object_references(call_kwargs)

            # Call object
            try:
                if asyncio.iscoroutinefunction(obj):
                    result = await obj(*processed_args, **processed_kwargs)
                else:
                    result = obj(*processed_args, **processed_kwargs)

                return {
                    'error': None,
                    'result': result
                }
            except Exception as e:
                logger.error(f"Error while calling object: {str(e)}")
                raise

        except Exception as e:
            logger.error(f"Error in handle_call_object: {str(e)}")
            return {
                'error': f'Object call failed: {str(e)}',
                'result': None
            }

    async def handle_get_attribute(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Get an object attribute"""
        try:
            obj_id = args.get('obj_id')
            attr_name = args.get('attribute')

            if not obj_id or not attr_name:
                return {
                    'error': 'Object ID and attribute name are required',
                    'result': None
                }

            obj = self.get_object(obj_id)
            if obj is None:
                return {
                    'error': f'Object with ID {obj_id} not found',
                    'result': None
                }

            logger.info(f"Getting attribute {attr_name} from object {obj_id}")

            try:
                result = getattr(obj, attr_name)
                return {
                    'error': None,
                    'result': result
                }
            except AttributeError as e:
                return {
                    'error': f'Attribute not found: {str(e)}',
                    'result': None
                }

        except Exception as e:
            logger.error(f"Error while getting attribute: {str(e)}")
            return {
                'error': f'Get attribute failed: {str(e)}',
                'result': None
            }

    async def handle_release_object(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Release an object from the store"""
        try:
            obj_id = args.get('obj_id')

            if not obj_id:
                return {
                    'error': 'Object ID is required',
                    'result': None
                }

            if obj_id in self.object_store:
                self.object_store.pop(obj_id, None)
                self.object_refs.pop(obj_id, None)
                logger.info(f"Object {obj_id} released")
                return {
                    'error': None,
                    'result': f'Object {obj_id} released'
                }
            else:
                return {
                    'error': f'Object with ID {obj_id} not found',
                    'result': None
                }

        except Exception as e:
            return {
                'error': f'Release object failed: {str(e)}',
                'result': None
            }

    async def handle_to_string(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Convert an object to string"""
        try:
            obj_id = args.get('obj_id')

            if not obj_id:
                return {
                    'error': 'Object ID is required',
                    'result': None
                }

            obj = self.get_object(obj_id)
            if obj is None:
                return {
                    'error': f'Object with ID {obj_id} not found',
                    'result': None
                }

            result = str(obj)
            return {
                'error': None,
                'result': result
            }

        except Exception as e:
            return {
                'error': f'To string failed: {str(e)}',
                'result': None
            }

    async def handle_is_generator(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Check whether an object is a generator"""
        try:
            obj_id = args.get('obj_id')

            if not obj_id:
                return {
                    'error': 'Object ID is required',
                    'result': None
                }

            obj = self.get_object(obj_id)
            if obj is None:
                return {
                    'error': f'Object with ID {obj_id} not found',
                    'result': None
                }

            is_gen = self.is_generator(obj)
            return {
                'error': None,
                'result': is_gen
            }

        except Exception as e:
            return {
                'error': f'Is generator check failed: {str(e)}',
                'result': None
            }

    async def handle_get_module_names_in_packages(self, args: Dict[str, Any]) -> Dict[str, Any]:
            """Get top-level module names for installed packages"""
            try:
                packages = args.get('packages')
                if not packages:
                    return {
                        'error': 'packages are required',
                        'result': None
                    }

                logger.info(f"Getting module names for packages: {packages}")

                # Execute
                try:
                    import importlib.metadata
                    result = set()

                    for package in packages:
                        try:
                            dist = importlib.metadata.distribution(package)

                            for f in dist.files:
                                top = str(f).split("/")[0]  # top-level directory
                                if not top.endswith(".dist-info") and not top.startswith("__") and top.isidentifier():
                                    result.add(top)
                        except Exception:
                            pass

                    return {
                        'error': None,
                        'result': result
                    }
                except StopIteration:
                    return {
                        'error': 'StopIteration',
                        'result': None
                    }

            except Exception as e:
                logger.error(f"Error in handle_get_module_names_in_packages: {str(e)}")
                return {
                    'error': f'Methods list receiving failed: {str(e)}',
                    'result': None
                }

    async def handle_inspect_modules(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Inspect modules"""
        try:
            modules = args.get('modules')
            if not modules:
                return {
                    'error': 'modules are required',
                    'result': None
                }

            logger.info(f"Getting methods list for modules: {modules}")

            # Execute
            try:
                inspector = ModuleInspector()
                result = inspector.inspect_modules_parallel_isolated(modules)

                return {
                    'error': None,
                    'result': result
                }
            except StopIteration:
                return {
                    'error': 'StopIteration',
                    'result': None
                }

        except Exception as e:
            logger.error(f"Error in handle_inspect_modules: {str(e)}")
            return {
                'error': f'Methods list receiving failed: {str(e)}',
                'result': None
            }

    async def handle_get_methods_and_properties(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Inspect methods and properties of an object by reference"""
        try:
            obj_id = args.get('obj_id')
            if not obj_id:
                return {
                    'error': 'Object ID and method name are required',
                    'result': None
                }

            obj = self.get_object(obj_id)
            if obj is None:
                return {
                    'error': f'Object with ID {obj_id} not found',
                    'result': None
                }

            logger.info(f"Getting methods list for object {obj_id}")

            # Execute
            try:
                inspector = ModuleInspector()
                result = inspector.inspect(obj)

                return {
                    'error': None,
                    'result': result
                }
            except StopIteration:
                return {
                    'error': 'StopIteration',
                    'result': None
                }

        except Exception as e:
            logger.error(f"Error in handle_get_methods_and_properties: {str(e)}")
            return {
                'error': f'Methods list receiving failed: {str(e)}',
                'result': None
            }

    async def handle_import(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Import a Python module"""
        try:
            module_name = args.get('module')
            alias = args.get('alias')

            if not module_name:
                return {
                    'error': 'Module name is required',
                    'result': None
                }

            if module_name in self.modules:
                return {
                    'error': None,
                    'result': self.modules[module_name]
                }

            logger.info(f"Importing module: {module_name} (alias: {alias})")

            # Find the longest importable module path
            parts = module_name.split('.')
            module = None
            actual_module_name = None
            
            # Try importing from longest to shortest path
            for i in range(len(parts), 0, -1):
                try_module_name = '.'.join(parts[:i])
                try:
                    module = importlib.import_module(try_module_name)
                    actual_module_name = try_module_name
                    logger.info(f"Successfully imported module: {actual_module_name}")
                    break
                except ImportError as e:
                    last_error = e
                    continue
            
            if module is None:
                if last_error:
                    raise last_error
                else:
                    raise ImportError(f"No module found in path: {module_name}")

            # Store both full name and alias
            self.modules[actual_module_name] = module
            self.globals_dict[actual_module_name] = module

            if alias:
                self.modules[alias] = module
                self.globals_dict[alias] = module

            # Handle compound imports (e.g. 'os.path')
            if '.' in module_name:
                # Store all parent modules
                for i in range(1, len(parts)):
                    parent_name = '.'.join(parts[:i])
                    if parent_name not in self.modules and parent_name != actual_module_name:
                        try:
                            parent_module = importlib.import_module(parent_name)
                            self.modules[parent_name] = parent_module
                            self.globals_dict[parent_name] = parent_module
                        except ImportError:
                            # Parent might be a class/attribute, not a module
                            pass

            return {
                'error': None,
                'result': module
            }

        except ImportError as e:
            return {
                'error': f'Import error: {str(e)}',
                'result': None
            }
        except Exception as e:
            return {
                'error': f'Import failed: {str(e)}',
                'result': None
            }

    async def handle_call(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Call a Python function"""
        try:
            function_name = args.get('function')
            func_args = self.normalize_args(args.get('args', []))
            func_kwargs = self.normalize_kwargs(args.get('kwargs', {}))

            if not function_name:
                return {
                    'error': 'Function name is required',
                    'result': None
                }

            logger.info(f"Calling function: {function_name}")

            # Resolve function from global namespace
            func = self.resolve_function(function_name)

            if func is None:
                return {
                    'error': f'Function {function_name} not found',
                    'result': None
                }

            # Process arguments
            processed_args = self.resolve_object_references(func_args)
            processed_kwargs = self.resolve_object_references(func_kwargs)

            # Call the function
            try:
                if asyncio.iscoroutinefunction(func):
                    result = await func(*processed_args, **processed_kwargs)
                else:
                    result = func(*processed_args, **processed_kwargs)

                return {
                    'error': None,
                    'result': result
                }

            except Exception as e:
                logger.error(f"Error calling function {function_name}: {str(e)}")
                return {
                    'error': f'Function call failed: {str(e)}',
                    'result': None
                }

        except Exception as e:
            logger.error(f"Error in handle_call: {str(e)}")
            return {
                'error': f'Function call failed: {str(e)}',
                'result': None
            }

    async def handle_eval(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Evaluate arbitrary Python code"""
        try:
            code = args.get('code')

            if not code:
                return {
                    'error': 'Code is required',
                    'result': None
                }

            logger.info(f"Evaluating code: {code[:50]}...")

            # Create a copy of globals for safety
            safe_globals = self.globals_dict.copy()
            safe_globals.update({
                '__builtins__': __builtins__,
                'print': print
            })

            # Evaluate the code
            result = eval(code, safe_globals)

            return {
                'error': None,
                'result': result
            }

        except Exception as e:
            return {
                'error': f'Code execution failed: {str(e)}',
                'result': None
            }

    async def handle_exec(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Execute arbitrary Python code"""
        try:
            code = args.get('code')

            if not code:
                return {
                    'error': 'Code is required',
                    'result': None
                }

            logger.info(f"Executing code: {code[:50]}...")

            # Create a copy of globals for safety
            safe_globals = self.globals_dict.copy()
            safe_globals.update({
                '__builtins__': __builtins__,
                'print': print
            })

            # Execute the code
            result = exec(code, safe_globals)

            return {
                'error': None,
                'result': result
            }

        except Exception as e:
            return {
                'error': f'Code execution failed: {str(e)}',
                'result': None
            }

    async def handle_shutdown(self) -> Dict[str, Any]:
        """Initiate server shutdown"""
        logger.info("Shutdown command received")
        asyncio.create_task(self.shutdown())
        return {
            'error': None,
            'result': 'Server shutting down'
        }

    async def shutdown(self):
        """Stop the server gracefully"""
        logger.info("Starting shutdown procedure...")

        # Close all connections
        if self.clients:
            logger.info(f"Closing {len(self.clients)} active connections")
            for client in self.clients.copy():
                try:
                    # Send shutdown notification
                    await client.send(json.dumps({
                        'error': None,
                        'result': 'Server shutting down',
                        'id': 'shutdown'
                    }))
                    await client.close()
                except Exception as e:
                    logger.error(f"An error occured while closing the server: {e}")

        # Stop the server
        if self.server:
            self.server.close()
            await self.server.wait_closed()

        logger.info("The server was successfully stopepd")

        # Clear object storage
        self.object_store.clear()
        self.object_refs.clear()

        sys.exit(0)

    def resolve_function(self, function_name: str):
        """Resolve a function name to a function object"""
        try:
            parts = function_name.split('.')
            obj = self.globals_dict.get(parts[0])

            if obj is None:
                # If not found in globals_dict — search in builtins
                import builtins
                obj = getattr(builtins, parts[0], None)
                if obj is None:
                    return None

            for part in parts[1:]:
                obj = getattr(obj, part)

            return obj

        except AttributeError:
            return None

    async def start_server(self):
        """Start the WebSocket server"""
        print("Modules available:", list(self.modules.keys()))

        self.server = await websockets.serve(
            self.handle_client,
            self.host,
            self.port,
            ssl=None,
            ping_interval=None,
            ping_timeout=3600,
            close_timeout=3600
        )

        await self.server.wait_closed()

def main():
    """Entry point"""
    parser = argparse.ArgumentParser(description='Python Bridge WebSocket Server')
    parser.add_argument('--host', default='127.0.0.1', help='Host to bind to')
    parser.add_argument('--port', type=int, default=8765, help='Port to bind to')
    parser.add_argument('--debug', action='store_true', help='Enable debug logging')
    parser.add_argument('--verbose', help='Enable logging')

    args = parser.parse_args()

    sys.argv = [sys.argv[0]] #Clear arguments to avoid conflicts in some packages

    if args.debug:
        logging.getLogger().setLevel(logging.DEBUG)

    server = PythonBridgeServer(args.host, args.port)

    try:
        asyncio.run(server.start_server())
    except KeyboardInterrupt:
        print("\nThe server was stopped by the user")
    except Exception as e:
        print("Server error")
        logging.exception(e)
        sys.exit(1)


if __name__ == '__main__':
    main()