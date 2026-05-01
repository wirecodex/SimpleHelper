<?php

declare(strict_types=1);

namespace ProcessWire;

if (!function_exists('ProcessWire\simplehelper')) {
    /**
     * Return the SimpleHelper module instance.
     *
     * @return \ProcessWire\SimpleHelper
     */
    function simplehelper(): \ProcessWire\SimpleHelper
    {
        return wire()->simplehelper;
    }
}

if (!function_exists('ProcessWire\helper')) {
    /**
     * Create a Helper instance scoped to a user/branch context.
     *
     * Usage:
     *   helper()                   → Helper scoped to default vault (wirecodex)
     *   helper('johndoe')          → Helper scoped to johndoe/SimpleHelperVault
     *   helper('johndoe@dev')      → Helper scoped to johndoe/SimpleHelperVault @ dev branch
     *
     * @param string|null $context Optional user or user@branch context
     * @return \SimpleWire\Helper\Helper
     */
    function helper(?string $context = null): \SimpleWire\Helper\Helper
    {
        return wire()->simplehelper->newHelper($context);
    }
}
