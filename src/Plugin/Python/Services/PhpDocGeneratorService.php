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
    private ?PythonBridge $bridge;
    private PythonObject $sys;
    private PythonObject $pkgutil;

    private string $namespace = 'py';
    private array $excluded_modules = [
        'idlelib',
        'antigravity'
    ];

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

        $this->output->displayMessage("Generating PHP Docs for installed packages ", false);

        if ($delete_old_docs) $this->deleteForModules($modules);
        $structures = $this->bridge->inspectModules($modules);
        $php_docs = $this->generateForModules($structures);
        $this->writeFiles($php_docs);

        $this->output->displayMessage("- Finished ✅", true, false);
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

    private function generateForModules(array $structures): \Generator
    {
        foreach ($structures as $module_name => $module_structure) {
            $this->output->verboseMessage("Generating docs for module $module_name");
            if ($this->isExcludedModule($module_name)) continue;
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
                $php_class->addComment("@method static {$function['return_type']} $function_name()");
            }
        }

        if (!empty($entity['instance_methods'])) {
            foreach ($entity['instance_methods'] as $method_name => $method) {
                $php_class->addComment("@method {$method['return_type']} {$method_name}()");
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

    private function convertType($type)
    {
        $converts = [
            'int' => 'int',
            'str' => 'string',
            'bool' => 'bool',
            'list' => 'array',
            'dict' => 'array',
            'tuple' => 'array',
            'NoneType' => 'null'
        ];

        if (isset($converts[$type])) {
            return $converts[$type];
        }
        else return "";
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
        if (!empty($packages)) {
            $package_names = array_map(fn($package) => $package->name, $packages);
            $modules = [...$modules, ...$this->bridge->getModuleNamesInPackages($package_names)];
        }
        if ($include_builtin_modules) {
            $modules = [...$modules, ...$this->sys->stdlib_module_names];
        }
        return $modules;
    }
}