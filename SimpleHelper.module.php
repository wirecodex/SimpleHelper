<?php

declare(strict_types=1);

namespace ProcessWire;

/** @property \ProcessWire\ProcessWire $wire */
class SimpleHelper extends WireData implements Module, ConfigurableModule
{
    public static function getModuleInfo(): array
    {
        return [
            'title'    => 'SimpleHelper',
            'version'  => '0.1.0',
            'summary'  => 'GitHub-based utility vault management with local caching, ETag updates, and discovery.',
            'icon'     => 'puzzle-piece',
            'author'   => 'WireCodex',
            'autoload' => true,
            'singular' => true,
            'requires' => 'ProcessWire>=3.0.200,PHP>=8.1,SimpleClient',
        ];
    }

    // ========================================
    // Lifecycle
    // ========================================

    public function init(): void
    {
        spl_autoload_register(function (string $class): void {
            $prefix = 'SimpleWire\\Helper\\';
            if (!str_starts_with($class, $prefix)) return;
            $relative = substr($class, strlen($prefix));
            $file     = __DIR__ . '/classes/' . str_replace('\\', '/', $relative) . '.php';
            if (file_exists($file)) require_once $file;
        });

        $this->wire('simplehelper', $this);

        $this->seedBundledVault();

        require_once __DIR__ . '/functions.php';
    }

    /**
     * Copy the bundled defaults vault to site/assets/SimpleWire/vaults/defaults/ on first run.
     * No-op if the target already exists.
     */
    private function seedBundledVault(): void
    {
        $target  = $this->wire->config->paths->assets . 'SimpleWire/vaults/defaults';
        $bundled = __DIR__ . '/vaults/defaults';

        if (!is_dir($target) && is_dir($bundled)) {
            $this->wire->files->mkdir(dirname($target), true);
            $this->wire->files->copy($bundled, $target);
        }
    }

    // ========================================
    // Factory
    // ========================================

    /**
     * Create a new Helper instance scoped to a user/branch context.
     *
     * @param string|null $context  null → default vault, 'johndoe' → johndoe vault,
     *                              'johndoe@dev' → johndoe vault @ dev branch
     * @return \SimpleWire\Helper\Helper
     */
    public function newHelper(?string $context = null): \SimpleWire\Helper\Helper
    {
        $config = array_merge(
            \SimpleWire\Helper\Helper::getDefaults(),
            (array) $this->wire('modules')->getConfig($this)
        );

        return new \SimpleWire\Helper\Helper($this->wire, $config, $context);
    }

    // ========================================
    // Config UI
    // ========================================

    public static function getModuleConfigInputfields(array $data): InputfieldWrapper
    {
        $modules = wire()->modules;

        /** @var InputfieldWrapper $wrapper */
        $wrapper = $modules->get('InputfieldWrapper');

        // ---- GitHub Settings ----

        /** @var \ProcessWire\InputfieldFieldset $fieldset */
        $fieldset        = $modules->get('InputfieldFieldset');
        $fieldset->label = 'GitHub Settings';
        $fieldset->icon  = 'github';

        /** @var \ProcessWire\InputfieldText $field */
        $field              = $modules->get('InputfieldText');
        $field->name        = 'helper_defaultUser';
        $field->label       = 'Default GitHub User';
        $field->description = 'GitHub username whose SimpleHelperVault is used by default';
        $field->value       = $data['helper_defaultUser'] ?? 'wirecodex';
        $field->columnWidth = 50;
        $fieldset->add($field);

        /** @var \ProcessWire\InputfieldText $field */
        $field              = $modules->get('InputfieldText');
        $field->name        = 'helper_defaultBranch';
        $field->label       = 'Default Branch / Tag';
        $field->description = 'Default git ref to use when downloading (e.g. main, dev, v2.0)';
        $field->value       = $data['helper_defaultBranch'] ?? 'main';
        $field->columnWidth = 50;
        $fieldset->add($field);

        /** @var \ProcessWire\InputfieldText $field */
        $field              = $modules->get('InputfieldText');
        $field->name        = 'helper_githubToken';
        $field->label       = 'GitHub Personal Access Token';
        $field->description = 'Optional. Raises rate limit from 60 to 5,000 requests/hour and enables access to private repositories.';
        $field->value       = $data['helper_githubToken'] ?? '';
        $field->attr('type', 'password');
        $field->columnWidth = 100;
        $fieldset->add($field);

        $wrapper->add($fieldset);

        return $wrapper;
    }
}
