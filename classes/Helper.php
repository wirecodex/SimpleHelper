<?php

declare(strict_types=1);

namespace SimpleWire\Helper;

/**
 * Helper — SimpleWire component for GitHub-based utility vaults.
 *
 * Each instance is scoped to a single user/branch context.
 * Use helper('username') or helper('username@branch') to create instances.
 *
 * @example helper()->load('ArrayTools')           // wirecodex vault
 * @example helper('johndoe')->load('Strings')     // johndoe vault
 * @example helper('johndoe@dev')->load('Strings') // johndoe vault @ dev branch
 */
class Helper
{

    /** @var \ProcessWire\ProcessWire */
    protected $wire;

    protected array $config;

    protected string $user;
    protected string $branch;
    protected string $vaultName;

    public function __construct(\ProcessWire\ProcessWire $wire, array $config = [], ?string $context = null)
    {
        $this->wire   = $wire;
        $this->config = array_merge(static::getDefaults(), $config);
        [$this->user, $this->branch, $this->vaultName] = $this->parseContext($context);
    }

    // ========================================
    // Configurable Interface
    // ========================================

    public static function getDefaults(): array
    {
        return [
            'helper_defaultUser'   => 'wirecodex',
            'helper_defaultBranch' => 'main',
            'helper_githubToken'   => '',
        ];
    }

    // ========================================
    // Public API
    // ========================================

    /**
     * Load one or multiple helpers from this vault.
     */
    public function load(string|array $name): HelperLoader|array
    {
        if (is_array($name)) {
            $result = [];
            foreach ($name as $n) {
                if (!$this->isValidName($n)) {
                    throw new \InvalidArgumentException(
                        "Invalid helper name '{$n}'. Names must start with a letter and contain only letters and digits."
                    );
                }
                $result[$n] = $this->loadSingle($n);
            }
            return $result;
        }

        if (!$this->isValidName($name)) {
            throw new \InvalidArgumentException(
                "Invalid helper name '{$name}'. Names must start with a letter and contain only letters and digits."
            );
        }

        return $this->loadSingle($name);
    }

    /**
     * Explicitly download and cache a helper (no-op if already local).
     */
    public function install(string $name): bool
    {
        if (!$this->isValidName($name)) return false;
        return $this->ensureHelperExists($name);
    }

    /**
     * Re-download a helper, using ETag to skip if nothing changed.
     */
    public function update(string $name): bool
    {
        if (!$this->isValidName($name)) return false;

        $success = $this->downloadHelper($name, checkEtag: true);

        if (!$success && file_exists($this->getHelperMainFile($name))) {
            $this->wire->log->save('simplehelper',
                "Update failed for '{$name}' in vault '{$this->vaultName}'; using cached version."
            );
        }

        return $success;
    }

    /**
     * Remove a helper from local vault.
     */
    public function remove(string $name): bool
    {
        if (!$this->isValidName($name)) return false;
        return $this->removeLocal($name);
    }

    /**
     * List all helper names installed in this vault.
     */
    public function installed(): array
    {
        $vaultPath = $this->getVaultPath();
        if (!is_dir($vaultPath)) return [];

        $helpers = [];
        foreach (scandir($vaultPath) ?: [] as $dir) {
            if ($dir[0] === '.') continue;
            if (is_dir($vaultPath . '/' . $dir)) {
                $helpers[] = $dir;
            }
        }

        return $helpers;
    }

    /**
     * Get metadata for a specific helper in this vault.
     */
    public function info(string $name): ?object
    {
        if (!$this->isValidName($name)) return null;
        $info = $this->readHelperInfo($name, $this->vaultName, $this->getVaultPath());
        return $info ? (object)$info : null;
    }

    /**
     * Find helpers in this vault matching a ProcessWire-style selector.
     *
     * @example find("tags=array")
     * @example find("author=israel")
     *
     * @return object[]
     */
    public function find(string $selector): array
    {
        $criteria  = $this->parseSelector($selector);
        $vaultPath = $this->getVaultPath();
        $results   = [];

        if (!is_dir($vaultPath)) return [];

        foreach (scandir($vaultPath) ?: [] as $name) {
            if ($name[0] === '.') continue;
            if (!is_dir($vaultPath . '/' . $name)) continue;

            $info = $this->readHelperInfo($name, $this->vaultName, $vaultPath);
            if ($info && $this->matchesSelector($info, $criteria)) {
                $results[] = (object)$info;
            }
        }

        return $results;
    }

    /**
     * Search helpers in this vault by keyword.
     *
     * @return object[]
     */
    public function search(string $keyword): array
    {
        $keyword   = strtolower($keyword);
        $vaultPath = $this->getVaultPath();
        $results   = [];

        if (!is_dir($vaultPath)) return [];

        foreach (scandir($vaultPath) ?: [] as $name) {
            if ($name[0] === '.') continue;
            if (!is_dir($vaultPath . '/' . $name)) continue;

            $info = $this->readHelperInfo($name, $this->vaultName, $vaultPath);
            if (!$info) continue;

            $haystack = strtolower(
                $info['name'] . ' ' .
                ($info['title'] ?? '') . ' ' .
                ($info['summary'] ?? '') . ' ' .
                implode(' ', $info['tags'] ?? [])
            );

            if (str_contains($haystack, $keyword)) {
                $results[] = (object)$info;
            }
        }

        return $results;
    }

    // ========================================
    // Load Internals
    // ========================================

    protected function loadSingle(string $name): HelperLoader
    {
        if (!$this->ensureHelperExists($name)) {
            throw new \RuntimeException(
                "Helper '{$name}' could not be resolved from vault '{$this->vaultName}' ({$this->user}/{$this->branch})."
            );
        }

        $mainFile = $this->getHelperMainFile($name);
        require_once $mainFile;

        $class = $this->resolveClassName($name);

        if (!class_exists($class)) {
            throw new \RuntimeException(
                "Helper class '{$class}' not found in '{$mainFile}'. " .
                "Ensure the file declares: namespace SimpleWire\\Helper\\{$this->normalizeNamespace($this->user)};"
            );
        }

        return new HelperLoader($class);
    }

    protected function ensureHelperExists(string $name): bool
    {
        if (file_exists($this->getHelperMainFile($name))) {
            return true;
        }

        return $this->downloadHelper($name);
    }

    // ========================================
    // Download Engine
    // ========================================

    protected function downloadHelper(string $name, bool $checkEtag = false): bool
    {
        return $this->downloadFolder(
            apiPath:   $name,
            localPath: $this->getVaultPath() . '/' . $name,
            checkEtag: $checkEtag
        );
    }

    /**
     * Download a GitHub folder recursively into $localPath.
     *
     * - Handles subdirectories (type=dir) by recursing
     * - Sends If-None-Match when $checkEtag=true and a stored ETag exists
     * - Throws on rate-limit (403/429) with reset time info
     * - Saves ETag from response for future update() calls
     * - Caches getHelperInfo() result to .info.json after top-level download
     */
    protected function downloadFolder(string $apiPath, string $localPath, bool $checkEtag = false): bool
    {
        $url = sprintf(
            'https://api.github.com/repos/%s/SimpleHelperVault/contents/%s?ref=%s',
            $this->user,
            $apiPath,
            $this->branch
        );

        $http = $this->httpClient();

        // ETag — send stored tag to detect "not modified" (304)
        if ($checkEtag) {
            $meta = $this->loadMeta($localPath);
            if (!empty($meta['etag'])) {
                $http->withHeaders(['If-None-Match' => "\"{$meta['etag']}\""]);
            }
        }

        try {
            $response = $http->get($url);
        } catch (\SimpleWire\Client\ClientException $e) {
            $status = $e->getResponseStatus();

            // Rate limit — 403 (unauthenticated) or 429 (authenticated)
            if ($status === 403 || $status === 429) {
                $res       = $e->getResponse();
                $remaining = $res ? $res->header('x-ratelimit-remaining') : '?';
                $reset     = $res ? $res->header('x-ratelimit-reset') : null;
                $resetTime = $reset ? gmdate('Y-m-d H:i:s', (int)$reset) . ' UTC' : 'unknown';

                throw new \RuntimeException(
                    "GitHub API rate limit exceeded (remaining: {$remaining}, resets: {$resetTime}). " .
                    "Add a GitHub personal access token in SimpleWire Helper settings to raise the limit to 5,000 req/hr."
                );
            }

            return false;
        }

        // Not modified — ETag matched, local copy is still current
        if ($response->status() === 304) {
            return true;
        }

        $items = json_decode($response->body(), true);

        // GitHub returns a single object when a file is requested directly
        if (isset($items['type'])) {
            $items = [$items];
        }

        if (!is_array($items)) {
            return false;
        }

        if (!is_dir($localPath)) {
            mkdir($localPath, 0755, true);
        }

        // Persist ETag for future update() calls (strip W/ prefix and surrounding quotes)
        $etag = $response->header('etag');
        if ($etag) {
            $meta               = $this->loadMeta($localPath);
            $meta['etag']       = trim(trim($etag, '"W/'), '"');
            $meta['branch']     = $this->branch;
            $meta['updated_at'] = gmdate('c');
            $this->saveMeta($localPath, $meta);
        }

        foreach ($items as $item) {
            if ($item['type'] === 'dir') {
                // Recursive — no ETag check for sub-folders (only top-level tracks ETag)
                $this->downloadFolder(
                    apiPath:   $apiPath . '/' . $item['name'],
                    localPath: $localPath . '/' . $item['name'],
                    checkEtag: false
                );
            } elseif ($item['type'] === 'file') {
                $this->downloadFile($item['download_url'], $localPath . '/' . $item['name']);
            }
        }

        // Cache helper metadata to .info.json for find()/search()/info() queries
        $this->cacheHelperInfo($localPath, basename($localPath));

        return true;
    }

    /**
     * Download a single raw file via Client.
     */
    protected function downloadFile(string $url, string $localPath): bool
    {
        return $this->httpClient()->download($url, $localPath);
    }

    /**
     * Return a Client instance pre-configured for GitHub API requests.
     */
    protected function httpClient(): \SimpleWire\Client\Client
    {
        $http = $this->wire->simpleclient->newClient('', [
            'client_userAgent' => 'SimpleWire-Helper/1.0',
        ]);

        $http->withHeaders([
            'Accept'               => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ]);

        if ($this->config['helper_githubToken'] ?? '') {
            $http->withToken($this->config['helper_githubToken']);
        }

        return $http;
    }

    /**
     * After downloading, require the main class file and persist getHelperInfo()
     * to .info.json so find()/search()/info() can scan without loading PHP.
     */
    protected function cacheHelperInfo(string $localPath, string $name): void
    {
        $mainFile = $localPath . '/' . $name . '.php';
        $infoFile = $localPath . '/.info.json';

        if (!file_exists($mainFile) || file_exists($infoFile)) {
            return;
        }

        try {
            require_once $mainFile;
            $class = $this->resolveClassName($name);

            if (class_exists($class) && method_exists($class, 'getHelperInfo')) {
                $info    = $class::getHelperInfo();
                $encoded = json_encode($info, JSON_PRETTY_PRINT);
                if ($encoded !== false) {
                    file_put_contents($infoFile, $encoded);
                }
            }
        } catch (\Throwable) {
            // Non-critical — skip if the class cannot be auto-loaded safely
        }
    }

    // ========================================
    // Local Vault Management
    // ========================================

    protected function removeLocal(string $name): bool
    {
        $path = $this->getVaultPath() . '/' . $name;
        if (!is_dir($path)) return false;

        $this->deleteDirectory($path);
        return true;
    }

    protected function deleteDirectory(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $file) {
            if ($file === '.' || $file === '..') continue;
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ========================================
    // ETag Meta Storage
    // ========================================

    /**
     * Load .meta.json for a local helper path.
     */
    protected function loadMeta(string $localPath): array
    {
        $metaFile = $localPath . '/.meta.json';
        if (!file_exists($metaFile)) return [];

        $data = json_decode(file_get_contents($metaFile), true);
        return is_array($data) ? $data : [];
    }

    /**
     * Persist .meta.json for a local helper path.
     */
    protected function saveMeta(string $localPath, array $data): void
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT);
        if ($encoded !== false) {
            file_put_contents($localPath . '/.meta.json', $encoded);
        }
    }

    // ========================================
    // Namespace & Path Helpers
    // ========================================

    /**
     * Resolve fully-qualified class name from username + helper name.
     *
     * Username is normalized to PascalCase so community usernames with
     * hyphens or underscores map cleanly to valid PHP identifiers:
     *   wirecodex  → Wirecodex
     *   john-doe   → JohnDoe
     *   my_plugin  → MyPlugin
     */
    protected function resolveClassName(string $name): string
    {
        return 'SimpleWire\\Helper\\' . $this->normalizeNamespace($this->user) . '\\' . $name;
    }

    /**
     * Normalize a GitHub username to PascalCase for namespace use.
     */
    protected function normalizeNamespace(string $username): string
    {
        $normalized = preg_replace('/[-_.\s]+/', ' ', $username);
        return str_replace(' ', '', ucwords(strtolower($normalized)));
    }

    protected function getHelperMainFile(string $name): string
    {
        return $this->getVaultPath() . "/{$name}/{$name}.php";
    }

    protected function getVaultPath(): string
    {
        return $this->getVaultsPath() . '/' . $this->vaultName;
    }

    protected function getVaultsPath(): string
    {
        return $this->wire->config->paths->assets . 'SimpleWire/vaults';
    }

    // ========================================
    // Validation
    // ========================================

    private function isValidName(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $name);
    }

    // ========================================
    // Context Parsing & Info
    // ========================================

    /**
     * Parse "user" or "user@branch" context into [user, branch, vaultName].
     *
     * The default user (wirecodex) maps to the 'defaults' vault directory
     * to match the bundled vault structure.
     */
    protected function parseContext(?string $context): array
    {
        $defaultUser   = $this->config['helper_defaultUser'] ?? 'wirecodex';
        $defaultBranch = $this->config['helper_defaultBranch'] ?? 'main';

        if (!$context) {
            return [$defaultUser, $defaultBranch, 'defaults'];
        }

        $user   = $context;
        $branch = $defaultBranch;

        if (str_contains($context, '@')) {
            [$user, $branch] = explode('@', $context, 2);
            $user   = $user ?: $defaultUser;
            $branch = $branch ?: $defaultBranch;
        }

        // If the context resolves to the default user, use 'defaults' as vault dir
        $vaultName = ($user === $defaultUser) ? 'defaults' : $user;

        return [$user, $branch, $vaultName];
    }

    /**
     * Read helper info from .info.json cache or fall back to bare directory presence.
     */
    protected function readHelperInfo(string $name, string $vaultName, string $vaultPath): ?array
    {
        $infoFile = $vaultPath . '/' . $name . '/.info.json';

        if (file_exists($infoFile)) {
            $data = json_decode(file_get_contents($infoFile), true);
            if (is_array($data)) {
                return array_merge(['name' => $name, 'vault' => $vaultName], $data);
            }
        }

        // Fallback: helper exists but has no .info.json yet
        if (file_exists($vaultPath . '/' . $name . '/' . $name . '.php')) {
            return ['name' => $name, 'vault' => $vaultName];
        }

        return null;
    }

    /**
     * Parse "key=value, key2=value2" selector string.
     */
    protected function parseSelector(string $selector): array
    {
        $criteria = [];
        foreach (explode(',', $selector) as $part) {
            $part = trim($part);
            if (str_contains($part, '=')) {
                [$key, $value] = explode('=', $part, 2);
                $criteria[trim($key)] = trim($value);
            }
        }
        return $criteria;
    }

    protected function matchesSelector(array $info, array $criteria): bool
    {
        foreach ($criteria as $key => $value) {
            if ($key === 'tags') {
                $tags = array_map('strtolower', $info['tags'] ?? []);
                if (!in_array(strtolower($value), $tags, true)) return false;
            } elseif (isset($info[$key])) {
                if (stripos((string)$info[$key], $value) === false) return false;
            } else {
                return false;
            }
        }
        return true;
    }
}
