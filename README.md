# SimpleHelper

**Alpha — v0.1.0.** This module is in early testing. The API may change before a stable release. Feedback and bug reports are welcome.

GitHub-based utility vault management for ProcessWire. Helpers are downloaded from GitHub on first use, cached locally, and served from the local copy thereafter. Supports ETag-based updates, multi-vault contexts, and metadata discovery.

The Helper class lives at `SimpleWire\Helper\Helper`.

## Features

*   **Local-first caching:** Downloaded once, used locally forever — works offline after first fetch
*   **ETag updates:** `update()` sends `If-None-Match` and skips the re-download when nothing changed (304)
*   **Multi-vault:** Load helpers from any GitHub user's `SimpleHelperVault` repo with `helper('username')`
*   **Version pinning:** Use `@tag` syntax to pin to a specific branch or release tag
*   **Discovery:** Search and find installed helpers by tags, author, or keyword
*   **Proxy loader:** Call helper methods directly on the loader — no unwrapping needed
*   **GitHub token support:** Raises rate limit from 60 to 5,000 requests/hour; enables private repos

## Installation

1.  Install **SimpleClient** first (required for downloading helpers from GitHub)
2.  Copy `SimpleHelper` to `/site/modules/`
3.  Go to **Modules → Refresh** in the ProcessWire admin and install **SimpleHelper**

## Quick Access

```php
// Global function (recommended)
helper()->load('ArrayTools');
helper('johndoe')->load('EmailValidator');

// Named helper — returns the SimpleHelper module
simplehelper()->newHelper('johndoe');

// Direct API variable
wire()->simplehelper->newHelper();
```

## Quick Start

```php
<?php
namespace ProcessWire;

// Load a helper from the default vault (wirecodex/SimpleHelperVault on GitHub)
// First call: downloads and caches locally. Every subsequent call: uses local copy.
$arr = helper()->load('ArrayTools');

$grouped = $arr->groupBy($users, 'role');
$names   = $arr->pluck($grouped['admin'], 'name');

// Load from any GitHub user's SimpleHelperVault
$validator = helper('johndoe')->load('EmailValidator');
$barcode   = helper('pixrael')->load('BarcodeGenerator');

// Pin to a specific branch or version tag
$helper = helper('johndoe@dev')->load('EmailValidator');
$helper = helper('pixrael@v2.0')->load('BarcodeGenerator');
```

## API

### `load(string|array $name): HelperLoader|array`

Load one or multiple helpers. Returns a `HelperLoader` for a single name, or an associative array of loaders for multiple names. Downloads from GitHub on first call; subsequent calls use the local cache.

```php
// Single — returns HelperLoader (methods callable directly)
$arr = helper()->load('ArrayTools');
$flat = $arr->flatten($nested);

// Multiple — returns ['Name' => HelperLoader, ...]
$helpers = helper()->load(['ArrayTools', 'StringUtils']);
$grouped = $helpers['ArrayTools']->groupBy($data, 'role');
```

### `with(array $args): HelperLoader`

Initialize a helper with constructor arguments. Arguments are passed positionally in array order.

```php
$gpt = helper()->load('GPTUtils')->with(['apiKey' => 'sk-xxx', 'model' => 'gpt-4']);
$response = $gpt->chat('Summarize this article...');

$mailer = helper()->load('EmailService')->with([
    'apiKey' => $config->sendgridKey,
    'from'   => 'noreply@example.com',
]);
$mailer->send($user->email, 'Welcome!', $body);
```

### `import(string|array $methods): callable|array`

Import one or more methods as plain callables — useful for passing to `array_map`, `usort`, etc.

```php
// Single method — returns callable
$flatten = helper()->load('ArrayTools')->import('flatten');
$flat = $flatten($nested);

// Multiple methods — returns ['method' => callable, ...]
$funcs = helper()->load('ArrayTools')->import(['groupBy', 'sortBy', 'pluck']);
$grouped = $funcs['groupBy']($products, 'category');
$sorted  = $funcs['sortBy']($grouped['electronics'], 'price');
$names   = $funcs['pluck']($sorted, 'name');
```

### `install(string $name): bool`

Pre-download a helper without using it immediately. Useful for seeding a local cache during deployment.

```php
helper()->install('ArrayTools');
helper('pixrael')->install('GeoUtils');
helper('pixrael@v2.0')->install('GeoUtils');
```

### `update(string $name): bool`

Re-check GitHub for changes. Uses ETag — returns `true` immediately if nothing changed (304). Logs a warning to `simplehelper` if the download fails but a local copy still exists.

```php
helper()->update('ArrayTools');
helper('pixrael')->update('GeoUtils');
```

### `remove(string $name): bool`

Delete a helper from the local vault. The helper will be re-downloaded from GitHub on the next `load()` call.

```php
helper()->remove('OldHelper');
helper('pixrael')->remove('GeoUtils');
```

### `installed(): array`

List helper names currently in this vault.

```php
$names = helper()->installed();         // ['ArrayTools', ...]
$names = helper('pixrael')->installed();
```

### `info(string $name): ?object`

Get metadata for a specific installed helper.

```php
$info = helper()->info('ArrayTools');
echo $info->title;    // "Array Utilities"
echo $info->version;  // "1.0.0"
echo $info->summary;
print_r($info->tags);
```

### `find(string $selector): array`

Find installed helpers matching a `key=value` selector.

```php
$helpers = helper()->find("tags=array");
$helpers = helper()->find("author=israel");
$helpers = helper()->find("tags=utility, vault=defaults");

foreach ($helpers as $info) {
    echo $info->name . ': ' . $info->summary;
}
```

### `search(string $keyword): array`

Full-text search across name, title, summary, and tags of installed helpers.

```php
$results = helper()->search('barcode');
$results = helper()->search('geolocation');

foreach ($results as $helper) {
    echo $helper->title;
}
```

## Included Helpers

### ArrayTools

Advanced array manipulation utilities. Available immediately after installation — no download needed.

```php
$arr = helper()->load('ArrayTools');

// Flatten nested arrays (depth-limited or unlimited)
$flat = $arr->flatten([[1, 2], [3, [4, 5]]]);          // [1, 2, 3, 4, 5]
$flat = $arr->flatten([[1, [2, [3]]]], depth: 1);       // [1, 2, [3]]

// Group by key or callback
$byRole = $arr->groupBy($users, 'role');
$byLen  = $arr->groupBy($words, fn($w) => strlen($w));

// Unique values
$unique = $arr->unique($items, 'id');

// Pluck a column
$names = $arr->pluck($users, 'name');                   // ['John', 'Jane', ...]

// Sort by key or callback
$sorted     = $arr->sortBy($products, 'price');
$sortedDesc = $arr->sortBy($products, 'price', true);

// Filter, map, reduce
$active  = $arr->filter($items, fn($i) => $i->active);
$titles  = $arr->map($items, fn($i) => $i->title);
$total   = $arr->reduce($items, fn($sum, $i) => $sum + $i->price, 0);

// First / last matching element
$first = $arr->first($items, fn($i) => $i->featured);
$last  = $arr->last($items);

// Partition into two arrays
[$active, $inactive] = $arr->partition($items, fn($i) => $i->active);

// Chunk
$pages = $arr->chunk($items, 10);

// Contains
$has = $arr->contains($items, 'value');
```

## Creating Custom Helpers

### 1. Create a `SimpleHelperVault` repository on GitHub

Name the repository exactly **`SimpleHelperVault`** — this is the convention that lets `helper('yourusername')` resolve it automatically.

### 2. Structure: one folder per helper

```
yourusername/SimpleHelperVault/
├── ArrayUtils/
│   ├── ArrayUtils.php      ← Main class (must match folder name)
│   └── helpers.php         ← Supporting files (optional)
├── EmailValidator/
│   ├── EmailValidator.php
│   └── DnsChecker.php
└── GeoUtils/
    ├── GeoUtils.php
    └── data/
        └── countries.json   ← Sub-folders are downloaded recursively
```

### 3. Write the main class file

```php
<?php
declare(strict_types=1);
// File: EmailValidator/EmailValidator.php

namespace SimpleWire\Helper\Yourusername; // PascalCase of your GitHub username

require_once __DIR__ . '/DnsChecker.php';

class EmailValidator
{
    public static function getHelperInfo(): array
    {
        return [
            'title'   => 'Email Validator',
            'version' => '1.0.0',
            'summary' => 'Advanced email validation with DNS lookup',
            'author'  => 'Your Name',
            'tags'    => ['email', 'validation'],
        ];
    }

    public function validate(string $email): bool
    {
        $checker = new DnsChecker();
        return $checker->checkMX($email);
    }
}
```

### 4. Push and use

```php
// Anyone can load it immediately
$validator = helper('yourusername')->load('EmailValidator');
$isValid   = $validator->validate('test@example.com');
```

### Namespace rule

Helper classes must declare `namespace SimpleWire\Helper\Yourusername` where `Yourusername` is the PascalCase form of your GitHub username:

| GitHub username | Namespace segment |
|---|---|
| `wirecodex` | `Wirecodex` |
| `john-doe` | `JohnDoe` |
| `my_plugin` | `MyPlugin` |

### Local development (no GitHub)

Place helpers directly in the `vaults/local/` directory for local-only helpers that never touch GitHub:

```
/site/assets/SimpleWire/vaults/local/
└── MyHelper/
    └── MyHelper.php
```

```php
$helper = helper('local')->load('MyHelper');
```

## Vault Structure

```
/site/assets/SimpleWire/
└── vaults/
    ├── defaults/                   ← wirecodex/SimpleHelperVault cache
    │   └── ArrayTools/
    │       ├── ArrayTools.php
    │       ├── .info.json          ← Metadata cache for find()/search()
    │       └── .meta.json          ← ETag storage for update()
    ├── pixrael/                    ← pixrael/SimpleHelperVault cache
    │   └── BarcodeGenerator/
    │       ├── BarcodeGenerator.php
    │       └── fonts/
    ├── johndoe/                    ← johndoe/SimpleHelperVault cache
    │   └── EmailValidator/
    │       └── EmailValidator.php
    └── local/                      ← Local helpers (not from GitHub)
        └── MyHelper/
            └── MyHelper.php
```

## Module Configuration

Settings are in the **SimpleHelper** module configuration screen.

*   **Default GitHub User:** Username whose `SimpleHelperVault` is used when calling `helper()` with no argument. Default: `wirecodex`.
*   **Default Branch / Tag:** Git ref used when downloading. Default: `main`. Use `user@branch` syntax on individual calls to override per-load.
*   **GitHub Personal Access Token:** Optional. Raises the unauthenticated rate limit from 60 to 5,000 requests/hour and enables access to private repositories.

## Complete Examples

### Processing Page Data

```php
$arr = helper()->load('ArrayTools');

$products = wire()->pages->find("template=product");

$byCategory = $arr->groupBy($products->getArray(), 'category');
$topPriced  = $arr->sortBy($byCategory['electronics'], 'price', true);
$names      = $arr->pluck(array_slice($topPriced, 0, 5), 'title');
```

### Configuring a Helper with API Keys

```php
$gpt = helper()->load('GPTUtils')->with([
    'apiKey'      => wire()->config->openaiKey,
    'model'       => 'gpt-4',
    'temperature' => 0.7,
]);

$summary     = $gpt->summarize($page->body, 150);
$translation = $gpt->translate($summary, 'es');
```

### Import Methods as Callbacks

```php
$flatten = helper()->load('ArrayTools')->import('flatten');
$groupBy = helper()->load('ArrayTools')->import('groupBy');

$flat    = $flatten($nestedData);
$grouped = $groupBy($flat, 'category');
```

### Pre-loading for Offline or Deployment Use

```php
// Pre-download helpers during deployment
helper()->install('ArrayTools');
helper('pixrael')->install('GeoUtils');
helper('johndoe')->install('EmailValidator');

// Alternatively, clone repos directly to the vaults directory
// git clone https://github.com/pixrael/SimpleHelperVault.git site/assets/SimpleWire/vaults/pixrael
```

## Troubleshooting

#### Helper not found after `install()`:

*   Confirm the helper folder name matches exactly (PascalCase)
*   Check that `SimpleHelperVault` is the exact GitHub repository name for that user
*   Verify `site/assets/SimpleWire/vaults/` is writable

#### GitHub API rate limit error:

*   Add a GitHub Personal Access Token in the module settings to raise the limit to 5,000 requests/hour
*   The error message includes the reset time so you know when to retry

#### Class not found after download:

*   Ensure the namespace in the helper file is `SimpleWire\Helper\Yourusername` (PascalCase of GitHub username)
*   Ensure the class name matches the folder name exactly

#### Update logs a warning but returns false:

*   The GitHub API was unreachable; the local cached copy is still intact and will be used on load
*   Check `Setup > Logs > simplehelper` for details

## API Reference

### Global Functions

```php
helper(?string $context = null): \SimpleWire\Helper\Helper
simplehelper(): \ProcessWire\SimpleHelper
```

### Helper Instance Methods

*   `load(string|array $name): HelperLoader|array`
*   `install(string $name): bool`
*   `update(string $name): bool`
*   `remove(string $name): bool`
*   `installed(): array`
*   `info(string $name): ?object`
*   `find(string $selector): array`
*   `search(string $keyword): array`

### HelperLoader Methods

*   `with(array $args): static` — pass constructor arguments
*   `getInstance(): object` — get the underlying instance
*   `import(string|array $methods): callable|array` — extract methods as callables
*   `__call(string $method, array $arguments): mixed` — proxy calls directly to the helper

## License

This module is released under the MIT License.
