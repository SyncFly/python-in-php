<table>
<tr>
<td valign="top">

> ⏳ The project is currently under active development. It is still in the alpha stage, but it is already working.
> <br/>⭐️ Star the repository to support the project and follow us

#### Python-in-PHP

The **Python-in-PHP** library allows you to easily use any Python packages as if they were native PHP packages 🐘

🔥 Fully use artificial intelligence frameworks for AI models inference or training directly in PHP! 
<br/>
You can run AI models with libraries like `transformers`, `torch`, `vllm`, `numpy`, etc. in your PHP project with PHP syntax.

✅ Environment with Python is installed automatically with Composer.

✅ Any Python packages are installed via Composer with a built-in package manager.

✅ Automatic PHPDoc generation for code completion in IDEs for any Python packages.

</td>
<td width="200" valign="center">
<img src="image.png" alt="Python-in-PHP" width="200"/>
</td>
</tr>
</table>

### Installation
```bash
composer require syncfly/python-in-php
```
You need to answer "yes" when prompted to activate the plugin.

[//]: # (⭐️ Star the repository to show that it's in demand and ensure that it will be maintained for a long time.)

### Documentation
You can find the documentation here: (coming soon)

### Package manager
The `Python-in-PHP Package Manager` is a built-in package manager based on `uv` that allows you to install and manage Python packages.
#### Intsall a package
`composer pip install <package-name>`
#### Uninstall a package
`composer pip uninstall <package-name>`
#### Upgrade a package
`composer pip install --upgrade <package-name>`

### Examples
Look at an example of using `transformers` and `torch` in PHP for running an AI model:

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

$outputs = $model->generate(
    $input_ids,
    max_new_tokens: 2048
);

$result = $tokenizer->decode($outputs[0], skip_special_tokens: true);
```

Or simplier with `transformers pipeline`:
```php
<?php

use py\transformers;

$pipe = transformers\pipeline(
    'text-generation',
    model: 'google/gemma-3-4b-it',
    torch_dtype: torch::$bfloat16,
    device_map: 'auto'
);

$messages = [
    ['role' => 'user', 'content' => 'Why PHP is great?']
];

$output = $pipe($messages, max_new_tokens: 2048);
$generated = $output[0]['generated_text'];
$result = end($generated)['content'];
```

### License
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