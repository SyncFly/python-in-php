import sys
import types
import inspect
import pkgutil
import importlib
import importlib.machinery
import multiprocessing
import traceback
import json
import re
import warnings
from typing import Any, Dict, List, Optional, Set, Tuple
from concurrent.futures import ProcessPoolExecutor, as_completed, TimeoutError
from unittest.mock import MagicMock

GUI_MODULES_TO_MOCK = [
    'tkinter.ttk', 'PyQt5.QtWidgets', 'idlelib.pyshell',
    'PyQt5.QtCore', 'PyQt5.QtGui', 'PySide2.QtWidgets',
    'PySide2.QtCore', 'PySide2.QtGui', 'matplotlib.pyplot',
    """ 'turtle', 'pygame', 'PyQt5', 'tkinter', 'PySide2', 'wx', 'pyglet', 'idlelib', """
]

MODULES_TO_EXCLUDE = [
    r'\.tests\b',
    r'\.testing\b',
    r'\b_[a-zA-Z0-9_]*\b'
]

class ModuleMock(MagicMock):
    def __init__(self, name: str, **kwargs):
        super().__init__(name, **kwargs); self.__name__ = name; self.__file__ = f"__mock__/{name.replace('.', '/')}.py"; self.__path__ = [f"__mock__/{name.replace('.', '/')}",]; loader = importlib.machinery.SourceFileLoader(self.__name__, self.__file__); self.__spec__ = importlib.machinery.ModuleSpec(name=self.__name__, loader=loader, origin=self.__file__)

def _apply_gui_mocks():
    for module_name in GUI_MODULES_TO_MOCK: sys.modules[module_name] = ModuleMock(module_name)

def _is_module_excluded(module_name: str) -> bool:
    # Check whether any part of the path contains an initial underscore
    if any(part.startswith('_') and part != '__init__' for part in module_name.split('.')):
        return True
    for pattern in MODULES_TO_EXCLUDE:
        if re.search(pattern, module_name): return True
    return False

def _inspect_single_module_worker(module_name: str) -> Tuple[str, Dict[str, Any], List[str]]:
    if _is_module_excluded(module_name): return module_name, {"error": f"Module '{module_name}' excluded by pattern"}, []
    warnings.filterwarnings("ignore", category=UserWarning); warnings.filterwarnings("ignore", category=DeprecationWarning)
    _apply_gui_mocks()
    try:
        module = importlib.import_module(module_name); inspector = ModuleInspector(max_depth=0); inspection_data = inspector.analyze_module_content(module); submodule_names = []
        if hasattr(module, "__path__"):
            for module_info in pkgutil.walk_packages(module.__path__, module.__name__ + "."):
                if not _is_module_excluded(module_info.name): submodule_names.append(module_info.name)
        return module_name, inspection_data, submodule_names
    except SystemExit: return module_name, {"error": f"Module '{module_name}' triggered SystemExit."}, []
    except Exception: return module_name, {"error": f"Failed to inspect module '{module_name}'", "details": traceback.format_exc()}, []

def _run_isolated_inspection_session(module_name: str, max_depth: int, worker_timeout: int, session_timeout: int) -> Tuple[str, Optional[Dict[str, Any]]]:
    try:
        inspector = ModuleInspector(max_depth=max_depth, worker_timeout=worker_timeout, session_timeout=session_timeout)
        result = inspector.inspect_module(module_name)
        return module_name, result
    except Exception as e:
        return module_name, {"error": f"Isolated session for '{module_name}' crashed unexpectedly.", "details": str(e)}

class ModuleInspector:
    def __init__(self, max_depth: int = 3, worker_timeout: int = 60, session_timeout: int = 300):
        self.max_depth = max_depth
        self.worker_timeout = worker_timeout
        self.session_timeout = session_timeout # Timeout for the entire analysis session of a single root module

    def _inspect_core(self, root_modules: List[str]) -> Dict[str, Any]:
        with ProcessPoolExecutor() as executor:
            flat_results = {}; submitted_modules = set(root_modules); parent_map = {name: None for name in root_modules}
            futures = {executor.submit(_inspect_single_module_worker, name): name for name in root_modules}
            while futures:
                try:
                    for future in as_completed(futures, timeout=self.worker_timeout):
                        parent_module_name = futures.pop(future)
                        try:
                            _, inspection_data, discovered_submodules = future.result()
                            flat_results[parent_module_name] = inspection_data
                            root_name = next(r for r in root_modules if parent_module_name.startswith(r))
                            current_depth = parent_module_name.count('.') - root_name.count('.')
                            if current_depth < self.max_depth:
                                for sub_name in discovered_submodules:
                                    if sub_name not in submitted_modules:
                                        submitted_modules.add(sub_name); parent_map[sub_name] = parent_module_name
                                        new_future = executor.submit(_inspect_single_module_worker, sub_name)
                                        futures[new_future] = sub_name
                        except Exception as exc:
                            flat_results[parent_module_name] = {"error": f"Future result retrieval failed: {exc}"}
                except TimeoutError:
                    stuck_modules = list(futures.values())
                    sys.stderr.write(f"\nWARNING: Worker timeout reached in batch. Stuck modules: {stuck_modules}\n")
                    for future, name in futures.items():
                        future.cancel()
                        if name not in flat_results:
                            flat_results[name] = {"error": "Analysis timed out (worker crashed)."}
                    break
        if not flat_results: return {}
        for res in flat_results.values():
            if "error" not in res: res["submodules"] = {}
        for name, data in flat_results.items():
            parent = parent_map.get(name)
            if parent and parent in flat_results and "error" not in flat_results[parent]:
                flat_results[parent]["submodules"][name] = data
        return {name: flat_results.get(name) for name in root_modules}

    def inspect_module(self, module_name: str) -> Optional[Dict[str, Any]]:
        results = self._inspect_core([module_name])
        return results.get(module_name)

    def inspect_modules_parallel_isolated(self, module_names: List[str]) -> Dict[str, Optional[Dict[str, Any]]]:
        final_results = {}
        with ProcessPoolExecutor(max_workers=multiprocessing.cpu_count()) as executor:
            future_to_module = {
                executor.submit(_run_isolated_inspection_session, name, self.max_depth, self.worker_timeout, self.session_timeout): name
                for name in module_names
            }
            for future in as_completed(future_to_module):
                module_name = future_to_module[future]
                try:
                    _, result = future.result(timeout=self.session_timeout)
                    final_results[module_name] = result
                except TimeoutError:
                    # This error will occur if the session freezes internally (during shutdown)
                    final_results[module_name] = {"error": f"Session for '{module_name}' timed out and was terminated."}
                except Exception as exc:
                    final_results[module_name] = {"error": f"Top-level session manager for '{module_name}' crashed.", "details": str(exc)}
        return final_results

    def analyze_module_content(self, module_obj: types.ModuleType) -> Dict[str, Any]:
        module_info = {"functions": {}, "classes": {}, "attributes": {}}
        for name in dir(module_obj):
            if name.startswith("__") and name.endswith("__"): continue
            try:
                attr = getattr(module_obj, name)
                if isinstance(attr, types.ModuleType): continue
                elif isinstance(attr, type): module_info["classes"][name] = self.analyze_class(attr)
                elif callable(attr): module_info["functions"][name] = self.analyze_method_signature(attr)
                else: module_info["attributes"][name] = {"name": name, "type": self.get_attribute_type(module_obj, name)}
            except Exception: continue
        return module_info
    @staticmethod
    def get_type_name(type_hint) -> str:
        if type_hint is None: return "None"
        if hasattr(type_hint, '__name__'): return type_hint.__name__
        if hasattr(type_hint, '__origin__'):
            origin = type_hint.__origin__; origin_name = getattr(origin, '__name__', str(origin))
            if hasattr(type_hint, '__args__') and type_hint.__args__:
                args = [ModuleInspector.get_type_name(arg) for arg in type_hint.__args__]; return f"{origin_name}[{', '.join(args)}]"
            return origin_name
        return str(type_hint)
    def analyze_method_signature(self, method) -> Dict[str, Any]:
        try:
            sig = inspect.signature(method)
            params = [{"name": name, "type": self.get_type_name(p.annotation) if p.annotation != p.empty else "Any", "default": repr(p.default) if p.default != p.empty else None} for name, p in sig.parameters.items()]
            ret_type = self.get_type_name(sig.return_annotation) if sig.return_annotation != sig.empty else "None"
            return {"parameters": params, "return_type": ret_type}
        except Exception: return {"parameters": [], "return_type": None}
    def get_attribute_type(self, obj, attr_name: str) -> Optional[str]:
        try:
            if hasattr(obj, '__annotations__') and attr_name in obj.__annotations__: return self.get_type_name(obj.__annotations__[attr_name])
            return type(getattr(obj, attr_name)).__name__
        except Exception: return None
    def classify_method(self, cls, method_name: str) -> str:
        try:
            if isinstance(inspect.getattr_static(cls, method_name), staticmethod): return "static"
            if isinstance(inspect.getattr_static(cls, method_name), classmethod): return "class"
        except Exception: pass
        return "instance"
    def analyze_class(self, cls) -> Dict[str, Any]:
        class_info = {"class_attributes": {}, "properties": {}, "instance_methods": {}, "class_methods": {}, "static_methods": {}}
        try: from typing import get_type_hints; type_hints = get_type_hints(cls)
        except Exception: type_hints = {}
        for name in dir(cls):
            if name.startswith("__") and name.endswith("__"): continue
            try:
                attr_obj = getattr(cls, name)
                if isinstance(inspect.getattr_static(cls, name), property):
                    prop_info = {"type": self.get_attribute_type(cls, name)}; fget = inspect.getattr_static(cls, name).fget
                    if fget: prop_info["return_type"] = self.analyze_method_signature(fget)["return_type"]
                    class_info["properties"][name] = prop_info
                elif callable(attr_obj):
                    method_type = self.classify_method(cls, name); class_info[f"{method_type}_methods"][name] = self.analyze_method_signature(attr_obj)
                else:
                    attr_type = self.get_type_name(type_hints.get(name)) or self.get_attribute_type(cls, name)
                    if name in cls.__dict__: class_info["class_attributes"][name] = {"type": attr_type}
            except Exception: continue
        return class_info

if __name__ == '__main__':
    if sys.platform != "win32":
        try: multiprocessing.set_start_method('fork', force=True)
        except (ValueError, RuntimeError): pass

    inspector = ModuleInspector(max_depth=3, worker_timeout=60, session_timeout=300)

    modules_to_inspect = ['TTS', 'json', 'logging', 'numpy', 'non_existent_module']
    print(f"--- Starting PARALLEL & ISOLATED analysis of: {modules_to_inspect} ---")
    results = inspector.inspect_modules_parallel_isolated(modules_to_inspect)

    print("\n--- Analysis Complete ---")
    for name, structure in results.items():
        if structure and "error" not in structure:
            submodule_count = len(structure.get("submodules", {}))
            print(f"Module '{name}': SUCCESS (found {submodule_count} submodules)")
        elif structure:
            error_msg = structure.get('error', 'Unknown error')
            details_msg = structure.get('details', '')
            print(f"Module '{name}': FAILED with error: {error_msg}, {details_msg}")
        else:
            print(f"Module '{name}': FAILED (No structure returned, session likely crashed)")

    if 'numpy' in results and results['numpy'] and "error" not in results['numpy']:
        print("\n--- Structure of 'numpy' (unaffected by other failures) ---")
        print("Successfully retrieved structure for numpy.")