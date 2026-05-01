<?php

declare(strict_types=1);

namespace SimpleWire\Helper;

/**
 * HelperLoader — dynamic proxy between Helper and loaded helper classes.
 *
 * Supports both static-only utility classes (no constructor args) and
 * instance-based helpers that require initialization via with().
 */
class HelperLoader
{
    protected string $className;

    protected ?object $instance = null;

    protected bool $isStaticOnly = false;

    protected array $constructorArgs = [];

    public function __construct(string $className)
    {
        $this->className = $className;

        $reflection  = new \ReflectionClass($className);
        $constructor = $reflection->getConstructor();

        // No constructor or zero parameters → treat as static-only utility
        $this->isStaticOnly = ($constructor === null || $constructor->getNumberOfParameters() === 0);
    }

    /**
     * Initialize helper with constructor arguments.
     * Arguments are passed positionally in array-value order.
     *
     * @example ->with(['apiKey' => 'sk-xxx', 'model' => 'gpt-4'])
     */
    public function with(array $args): static
    {
        $this->constructorArgs = array_values($args);
        $this->instance        = null;
        $this->isStaticOnly    = false;

        return $this;
    }

    /**
     * Return the underlying helper instance.
     */
    public function getInstance(): object
    {
        if ($this->instance !== null) {
            return $this->instance;
        }

        if ($this->isStaticOnly) {
            throw new \LogicException(
                "Helper '{$this->className}' has no constructor parameters. " .
                "Call methods directly or use with() to force instantiation."
            );
        }

        $reflection     = new \ReflectionClass($this->className);
        $this->instance = $reflection->newInstanceArgs($this->constructorArgs);

        return $this->instance;
    }

    /**
     * Import one or multiple methods as callables.
     *
     * @example import('groupBy')                   → callable
     * @example import(['groupBy', 'flatten'])       → ['groupBy' => callable, ...]
     */
    public function import(string|array $methods): callable|array
    {
        if (is_array($methods)) {
            $result = [];
            foreach ($methods as $method) {
                $result[$method] = $this->resolveCallable($method);
            }
            return $result;
        }

        return $this->resolveCallable($methods);
    }

    /**
     * Magic proxy — call helper methods directly on the loader.
     *
     * @example $arr->flatten([[1, 2], [3, 4]])
     */
    public function __call(string $method, array $arguments): mixed
    {
        // Instance method first
        if (!$this->isStaticOnly) {
            $instance = $this->getInstance();
            if (method_exists($instance, $method)) {
                return $instance->$method(...$arguments);
            }
        }

        // Static fallback
        if (method_exists($this->className, $method)) {
            return $this->className::$method(...$arguments);
        }

        throw new \BadMethodCallException(
            "Method '{$method}' does not exist on helper '{$this->className}'."
        );
    }

    protected function resolveCallable(string $method): callable
    {
        if (!$this->isStaticOnly) {
            $instance = $this->getInstance();
            if (method_exists($instance, $method)) {
                return [$instance, $method];
            }
        }

        if (method_exists($this->className, $method)) {
            return [$this->className, $method];
        }

        throw new \InvalidArgumentException(
            "Cannot import method '{$method}' from helper '{$this->className}'."
        );
    }
}
