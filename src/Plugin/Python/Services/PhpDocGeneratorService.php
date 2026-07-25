<?php

namespace Python_In_PHP\Plugin\Python\Services;

use Nette\PhpGenerator\Helpers;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PhpNamespace;
use Nette\PhpGenerator\PsrPrinter;
use PhpParser\Node\Scalar\MagicConst\Dir;
use Python_In_PHP\Plugin\OutputService;
use Python_In_PHP\Plugin\Utils;
use Python_In_PHP\PythonBridge;
use Python_In_PHP\PythonObject;
use Python_In_PHP\Plugin\Python\Entities\Package;

class PhpDocGeneratorService
{
    // Modules inspected per round-trip. Large enough to keep the Python-side worker
    // pool saturated, small enough that the progress counter advances visibly.
    private const PROGRESS_CHUNK_SIZE = 24;

    private ?PythonBridge $bridge;
    private PythonObject $sys;
    private PythonObject $pkgutil;

    private string $namespace = 'py';
    private array $excluded_modules = [
        'idlelib',
        'antigravity'
    ];

    private array $importErrors = [];

    function __construct(
        private string $dir,
        public OutputService $output
    ){
    }

    public function preparePython(): void
    {
        if (!isset($this->bridge)) {
            $this->bridge = PythonBridge::startOrGetRunning([
                'debug' => $this->output->isDebug()
            ]);
            $this->sys = $this->bridge->importModule('sys');
            $this->bridge->importModule('importlib');
        }
    }

    /**
     * @param Package[] $packages
     * @param bool      $include_builtin_modules
     * @return void
     */
    public function refreshPhpDocs(array $packages, bool $include_builtin_modules = false, bool $delete_old_docs = true): void
    {
        $this->preparePython();

        $modules = $this->getModuleNamesByPackages($packages, $include_builtin_modules);
        $modules = $this->removeExcludedModules($modules);

        if (empty($modules)) return;

        // Terminate this line with a newline so it flushes to the console *before* the
        // long blocking inspection below. Without the newline the message sits in an
        // unterminated line and only appears (glued to "Finished") once generation ends,
        // which looks like a silent hang on a non-TTY (e.g. captured by the test runner).
        $this->output->displayMessage("Generating PHP Docs for installed packages...", 1);

        $this->importErrors = [];
        if ($delete_old_docs) $this->deleteForModules($modules);

        // Inspect + generate in chunks rather than one giant blocking call, so we can
        // report progress. Inspection (the Python round-trip) is where the time goes;
        // chunking is the only way to surface a moving counter during that wait.
        $total = count($modules);
        $done = 0;
        foreach (array_chunk($modules, self::PROGRESS_CHUNK_SIZE) as $chunk) {
            $structures = $this->bridge->inspectModules($chunk);
            $this->writeFiles($this->generateForModules($structures));
            $done += count($chunk);
            $this->output->displayMessage(sprintf("  [%d/%d] modules processed", $done, $total), 1);
        }

        $this->output->displayMessage("  ✅ Done");

        $this->reportImportErrors();
    }

    /**
     * -v shows every import error in full; otherwise expected noise (missing modules,
     * platform-only stubs) is dropped and real failures are listed compactly by name.
     */
    private function reportImportErrors(): void
    {
        if (empty($this->importErrors)) return;

        if ($this->output->isVerbose()) {
            foreach ($this->importErrors as $module => $error) {
                $this->output->displayMessage("  ⚠️  $module: $error");
            }
            return;
        }

        $real_errors = array_filter($this->importErrors, fn($error) => !$this->isIgnorableImportError($error));
        if (empty($real_errors)) return;

        $modules = implode(', ', array_keys($real_errors));
        $this->output->displayMessage("  ⚠️  Could not import: $modules", 1);
        $this->output->displayMessage("  (run with -v for details)");
    }

    /** Expected, non-actionable import failures: a module that isn't installed or a wrong-OS stub. */
    private function isIgnorableImportError(string $error): bool
    {
        return str_contains($error, 'ModuleNotFoundError: No module named')
            || str_contains($error, 'ImportError: win32 only');
    }

    public function refreshPhpDocsForAllModules(): void
    {
        $this->preparePython();

        $pkgutil = $this->bridge->importModule('pkgutil');
        $modules = $this->sys->builtin_module_names + array_map(fn($x) => $x[1], iterator_to_array($pkgutil->iter_modules()));
        $modules = $this->removeExcludedModules($modules);
        if (empty($modules)) return;

        $structures = $this->bridge->inspectModules($modules);
        $php_docs = $this->generateForModules($structures);
        $this->writeFiles($php_docs);
    }

    /**
     * @param Package[] $packages
     * @param bool      $include_builtin_modules
     * @return void
     */
    public function deletePhpDocs(array $packages, bool $include_builtin_modules = false): void
    {
        $this->preparePython();

        $modules = $this->getModuleNamesByPackages($packages, $include_builtin_modules);
        if (empty($modules)) return;

        $this->deleteForModules($modules);
    }

    private function writeFiles(iterable $php_docs): void
    {
        foreach ($php_docs as $path => $content) {
            $path = str_replace('\\', DIRECTORY_SEPARATOR, $path);
            $path = $this->dir . DIRECTORY_SEPARATOR . $path;
            $dir = dirname($path);
            if (!is_dir($dir)) mkdir($dir, recursive: true);
            file_put_contents($path, $content);
        }
    }

    private function generateForModules(iterable $structures): \Generator
    {
        foreach ($structures as $module_name => $module_structure) {
            $this->output->verboseMessage("Generating docs for module $module_name");
            if ($this->isExcludedModule($module_name)) continue;

            if (is_array($module_structure) && isset($module_structure['error'])) {
                $this->importErrors[$module_name] = $module_structure['error'];
                continue;
            }

            try {
                yield from $this->generateForModule($module_name, $module_structure);
            }
            catch (\Exception $e) {
                $this->output->verboseMessage("Error while generating PHP Docs for module $module_name: $e");
            }
        }
    }

    private function generateForModule(string $name, ?array $structure = null): \Generator
    {
        if ($this->isExcludedModule($name)) return;

        $namespace = $this->namespace;
        if (str_contains($name, '.')) {
            $name_path = explode('.', $name);
            $name = array_pop($name_path);
            $namespace = implode('\\', [$namespace, ...$name_path]);
        }

        yield from $this->processEntity($structure, $name, $namespace);
    }

    private function processEntity(array $entity, string $name, ?string $namespace = null, $is_class = false): \Generator
    {
        if (!Helpers::isIdentifier($name) || isset(Helpers::Keywords[strtolower($name)])) {
            $name = '_' . $name;
        }

        $php_file = new PhpFile();
        $php_namespace = $php_file->addNamespace(new PhpNamespace($namespace));
        $php_class = $php_namespace->addClass($name);
        $php_class->setExtends('\Python_In_PHP\PythonClass');
        $php_init_code = "$name::init();";

        if (!empty($entity['functions'])) {
            foreach ($entity['functions'] as $function_name => $function) {
                $returnType = $this->convertReturnType($function['return_type'] ?? '');
                $params = $this->buildParamString($function['parameters'] ?? []);
                $php_class->addComment("@method static {$returnType} {$function_name}({$params})");
            }
        }

        if (!empty($entity['instance_methods'])) {
            foreach ($entity['instance_methods'] as $method_name => $method) {
                $returnType = $this->convertReturnType($method['return_type'] ?? '');
                $params = $this->buildParamString($method['parameters'] ?? []);
                $php_class->addComment("@method {$returnType} {$method_name}({$params})");
            }
        }

        if (!empty($entity['static_methods'])) {
            foreach ($entity['static_methods'] as $method_name => $method) {
                $returnType = $this->convertReturnType($method['return_type'] ?? '');
                $params = $this->buildParamString($method['parameters'] ?? []);
                $php_class->addComment("@method static {$returnType} {$method_name}({$params})");
            }
        }

        if (!empty($entity['class_methods'])) {
            foreach ($entity['class_methods'] as $method_name => $method) {
                $returnType = $this->convertReturnType($method['return_type'] ?? '');
                $params = $this->buildParamString($method['parameters'] ?? []);
                $php_class->addComment("@method static {$returnType} {$method_name}({$params})");
            }
        }

        if (!empty($entity['class_attributes'])) {
            foreach ($entity['class_attributes'] as $attribute_name => $attribute) {
                $php_class->addComment("@property {$attribute['type']} $$attribute_name");
                $php_class->addComment("@property static {$attribute['type']} $$attribute_name");
            }
        }

        if (!empty($entity['instance_attributes'])) {
            foreach ($entity['instance_attributes'] as $attribute_name => $attribute) {
                $php_class->addComment("@property {$attribute['type']} $$attribute_name");
            }
        }

        if (!empty($entity['attributes'])) {
            foreach ($entity['attributes'] as $property) {
                $property_name = $property['name'];
                $property_type = $this->convertType($property['type']);
                $php_class->addProperty($property_name)->setStatic()->addComment("@var $property_type");
            }
        }

        $printer = new PsrPrinter();
        $content = $printer->printFile($php_file) . PHP_EOL . $php_init_code;
        $path = $namespace . DIRECTORY_SEPARATOR . $name . '.php';
        yield $path => $content;

        if (!empty($entity['classes'])) {
            foreach ($entity['classes'] as $class_name => $class_entities) {
                try {
                    yield from $this->processEntity($class_entities, $class_name, implode('\\', [$namespace, $name]), true);
                }
                catch (\Throwable $e) {
                    echo $e . "\n";
                }
            }
        }

        if (!empty($entity['submodules'])) {
            yield from $this->generateForModules($entity['submodules']);
        }
    }

    private function convertType(string $type): string
    {
        $converts = [
            'int'      => 'int',
            'str'      => 'string',
            'bool'     => 'bool',
            'float'    => 'float',
            'bytes'    => 'string',
            'list'     => 'array',
            'dict'     => 'array',
            'tuple'    => 'array',
            'NoneType' => 'null',
            'None'     => 'null',
            'Any'      => 'mixed',
        ];

        return $converts[$type] ?? '';
    }

    private function convertReturnType(string $type): string
    {
        if ($type === '' || $type === 'Any') {
            return 'mixed';
        }
        if ($type === 'None' || $type === 'NoneType') {
            return 'void';
        }

        $converted = $this->convertType($type);
        if ($converted !== '') {
            return $converted;
        }

        // Generic containers (list[int], dict[str, int], tuple[...]) map to array.
        $base = strtolower(explode('[', $type, 2)[0]);
        if (in_array($base, ['list', 'dict', 'tuple', 'set', 'frozenset', 'sequence', 'mapping', 'iterable'], true)) {
            return 'array';
        }

        // Parameterised / union types we can't map to a single class (Optional[int],
        // Union[...], etc.) — stay permissive.
        if (str_contains($type, '[')) {
            return 'mixed';
        }

        // A module-qualified class (requests.models.Response) points at the generated
        // wrapper class \py\requests\models\Response, which shares the Python name.
        if (str_contains($type, '.')) {
            return $this->qualifyClass($type);
        }

        // Bare unknown class with no locatable module (e.g. a builtins object): it's
        // wrapped as a PythonObject at runtime, and that type always resolves.
        return '\\' . PythonObject::class;
    }

    /**
     * Turn a module-qualified Python class name (e.g. "requests.models.Response")
     * into the fully-qualified name of its generated PHP wrapper class
     * (e.g. "\py\requests\models\Response").
     */
    private function qualifyClass(string $type): string
    {
        $parts = explode('.', $type);
        $class = array_pop($parts);

        // Match the sanitisation processEntity() applies to generated class names.
        if (!Helpers::isIdentifier($class) || isset(Helpers::Keywords[strtolower($class)])) {
            $class = '_' . $class;
        }

        return '\\' . implode('\\', [$this->namespace, ...$parts, $class]);
    }

    private function buildParamString(array $params): string
    {
        $parts = [];
        // PHP forbids a required parameter after an optional one. Python has no such
        // rule (keyword-only args can mix required/optional freely), so once we emit
        // an optional param we must keep every following one optional too.
        $optionalStarted = false;
        foreach ($params as $param) {
            $name = $param['name'] ?? '';
            if ($name === 'self' || $name === 'cls') {
                continue;
            }

            // *args and **kwargs both become a variadic so callers can pass extra
            // positional or named arguments (e.g. requests::get($url, timeout: 10)).
            $isVariadic = str_starts_with($name, '*');
            $varName = '$' . ltrim($name, '*');
            $type = $this->convertType($param['type'] ?? '') ?: 'mixed';
            $spread = $isVariadic ? '...' : '';

            // A variadic never takes a default and doesn't make later params optional.
            $default = '';
            if (!$isVariadic) {
                $phpDefault = $this->convertDefault($param['default'] ?? null);
                if ($phpDefault !== null) {
                    $default = " = {$phpDefault}";
                    $optionalStarted = true;
                } elseif ($optionalStarted) {
                    // Forced optional purely to keep valid ordering after a preceding
                    // optional param; null is the only safe stand-in default.
                    $default = ' = null';
                }
            }

            $parts[] = "{$type} {$spread}{$varName}{$default}";
        }
        return implode(', ', $parts);
    }

    /**
     * Convert a Python default (an `repr()` string from the inspector) into a PHP
     * literal usable in an `@method` signature. Returns null when the parameter has
     * no default at all; falls back to `null` for values PHP can't represent.
     */
    private function convertDefault(?string $repr): ?string
    {
        if ($repr === null) {
            return null;
        }

        return match ($repr) {
            'None'  => 'null',
            'True'  => 'true',
            'False' => 'false',
            default => match (true) {
                // Integer / float literals are valid PHP as-is.
                (bool) preg_match('/^-?\d+(\.\d+)?([eE][+-]?\d+)?$/', $repr) => $repr,
                // Single- or double-quoted string reprs are valid PHP string literals.
                (bool) preg_match("/^'[^'\\\\]*'$/", $repr),
                (bool) preg_match('/^"[^"\\\\]*"$/', $repr) => $repr,
                // Anything else (objects, tuples, etc.) can't be rendered; keep the
                // param optional with a safe placeholder.
                default => 'null',
            },
        };
    }

    private function removeExcludedModules(array $modules): array
    {
        $modules = array_values(array_filter($modules, fn($module) => !$this->isExcludedModule($module)));
        return $modules;
    }

    private function isExcludedModule(string $module_name): bool
    {
        $is_private_name = str_starts_with($module_name, '_');
        $is_excluded = in_array($module_name, $this->excluded_modules);
        $is_excluded_by_parent = !empty(array_filter($this->excluded_modules, fn($excluded) => str_starts_with($module_name, $excluded)));

        return $is_private_name || $is_excluded || $is_excluded_by_parent;
    }

    private function deleteForModules(array $modules)
    {
        foreach ($modules as $module) {
            Utils::deleteFolder($this->dir . DIRECTORY_SEPARATOR . $this->namespace . DIRECTORY_SEPARATOR . $module);
            Utils::deleteFile($this->dir . DIRECTORY_SEPARATOR . $this->namespace . DIRECTORY_SEPARATOR . $module . '.php');
        }
    }

    private function getModuleNamesByPackages(array $packages, bool $include_builtin_modules): array
    {
        $modules = [];
        // Path-only packages carry no distribution name, so they can't be mapped to
        // their modules here; skip them (they contribute no name to look up).
        $package_names = array_values(array_filter(
            array_map(fn($package) => $package->name, $packages),
            fn($name) => $name !== null
        ));
        if (!empty($package_names)) {
            $modules = [...$modules, ...$this->bridge->getModuleNamesInPackages($package_names)];
        }
        if ($include_builtin_modules) {
            $modules = [...$modules, ...$this->sys->stdlib_module_names];
        }
        return $modules;
    }
}
