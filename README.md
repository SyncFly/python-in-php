
> ⏳ The project is currently under active development. It is still in the beta stage, but it is already working.
> <br/>⭐️ Star the repository to support the project and follow us

<table>
<tr>
<td valign="top">

#### Python-in-PHP

The **Python-in-PHP** library allows you to easily use any Python packages as if they were native PHP packages 🐘

🔥 Fully use artificial intelligence frameworks for AI models inference or training directly in PHP!
<br/>
You can run AI models with libraries like `transformers`, `torch`, `vllm`, `numpy`, etc. in your PHP project with PHP syntax.
</td>
<td style="max-width: 30%" valign="center">
<img src="image.png" alt="Python-in-PHP" width="200"/>
</td>
</tr>
</table>

✅ Environment with Python is installed automatically with Composer.

✅ Any Python packages are installed via Composer with a built-in package manager.

✅ Automatic PHPDoc generation for code completion in IDEs for any Python packages.


## System requirements

| Requirement | Version |
|-------------|---------|
| PHP | ≥ 8.2 |
| OS | Linux, macOS, Windows |
| Architecture | x86_64, arm64 |

The library automatically downloads and installs the [`uv`](https://docs.astral.sh/uv/) tool and a Python environment on first use. No manual Python installation is required.

> **Note for Windows users:** symlink creation may require Administrator privileges or Developer Mode enabled.

## Installation

```bash
composer require syncfly/python-in-php
```

Answer **yes** when Composer asks to activate the plugin. This will:
1. Download `uv` (~10 MB) into `vendor/bin/`
2. Create a Python virtual environment in `vendor/bin/python-in-php/`
3. Generate PHPDoc stubs for installed packages in `py/`

## Quick start

After installation, Python's standard library is available immediately:

```php
<?php

use py\json;
use py\datetime\datetime;

echo json::dumps(['hello' => 'world']); // {"hello": "world"}
echo datetime::now()->isoformat();       // 2024-01-15T12:34:56.789012
```

Install additional packages, then use them:

```bash
composer pip install numpy
```

```php
<?php

use py\numpy;

$arr = numpy::array([1, 2, 3, 4, 5]);
echo numpy::mean($arr); // 3.0
```

For a complete Python → PHP syntax reference (kwargs, iteration, dicts, context managers, exceptions, and more) see **[docs/usage.md](./docs/usage.md)**.

## Package manager

The built-in package manager wraps `uv pip` with Composer integration.

```bash
# Install a package
composer pip install requests

# Install a specific version
composer pip install "numpy:^1.24"

# Install with a custom PyPI index
# (for PyTorch the right GPU index is normally picked automatically — see below)
composer pip install torch --index-url https://download.pytorch.org/whl/rocm6.3

# Install a local package from a directory
composer pip install /path/to/my-local-package

# Uninstall a package
composer pip uninstall requests

# Upgrade a package
composer pip install --upgrade numpy
```

Installed packages and their sources are saved to `composer.json` under `extra.python-in-php.packages` and are re-installed automatically on the next `composer install`.

### PyTorch GPU backends

`composer pip install torch` picks the right PyTorch build for your hardware automatically:

- **NVIDIA (CUDA), AMD (ROCm), Intel (XPU)** — on Linux and Windows the install runs with uv's `--torch-backend=auto`, which detects the GPU and driver and selects the matching wheel index (e.g. `cu130`, `rocm7.2`).
- **Apple (MPS)** — on macOS the standard PyPI wheels already include Metal/MPS support, so no index override is applied.

To force a specific backend, set the `PYTHON_IN_PHP_TORCH_BACKEND` environment variable (e.g. `cpu`, `cu128`, `rocm7.2`, or `none` to disable the default) or pass `--torch-backend=...` explicitly:

```bash
PYTHON_IN_PHP_TORCH_BACKEND=cpu composer pip install torch
```

## Configuration

Configure Python-in-PHP in `composer.json`:

```json
{
    "extra": {
        "python-in-php": {
            "python-version": "3.12",
            "packages": [
                {"name": "requests", "version": "*"},
                {"name": "numpy",    "version": "^1.24"},
                {
                    "name":      "torch",
                    "version":   "2.7.0+rocm6.3",
                    "index-url": "https://download.pytorch.org/whl/rocm6.3"
                },
                {
                    "name":    "my-local-lib",
                    "version": "*",
                    "path":    "/home/user/my-local-lib"
                }
            ]
        }
    }
}
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `python-version` | string | `"3.12"` | Python version to install |
| `packages` | array | `[]` | List of packages to install |
| `packages[].name` | string | — | Package name |
| `packages[].version` | string | `"*"` | Version constraint (PEP 440 or `*`) |
| `packages[].index-url` | string | — | Custom PyPI index URL for this package |
| `packages[].path` | string | — | Absolute path to a local package directory |

## Using Python objects

Python objects are returned as `PythonObject` instances that support method calls and attribute access:

```php
<?php

use py\requests;

$response = requests::get('https://httpbin.org/json');
$data = $response->json();  // method call
echo $response->status_code; // attribute access
```

### Context managers

Use `Py::with()` to run code inside a Python context manager — the equivalent of
Python's `with` statement. The context is exited afterwards even if the callback
throws.

```php
<?php

use py\builtins;

// with file:
$file = builtins::open('/tmp/data.txt', 'w');
Py::with($file, function () use ($file) {
    $file->write('hello');
});

// with open(...) as f:  — the callback receives the entered value
Py::with(builtins::open('/tmp/data.txt', 'a'), function ($f) {
    $f->write(' world');
});
// the file is closed automatically when the context exits
```

`Py::with()` returns whatever the callback returns.

### PHP callbacks in Python

You can pass a PHP callable as an argument to any Python call. Python receives a
callable it can invoke synchronously — the PHP callback runs and its return value
is sent back — so functions like `map`, `filter`, `sorted(key=...)` and any API
that takes a callback work directly:

```php
<?php

use py\builtins;

// A PHP closure invoked by Python's map()
$doubled = builtins::list(builtins::map(fn ($x) => $x * 2, [1, 2, 3]));
// [2, 4, 6]

// As a sorting key
$sorted = builtins::sorted(['ccc', 'a', 'bb'], key: fn ($w) => strlen($w));
// ['a', 'bb', 'ccc']
```

The callback may itself call back into Python (re-entrancy), and it can be stored
by Python and invoked later:

```php
<?php

use py\functools;

$adder = functools::partial(fn ($a, $b) => $a + $b, 10);
echo $adder(5); // 15 — the PHP callback runs each time Python calls the partial
```

Most PHP callables are auto-detected as callbacks: a `Closure`, a first-class
callable (`strlen(...)`), a `[$object, 'method']` pair, an invokable object, etc.

> ℹ️ **Callable strings are not auto-detected.** A plain string such as
> `'strlen'` is ambiguous with ordinary string data, so it is passed to Python as
> a string. To use a named function (or force callback semantics for any value),
> wrap it in `Py::callback()`:
>
> ```php
> $lengths = builtins::map(Py::callback('strlen'), ['a', 'bb', 'ccc']);
> ```

If an exception is thrown inside the PHP callback, it propagates back to the
original caller.

### Exceptions

Python exceptions are thrown as `Python_In_PHP\PythonException`:

```php
<?php

use Python_In_PHP\PythonException;
use py\json;

try {
    json::loads('invalid json');
} catch (PythonException $e) {
    echo $e->getMessage();   // "Python error: Expecting value: line 1..."
    echo $e->traceback;      // full Python traceback
}
```

## AI model example

```php
<?php

use py\transformers;
use py\torch;

$model_name = 'google/gemma-3-4b-it';

$tokenizer = transformers\AutoTokenizer::from_pretrained($model_name);

$model = transformers\AutoModelForCausalLM::from_pretrained(
    $model_name,
    torch_dtype: torch::$bfloat16,
    device_map: "auto"
);

$messages = [
    ['role' => 'user', 'content' => 'Why PHP is great?']
];

$input_ids = $tokenizer->apply_chat_template(
    $messages,
    return_tensors: 'pt',
    add_generation_prompt: true
);

$outputs = $model->generate($input_ids, max_new_tokens: 2048);
$result = $tokenizer->decode($outputs[0], skip_special_tokens: true);
```

Or simpler with `transformers pipeline`:

```php
<?php

use py\transformers;
use py\torch;

$pipe = transformers\pipeline(
    'text-generation',
    model: 'google/gemma-3-4b-it',
    torch_dtype: torch::$bfloat16,
    device_map: 'auto'
);

$messages = [['role' => 'user', 'content' => 'Why PHP is great?']];
$output = $pipe($messages, max_new_tokens: 2048);
$result = end($output[0]['generated_text'])['content'];
```

## Troubleshooting

**`uv` download fails / no internet access**

The library downloads `uv` automatically during `composer install`. If your environment has no internet access, install `uv` manually before running Composer:

- macOS/Linux: `curl -Ls https://astral.sh/uv/install.sh | sh`
- Windows: `winget install astral-sh.uv`

Then set the `UV_BIN` env variable or ensure `uv` is in your `PATH`.

**"Class py\xxx not found"**

PHPDoc stubs are generated in `vendor/syncfly/python-in-php/py/`. Run `composer install` to regenerate them after adding new packages.

**"Python script was not found"**

The Python binary symlink is missing. Remove `vendor/bin/python-in-php/` and re-run `composer install`.

**Python server does not start within 30 seconds**

Check that the Python binary works: `vendor/bin/python-in-php/python --version`. If it fails, remove the environment and reinstall: `rm -rf vendor/bin/python-in-php && composer install`.

**Permission denied on Windows**

Symlink creation requires Administrator privileges or Windows Developer Mode. Run your terminal as Administrator, or enable Developer Mode in Windows Settings → System → Developer options.

## License

This project is distributed under a source-available license.

#### Allowed:
- Using the package in your projects, including commercial ones ✅
- Making changes and submitting pull requests to this repository

#### Prohibited:
- Creating public forks or distributing the project under your own name
- Uploading the code (modified or original) anywhere else

✅ All contributions are accepted through pull requests to the official repository

#### Attribution:
- Attribution notice is required for software with publicly available source code

See [LICENSE.md](./LICENSE.md) for full details.
