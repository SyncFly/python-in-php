import os
import sys
import time
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
from concurrent.futures import ProcessPoolExecutor, as_completed, wait, FIRST_COMPLETED, TimeoutError
from unittest.mock import MagicMock

# Use "spawn" for our pools: the default "fork" would fork the asyncio worker that
# imports us and deadlock on its inherited threads/locks (notably in CI).
try:
    _MP_CONTEXT = multiprocessing.get_context('spawn')
except ValueError:
    _MP_CONTEXT = multiprocessing.get_context()


def _available_memory_gb() -> Optional[float]:
    # Best-effort free memory, so we don't OOM a weak box by importing a heavy stack
    # (torch et al.) in many workers at once. Prefers MemAvailable, which already
    # accounts for RAM the surrounding app is holding. Returns None if unknown
    # (e.g. Windows), in which case only the CPU cap applies.
    try:
        with open('/proc/meminfo') as f:
            info = {}
            for line in f:
                key, _, rest = line.partition(':')
                info[key.strip()] = rest.strip()
        for key in ('MemAvailable', 'MemTotal'):
            if key in info:
                return int(info[key].split()[0]) / (1024 * 1024)
    except Exception:
        pass
    try:
        return (os.sysconf('SC_PAGE_SIZE') * os.sysconf('SC_AVPHYS_PAGES')) / (1024 ** 3)
    except Exception:
        return None


def _default_max_workers(cpu_cap: int = 8, mem_per_worker_gb: float = 1.5) -> int:
    workers = min(multiprocessing.cpu_count(), cpu_cap)
    avail = _available_memory_gb()
    if avail is not None:
        workers = min(workers, int(avail // mem_per_worker_gb))
    return max(1, workers)

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

# Known-broken imports: setup-time machinery, deprecated shims, wrong-OS codecs, import side effects.
KNOWN_BROKEN_MODULES = [
    'setuptools.command',
    'setuptools.installer',
    'setuptools.wheel',
    'setuptools.sandbox',
    'setuptools.msvc',
    'pkg_resources.extern',
    'encodings.mbcs',
    'encodings.oem',
    'encodings.cp65001',
    'multiprocessing.popen_spawn_win32',
    'asyncio.windows_events',
    'asyncio.windows_utils',
    'distutils',
    'lib2to3',
    'turtledemo',
    'idlelib',
    'antigravity',
    'this',
]

class ModuleMock(MagicMock):
    def __init__(self, name: str, **kwargs):
        # name must go as a keyword: the first positional Mock argument is spec, which would restrict attributes and break imports like `matplotlib.pyplot.Figure`.
        super().__init__(name=name, **kwargs); self.__name__ = name; self.__file__ = f"__mock__/{name.replace('.', '/')}.py"; self.__path__ = [f"__mock__/{name.replace('.', '/')}",]; loader = importlib.machinery.SourceFileLoader(self.__name__, self.__file__); self.__spec__ = importlib.machinery.ModuleSpec(name=self.__name__, loader=loader, origin=self.__file__)

def _apply_gui_mocks():
    for module_name in GUI_MODULES_TO_MOCK: sys.modules[module_name] = ModuleMock(module_name)

def _is_module_excluded(module_name: str) -> bool:
    # Check whether any part of the path contains an initial underscore
    if any(part.startswith('_') and part != '__init__' for part in module_name.split('.')):
        return True
    # Dot-aware prefix match so 'this' doesn't swallow e.g. 'thing'.
    for known in KNOWN_BROKEN_MODULES:
        if module_name == known or module_name.startswith(known + '.'): return True
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
            # Without onerror, walk_packages re-raises any non-ImportError from a
            # sub-package's import, which would discard this module's whole inspection.
            # Swallow per-subpackage failures so one broken part doesn't cancel the module.
            def _on_walk_error(failed_name: str) -> None:
                sys.stderr.write(f"WARNING: skipping subpackage '{failed_name}': {sys.exc_info()[1]}\n")
            for module_info in pkgutil.walk_packages(module.__path__, module.__name__ + ".", onerror=_on_walk_error):
                if not _is_module_excluded(module_info.name): submodule_names.append(module_info.name)
        return module_name, inspection_data, submodule_names
    except SystemExit: return module_name, {"error": f"Module '{module_name}' triggered SystemExit."}, []
    except Exception as e: return module_name, {"error": f"{type(e).__name__}: {e}", "details": traceback.format_exc()}, []

def _run_isolated_inspection_session(module_name: str, max_depth: int, worker_timeout: int, session_timeout: int, max_workers: int) -> Tuple[str, Optional[Dict[str, Any]]]:
    try:
        inspector = ModuleInspector(max_depth=max_depth, worker_timeout=worker_timeout, session_timeout=session_timeout, max_workers=max_workers)
        result = inspector.inspect_module(module_name)
        return module_name, result
    except Exception as e:
        return module_name, {"error": f"Isolated session for '{module_name}' crashed unexpectedly.", "details": str(e)}

class ModuleInspector:
    def __init__(self, max_depth: int = 3, worker_timeout: int = 180, session_timeout: int = 1200, max_workers: Optional[int] = None):
        self.max_depth = max_depth
        # worker_timeout is a *no-progress* timeout: we abort only if nothing at all
        # completes for this long. A slow-but-progressing (weak) machine keeps going;
        # only a genuinely hung import trips it.
        self.worker_timeout = worker_timeout
        # Absolute ceiling for analysing a single root module, so a huge package can't
        # run forever even while making steady progress.
        self.session_timeout = session_timeout
        # Cap the inner pool by both CPU and free RAM so a single root module can't
        # spawn cpu_count workers (or, with the outer pool, cpu_count**2 processes),
        # each re-importing a heavy stack and OOM-ing a weak machine.
        self.max_workers = max_workers or _default_max_workers()

    def _inspect_core(self, root_modules: List[str]) -> Dict[str, Any]:
        session_deadline = time.monotonic() + self.session_timeout
        with ProcessPoolExecutor(max_workers=self.max_workers, mp_context=_MP_CONTEXT) as executor:
            flat_results = {}; submitted_modules = set(root_modules); parent_map = {name: None for name in root_modules}
            futures = {executor.submit(_inspect_single_module_worker, name): name for name in root_modules}
            while futures:
                # Stop if the whole-session budget is exhausted, regardless of progress.
                budget_left = session_deadline - time.monotonic()
                if budget_left <= 0:
                    for future, name in futures.items():
                        future.cancel()
                        if name not in flat_results:
                            flat_results[name] = {"error": f"Analysis exceeded session budget of {self.session_timeout}s."}
                    break

                # Wait for *any* worker to finish. worker_timeout here is a no-progress
                # window: a slow machine that keeps completing modules never trips it.
                done, _ = wait(list(futures), timeout=min(self.worker_timeout, budget_left), return_when=FIRST_COMPLETED)
                if not done:
                    stuck_modules = list(futures.values())
                    sys.stderr.write(f"\nWARNING: no inspection progress for {self.worker_timeout}s. Stuck modules: {stuck_modules}\n")
                    for future, name in futures.items():
                        future.cancel()
                        if name not in flat_results:
                            flat_results[name] = {"error": f"Analysis stalled (no progress for {self.worker_timeout}s)."}
                    break

                for future in done:
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
        # self.max_workers is the *total* concurrent heavy-import budget. Split it
        # between the outer pool (one process per root module) and each root's inner
        # submodule pool so the product stays within budget and a weak machine isn't
        # swamped by cpu_count**2 processes each importing torch.
        outer_workers = max(1, min(self.max_workers, len(module_names)))
        per_session_workers = max(1, self.max_workers // outer_workers)
        with ProcessPoolExecutor(max_workers=outer_workers, mp_context=_MP_CONTEXT) as executor:
            future_to_module = {
                executor.submit(_run_isolated_inspection_session, name, self.max_depth, self.worker_timeout, self.session_timeout, per_session_workers): name
                for name in module_names
            }
            # as_completed() without a timeout blocks forever if a worker hangs; cap the batch.
            batch_timeout = self.session_timeout * 2
            try:
                for future in as_completed(future_to_module, timeout=batch_timeout):
                    module_name = future_to_module[future]
                    try:
                        # Small buffer over session_timeout so the inner run can hit its
                        # own budget and return partial results before we give up on it.
                        _, result = future.result(timeout=self.session_timeout + 30)
                        final_results[module_name] = result
                    except TimeoutError:
                        final_results[module_name] = {"error": f"Session for '{module_name}' timed out and was terminated."}
                    except Exception as exc:
                        final_results[module_name] = {"error": f"Top-level session manager for '{module_name}' crashed.", "details": str(exc)}
            except TimeoutError:
                for future, module_name in future_to_module.items():
                    if module_name not in final_results:
                        future.cancel()
                        final_results[module_name] = {"error": f"Inspection of '{module_name}' timed out (batch deadline reached)."}
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
        if hasattr(type_hint, '__name__'):
            name = type_hint.__name__
            module = getattr(type_hint, '__module__', None)
            # Qualify real classes with their module (e.g. requests.models.Response)
            # so the PHP generator can emit a resolvable \py\<module>\<Class> path.
            # Builtins (int/str/...) and typing constructs stay bare so they map to
            # PHP scalars / are handled generically.
            if module and module not in ('builtins', 'typing'):
                return f"{module}.{name}"
            return name
        if hasattr(type_hint, '__origin__'):
            origin = type_hint.__origin__; origin_name = getattr(origin, '__name__', str(origin))
            if hasattr(type_hint, '__args__') and type_hint.__args__:
                args = [ModuleInspector.get_type_name(arg) for arg in type_hint.__args__]; return f"{origin_name}[{', '.join(args)}]"
            return origin_name
        return str(type_hint)
    @staticmethod
    def _param_name(name: str, kind) -> str:
        # inspect.signature() drops the leading asterisks; re-add them so the PHP
        # generator can render *args / **kwargs as variadics.
        if kind == inspect.Parameter.VAR_POSITIONAL:
            return '*' + name
        if kind == inspect.Parameter.VAR_KEYWORD:
            return '**' + name
        return name
    def analyze_method_signature(self, method) -> Dict[str, Any]:
        try:
            sig = inspect.signature(method)
            params = [{"name": self._param_name(name, p.kind), "type": self.get_type_name(p.annotation) if p.annotation != p.empty else "Any", "default": repr(p.default) if p.default != p.empty else None} for name, p in sig.parameters.items()]
            # No annotation means unknown (=> mixed), not None; only explicit -> None is void.
            ret_type = self.get_type_name(sig.return_annotation) if sig.return_annotation != sig.empty else "Any"
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