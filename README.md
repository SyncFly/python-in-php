# Python packages in PHP

<table>
<tr>
<td valign="top">

### Python-in-PHP

The **Python-in-PHP** library allows you to easily use any Python packages as if they were native PHP packages 🐘

✅ Environment with Python is installed automatically with Composer.

✅ Any Python packages are installed via Composer with a built-in package manager.

✅ Automatic PHPDoc generation for code completion in IDEs for any Python packages.

</td>
<td style="max-width: 30%" valign="center">
<img src="image.jpg" alt="Python-in-PHP" width="200"/>
</td>
</tr>
</table>

🔥 Fully use artificial intelligence frameworks for AI models inference or training directly in PHP!
<br/>
You can run AI models with libraries like `transformers`, `torch`, `vllm`, `numpy`, etc. in your PHP project with PHP syntax.
<br/>
See [syncfly/transformers-torch-php](https://github.com/SyncFly/transformers-torch-php) for a quick start with `transformers` and `torch`.

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

⭐️ Please star the repository to show that there is demand for it, so that you can be sure it will continue to be maintained

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

# Install with extras
composer pip install "requests[socks]"

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

Installed packages and their sources are saved to `composer.json` under `extra.python-in-php.packages` with an approximate constraint (e.g. `^2.32.3`), while the exact resolved versions are pinned in `python-in-php.lock` next to `composer.lock`. On the next `composer install` packages are re-installed exactly from the lock file; without it they are resolved from the `composer.json` constraints and the lock file is created. **Commit `python-in-php.lock` to version control** to get reproducible installs across machines.

`composer pip install --upgrade` re-resolves versions within the `composer.json` constraints and updates only the lock file, mirroring how `composer update` treats PHP packages.

### Running Python

The `composer python` command runs the project's Python interpreter (the one with your
installed packages), runs `.py` scripts, and manages the Python version:

```bash
# Run a script
composer python path/to/script.py arg1 arg2

# Run a one-liner
composer python -c "import numpy; print(numpy.__version__)"

# Show the current Python version
composer python use

# Switch the Python version (recreates the environment and reinstalls packages)
composer python use 3.12
```

The script's exit code is propagated. Note that `--version`/`-V` is intercepted by Composer
itself — use `composer python use` to see the managed version, or `composer python -c "import sys; print(sys.version)"`.

## Manual configuration

In addition to the `composer pip` commands you can configure Python-in-PHP manually in `composer.json`:

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
                    "version":   "2.13.0+rocm7.2",
                    "index-url": "https://download.pytorch.org/whl/rocm7.2"
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
| `packages[].version` | string | `"*"` | Version constraint (Composer-style `^`/`~`, PEP 440 or `*`) |
| `packages[].extras` | array | — | Package extras to install (the `name[extra]` pip syntax) |
| `packages[].index-url` | string | — | Custom PyPI index URL for this package |
| `packages[].path` | string | — | Absolute path to a local package directory |

### PyTorch GPU backends

`composer pip install torch` picks the right PyTorch build for your hardware automatically:

- CUDA for NVIDIA
- ROCm for AMD
- Metal for Apple Silicon
- CPU backend when no GPU is present

To force a specific backend, set the `PYTHON_IN_PHP_TORCH_BACKEND` environment variable (e.g. `cpu`, `cu128`, `rocm7.2`, or `none` to disable the default) or pass `--torch-backend=...` explicitly:

```bash
PYTHON_IN_PHP_TORCH_BACKEND=cpu composer pip install torch
```

## Runtime configuration

The Python worker is started lazily on the first `Py::` call (or first generated class call).
To configure it, call `Py::startIfNotStarted()` **before that first call**, or set environment
variables — every option has one. Passing different options once the bridge exists throws a
`LogicException` rather than being silently ignored.

```php
Py::startIfNotStarted([
    'log_file' => __DIR__ . '/var/log/python.log',
    'timeout'  => 3600,
]);
```

| Option | Environment variable | Default | Description |
|--------|----------------------|---------|-------------|
| `log_file` | `PYTHON_IN_PHP_LOG_FILE` | — | File the worker's stdout/stderr are appended to (see [Logging](#logging)) |
| `logger` | — | — | PSR-3 logger that receives the worker output and bridge events |
| `debug` | `PYTHON_IN_PHP_DEBUG` | `false` | Echo bridge traffic and let the worker inherit the PHP process's stdio |
| `timeout` | `PYTHON_IN_PHP_TIMEOUT` | `36000` | Seconds to wait for a single Python call |
| `python_binary` | `PYTHON_IN_PHP_PYTHON_BINARY` | managed venv | Interpreter used for the worker |
| `working_directory` | `PYTHON_IN_PHP_WORKING_DIRECTORY` | `getcwd()` | Working directory of the worker |
| `host` / `port` | `PYTHON_IN_PHP_HOST` / `PYTHON_IN_PHP_PORT` | `127.0.0.1` / free port | Where the worker listens; an already listening server is reused |

### Logging

By default the worker's stdout and stderr are discarded, so library warnings (LightGBM,
scikit-learn, transformers…), progress bars and `print()` output are lost — as is the reason
a worker died. Give it a file, or a PSR-3 logger, or both:

```php
// Everything Python writes to stdout/stderr, appended to this file
Py::startIfNotStarted(['log_file' => '/var/log/app/python.log']);

// Forwarded line by line to your logger after each call ("[python] …"), with the level
// guessed from the text: tracebacks/errors → error, warnings → warning, everything else → info
Py::startIfNotStarted(['logger' => $monolog]);
```

Python-level exceptions never depend on this — they always arrive as `PythonException`
with the full traceback. The log matters for everything that is *not* an exception.

### Worker lifecycle

```php
Py::isInstalled(); // true when the Python environment exists — a file check, no process spawned
Py::isRunning();   // true when a worker is up and answering — never starts one
Py::stop();        // terminate the worker; the next Py:: call starts a fresh one
Py::restart();     // stop + start; every PythonObject obtained so far becomes stale
```

`Py::isInstalled()` is the hook for test suites that should not require Python:

```php
if (!Py::isInstalled()) {
    $this->markTestSkipped('Python environment not installed — run composer install');
}
```

The same methods exist on the bridge instance (`Py::bridge()->stop()`, `->getWorkerPid()`,
`->getLogFile()`). The worker is also terminated when the PHP process exits.

## Using Python objects

Python objects are returned as `PythonObject` instances that support method calls and attribute access:

```php
<?php

use py\requests;

$response = requests::get('https://httpbin.org/json');
$data = $response->json();  // method call
echo $response->status_code; // attribute access
```

### Python core from PHP

The `Py` facade exposes Python's `exec`/`eval` and its builtins directly — useful for the
things PHP has no equivalent for, or handles differently. It starts the Python worker
automatically on first use.

```php
Py::eval('2 ** 10');                    // 1024
Py::sum([1, 2, 3]);                     // 6  (Python sum(), not array_sum)
Py::sorted([3, 1, 2], reverse: true);   // [3, 2, 1]  — PHP named args become Python kwargs
Py::builtin('pow', 2, 8);               // 256  — call any builtin by name
```

See **[docs/usage.md](./docs/usage.md#python-builtins--running-code-from-php)** for the full list of helpers.

### Context managers

Use `Py::with()` to run code inside a Python context manager — the equivalent of
Python's `with` statement. The context is exited afterwards even if the callback
throws.

```php
<?php

use py\builtins;

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

### Worker crashes

If the worker process itself dies during a call (segfault in a native extension, the OOM
killer, `sys.exit()` inside library code), the call throws
`Python_In_PHP\WorkerCrashedException` instead. It carries the exit code or signal and the
last lines of the worker log when a `log_file` (or `logger`) is configured — without one,
the message tells you how to enable it. The session is torn down and the next `Py::` call
starts a fresh worker; `PythonObject`s from the dead worker are stale and must not be reused.

```php
use Python_In_PHP\WorkerCrashedException;

try {
    $model->fit($x, $y);
} catch (WorkerCrashedException $e) {
    $e->exitCode;   // 137, or null when killed by a signal
    $e->signal;     // 9, or null
    $e->logTail;    // last ~40 lines of stdout/stderr
}
```

A dropped connection while the worker is still alive (for example the WebSocket `timeout`)
is retried once; if that fails a `RuntimeException` is thrown.

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

## Docker

`composer install` downloads `uv` (~10 MB), a managed Python (~30 MB) and the packages. All of
that is driven by `uv`, so its own environment variables work — the library only fills in
defaults for the ones it needs and never overrides a value you set:

| Variable | Effect |
|----------|--------|
| `UV_CACHE_DIR` | Wheel/source cache — mount it as a build cache to skip re-downloading packages |
| `UV_PYTHON_INSTALL_DIR` | Where managed interpreters go (default `vendor/bin/python-in-php/python`) — mount it too |
| `UV_PYTHON_PREFERENCE` | Default `only-managed`; set `system` to use the image's own Python (e.g. `python:3.12` base) instead of downloading one |
| `UV_BIN` | Path to an existing `uv` binary — skips the download entirely |
| `UV_OFFLINE` | `1` disables all network access; requires `UV_BIN` (or `uv` on `PATH`) and a warm cache |

Typical Dockerfile:

```dockerfile
FROM php:8.3-cli
COPY --from=ghcr.io/astral-sh/uv:latest /uv /usr/local/bin/uv
ENV UV_BIN=/usr/local/bin/uv \
    UV_CACHE_DIR=/root/.cache/uv \
    UV_PYTHON_INSTALL_DIR=/opt/uv-python

WORKDIR /app
COPY composer.json composer.lock python-in-php.lock ./
RUN --mount=type=cache,target=/root/.cache/uv \
    --mount=type=cache,target=/opt/uv-python \
    composer install --no-dev --no-interaction
COPY . .
```

If the image already has a matching Python (`python:3.12` etc.), add `UV_PYTHON_PREFERENCE=system`
and drop the `/opt/uv-python` mount.

Run the worker with a log file so crashes are diagnosable from the container logs, e.g.
`PYTHON_IN_PHP_LOG_FILE=/proc/self/fd/2` or a file under a mounted volume.

> ⚠️ The virtual environment records the interpreter it was created from, and the managed
> interpreter lives in `UV_PYTHON_INSTALL_DIR`. In multi-stage builds copy `vendor/` **to the
> same path** it was built at and keep `UV_PYTHON_INSTALL_DIR` identical — or run `composer
> install` in the final stage with the cache mounts above.

## Troubleshooting

**`uv` download fails / no internet access**

The library downloads `uv` automatically during `composer install`. If your environment has no internet access, install `uv` manually before running Composer:

- macOS/Linux: `curl -Ls https://astral.sh/uv/install.sh | sh`
- Windows: `winget install astral-sh.uv`

Then point the `UV_BIN` environment variable at the binary; if the download fails and `UV_BIN` is
not set, a `uv` found on `PATH` is used as a fallback. See [Docker](#docker) for caching and
`UV_OFFLINE`.

**Python warnings, progress bars or prints are missing / a call ends with `WorkerCrashedException`**

The worker's stdout/stderr go to `/dev/null` unless you configure a log: set
`PYTHON_IN_PHP_LOG_FILE` (or the `log_file` / `logger` options). See [Logging](#logging).

**"Class py\xxx not found"**

PHPDoc stubs are generated in `vendor/syncfly/python-in-php/py/`. Run `composer install` to regenerate them after adding new packages.

**"Could not import: …" during stub generation**

Some modules can't be imported while their stubs are generated. Expected cases — optional modules that aren't installed, or platform-only modules on the wrong OS — are hidden; the remaining ones are summarized on a single line. Re-run with `-v` (e.g. `composer install -v`) to see the full error for each module.

**"Python environment is not installed (…/python_bin/python)"**

The managed interpreter is missing: run `composer install` (it creates the venv and the
`python_bin` symlink). `Py::isInstalled()` returns `false` in this state without starting anything.

**"Python script was not found"**

The Python binary symlink is missing. Remove `vendor/bin/python-in-php/` and re-run `composer install`.

**Python server does not start within 30 seconds**

Check that the Python binary works: `vendor/bin/python-in-php/python --version`. If it fails, remove the environment and reinstall: `rm -rf vendor/bin/python-in-php && composer install`.

**Permission denied on Windows**

Symlink creation requires Administrator privileges or Windows Developer Mode. Run your terminal as Administrator, or enable Developer Mode in Windows Settings → System → Developer options.

## License

This project is licensed under the [Apache License 2.0](./LICENSE).

You are free to use, modify and distribute it, including in commercial projects,
provided you retain the copyright notice, state significant changes and include a
copy of the license. The license also grants an explicit patent license.

Contributions submitted to this repository are licensed under the same terms.

"SyncFly" and "Python-in-PHP" are trademarks of the project maintainers and are
not covered by the Apache License; see [TRADEMARKS](./TRADEMARKS) for what use is
permitted without asking.
