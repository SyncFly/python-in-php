# Python → PHP Syntax Reference

This guide shows you how to translate Python code into PHP when using **python-in-php**.
Every Python pattern has a direct PHP equivalent — once you learn the mapping, using Python
libraries in PHP feels natural.

---

## Quick-reference cheatsheet

| Python | PHP | Notes |
|--------|-----|-------|
| `import json` | `use py\json;` | At the top of your file |
| `json.dumps(x)` | `json::dumps($x)` | Module-level function |
| `obj.method(a, b)` | `$obj->method($a, $b)` | Instance method |
| `obj.attr` | `$obj->attr` | Attribute read |
| `obj.attr = v` | `$obj->attr = $v` | Attribute write |
| `ClassName(args)` | `new ClassName($args)` | Object construction |
| `ClassName.static_attr` | `ClassName::$static_attr` | Static / class attribute |
| `ClassName.method(a)` | `ClassName::method($a)` | Static / class method |
| `func(a, key=b)` | `func($a, key: $b)` | Keyword argument (PHP 8 named arg) |
| `obj[i]` | `$obj[$i]` | `__getitem__` |
| `obj[i] = v` | `$obj[$i] = $v` | `__setitem__` |
| `len(obj)` | `count($obj)` | `__len__` |
| `str(obj)` | `(string)$obj` | `__str__` |
| `for x in obj:` | `foreach ($obj as $x)` | Iteration |
| `None` | `null` | |
| `True` / `False` | `true` / `false` | |

---

## Imports and namespaces

Python modules become PHP namespaces under `py\`. Use PHP's `use` statement exactly where you
would write `import` in Python.

```python
# Python
import json
import os.path
from datetime import datetime
```

```php
// PHP
use py\json;           // json::dumps(), json::loads(), …
use py\os\path;        // path::join(), path::exists(), …
use py\datetime\datetime;  // datetime::now(), new datetime(…)
```

Submodules map directly to sub-namespaces: `os.path` → `py\os\path`,
`datetime.datetime` → `py\datetime\datetime`.

---

## Calling functions

### Module-level functions

```python
# Python
import json
result = json.dumps({"key": "value"}, indent=2)
```

```php
// PHP
use py\json;
$result = json::dumps(["key" => "value"], indent: 2);
```

### Instance methods

```python
# Python
response = requests.get("https://example.com")
data = response.json()
text = response.text
```

```php
// PHP
use py\requests;
$response = requests::get("https://example.com");
$data     = $response->json();
$text     = $response->text;
```

### Method chaining

```python
# Python
ts = datetime.now().isoformat()
```

```php
// PHP
use py\datetime\datetime;
$ts = datetime::now()->isoformat();
```

### Calling objects as functions

Some Python objects are callable (e.g. pipelines, models). Use `$obj(...)` in PHP:

```python
# Python
pipe = pipeline("text-generation", model="gpt2")
result = pipe("Hello, world!", max_new_tokens=50)
```

```php
// PHP
use py\transformers;
$pipe   = transformers::pipeline("text-generation", model: "gpt2");
$result = $pipe("Hello, world!", max_new_tokens: 50);
```

---

## Keyword arguments (kwargs)

PHP 8 named arguments map directly to Python keyword arguments:

```python
# Python
model.generate(input_ids, max_new_tokens=2048, do_sample=True, temperature=0.7)
```

```php
// PHP
$model->generate($input_ids, max_new_tokens: 2048, do_sample: true, temperature: 0.7);
```

This works for any call — instance methods, static methods, or standalone functions:

```python
# Python
tokenizer.apply_chat_template(messages, return_tensors="pt", add_generation_prompt=True)
```

```php
// PHP
$tokenizer->apply_chat_template($messages, return_tensors: "pt", add_generation_prompt: true);
```

---

## Constructing objects

```python
# Python
from datetime import datetime
dt = datetime(1969, 7, 20, 20, 17)
print(dt.year)   # 1969
```

```php
// PHP
use py\datetime\datetime;
$dt = new datetime(1969, 7, 20, 20, 17);
echo $dt->year;   // 1969
```

Constructor keyword arguments work the same way:

```python
# Python
model = AutoModelForCausalLM.from_pretrained(
    model_name, torch_dtype=torch.bfloat16, device_map="auto"
)
```

```php
// PHP
use py\transformers;
use py\torch;
$model = transformers\AutoModelForCausalLM::from_pretrained(
    $model_name,
    torch_dtype: torch::$bfloat16,
    device_map: "auto"
);
```

---

## Static attributes

Python class attributes are accessed with `::$` in PHP (the dollar sign is required):

```python
# Python
import sys
import torch

print(sys.platform)      # 'linux'
dtype = torch.bfloat16
```

```php
// PHP
use py\sys;
use py\torch;

echo sys::$platform;       // 'linux'
$dtype = torch::$bfloat16;
```

---

## Array indexing

```python
# Python
outputs = model.generate(input_ids)
first   = outputs[0]
token   = outputs[0][42]
```

```php
// PHP
$outputs = $model->generate($input_ids);
$first   = $outputs[0];
$token   = $outputs[0][42];
```

Setting and deleting items:

```python
# Python
obj[key] = value
del obj[key]
```

```php
// PHP
$obj[$key] = $value;
unset($obj[$key]);
```

---

## Iteration

```python
# Python
for row in response:
    print(str(row))
```

```php
// PHP
foreach ($response as $row) {
    echo (string)$row;
}
```

---

## Counting and string conversion

```python
# Python
n   = len(arr)
txt = str(obj)
```

```php
// PHP
$n   = count($arr);
$txt = (string)$obj;
```

---

## Type conversion

Types are converted automatically when crossing the PHP↔Python boundary:

| Python type | PHP type | Notes |
|-------------|----------|-------|
| `int` | `int` | |
| `float` | `float` | |
| `str` | `string` | |
| `bool` | `bool` | |
| `None` | `null` | |
| `list` / `tuple` (simple values) | `array` | Sequential |
| `dict` (non-empty, string keys only) | `array` | Associative |
| `dict` (integer keys or empty) | `PythonDict` | See below |
| `float('inf')` | `INF` | PHP constant |
| `float('-inf')` | `-INF` | PHP expression |
| `float('nan')` | `NAN` | PHP constant |
| Any complex object | `PythonObject` | Accessed via `->` and `[]` |

### PHP arrays become Python lists or dicts

When you pass a PHP array to Python:
- Sequential array (`[1, 2, 3]`) → Python `list`
- Associative array (`["key" => "val"]`) → Python `dict`

### PythonDict

When Python returns a dict with integer keys or an empty dict, the library wraps it in
`PythonDict` to preserve the type through JSON serialization. It behaves just like an array:

```php
use Python_In_PHP\PythonDict;

$d = $bridge->eval("{0: 'zero', 1: 'one'}");  // PythonDict
echo $d[0];          // 'zero'
echo count($d);      // 2

foreach ($d as $k => $v) {
    echo "$k: $v\n";
}

// Convert to plain PHP array if you need one
$arr = $d->toArray();
```

For dicts with string keys, you get a plain PHP array:

```php
$d = json::loads('{"a": 1, "b": 2}');
echo $d['a'];   // 1 — plain array, no wrapper
```

---

## Naming conflicts

A few Python names are reserved words in PHP (`list`, `match`, `default`, …). The generated
stubs prefix them with an underscore:

```python
# Python
builtins.list([1, 2, 3])
builtins.print("hello")
```

```php
// PHP
use py\builtins;
builtins::_list([1, 2, 3]);
builtins::_print("hello");
```

Python names that start with `_` (private by convention) are not included in the generated
stubs, but you can still access them at runtime via `$obj->_name`.

---

## Context managers

```python
# Python
with open("data.txt", "r") as f:
    content = f.read()
```

```php
// PHP
use Python_In_PHP\PythonBridge;

$bridge = PythonBridge::startOrGetRunning();
$file   = $bridge->eval("open('data.txt', 'r')");

$content = $bridge->with($file->getObjectId(), function () use ($file) {
    return $file->read();
    // __exit__ is called automatically here, even if an exception is thrown
});
```

---

## Exception handling

Any exception raised in Python becomes a `PythonException` in PHP:

```python
# Python
try:
    json.loads("bad json")
except json.JSONDecodeError as e:
    print(e)
```

```php
// PHP
use Python_In_PHP\PythonException;
use py\json;

try {
    json::loads("bad json");
} catch (PythonException $e) {
    echo $e->getMessage();  // "Python error: Expecting value: line 1 column 1 (char 0)"
    echo $e->traceback;     // full Python traceback for debugging
}
```

---

## Low-level bridge API

For cases where the generated stubs aren't enough, you can use `PythonBridge` directly:

```php
use Python_In_PHP\PythonBridge;

$bridge = PythonBridge::startOrGetRunning();

// Run a Python expression and get the result
$result = $bridge->eval('2 ** 10');       // 1024

// Run Python statements (no return value)
$bridge->exec('import sys; sys.path.append("/my/lib")');

// Import a module and get it as a PythonObject
$np = $bridge->importModule('numpy');

// Call a function by dotted name
$arr = $bridge->call('numpy.array', [[1, 2, 3]]);

// Call a method on a PythonObject
$mean = $bridge->callMethod($arr->getObjectId(), 'mean');
```

---

## Full example: requests + json

```python
# Python
import requests, json

response = requests.get("https://jsonplaceholder.typicode.com/posts/1")
post     = response.json()
print(post["title"])
print(f"Status: {response.status_code}")
```

```php
// PHP
<?php
use py\requests;

$response = requests::get("https://jsonplaceholder.typicode.com/posts/1");
$post     = $response->json();

echo $post["title"] . "\n";
echo "Status: " . $response->status_code . "\n";
```

## Full example: AI inference with transformers

```python
# Python
from transformers import pipeline

pipe   = pipeline("text-generation", model="gpt2")
result = pipe("The meaning of life is", max_new_tokens=50)
print(result[0]["generated_text"])
```

```php
// PHP
<?php
use py\transformers;

$pipe   = transformers::pipeline("text-generation", model: "gpt2");
$result = $pipe("The meaning of life is", max_new_tokens: 50);
echo $result[0]["generated_text"];
```

## Full example: numpy

```python
# Python
import numpy as np

temps = np.array([12.5, 14.1, 13.8, 15.2, 16.0])
mean  = np.mean(temps)
above = temps[temps > mean]
print(above.tolist())
```

```php
// PHP
<?php
use py\numpy;

$temps = numpy::array([12.5, 14.1, 13.8, 15.2, 16.0]);
$mean  = numpy::mean($temps);
$mask  = numpy::greater($temps, $mean);
$above = numpy::where($mask)[0];
print_r($above->tolist());
```
