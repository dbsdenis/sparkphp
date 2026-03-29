<?php

class Container
{
    private array $bindings   = [];
    private array $singletons = [];
    private array $instances  = [];

    /**
     * Register a binding (new instance each call).
     */
    public function bind(string $abstract, callable $factory): void
    {
        $this->bindings[$abstract] = $factory;
    }

    /**
     * Register a singleton (shared instance).
     */
    public function singleton(string $abstract, callable $factory): void
    {
        $this->singletons[$abstract] = $factory;
    }

    /**
     * Resolve a class/abstract from the container.
     */
    public function make(string $abstract): mixed
    {
        // Already resolved singleton
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // Singleton factory
        if (isset($this->singletons[$abstract])) {
            $this->instances[$abstract] = ($this->singletons[$abstract])($this);
            return $this->instances[$abstract];
        }

        // Regular binding
        if (isset($this->bindings[$abstract])) {
            return ($this->bindings[$abstract])($this);
        }

        // Auto-wire if it's a real class
        if (class_exists($abstract)) {
            return $this->build($abstract);
        }

        throw new \RuntimeException("Container: cannot resolve [{$abstract}]");
    }

    /**
     * Auto-wire a class by reflecting its constructor.
     */
    public function build(string $class): mixed
    {
        $ref = new \ReflectionClass($class);

        if (!$ref->isInstantiable()) {
            throw new \RuntimeException("Container: [{$class}] is not instantiable");
        }

        $constructor = $ref->getConstructor();
        if (!$constructor) {
            return new $class();
        }

        $args = $this->resolveParameters($constructor->getParameters(), []);
        return $ref->newInstanceArgs($args);
    }

    /**
     * Call a callable, resolving its parameters from the container + extras.
     */
    public function call(callable $callable, array $extras = []): mixed
    {
        $ref = $this->reflectCallable($callable);

        $args = $this->resolveParameters($ref->getParameters(), $extras, []);
        return $callable(...$args);
    }

    /**
     * Call a route handler, resolving URL params, model bindings, and services separately.
     */
    public function callRoute(callable $callable, array $routeParams = [], array $extras = []): mixed
    {
        $ref = $this->reflectCallable($callable);

        $args = $this->resolveParameters($ref->getParameters(), $extras, $routeParams);
        return $callable(...$args);
    }

    private function reflectCallable(callable $callable): \ReflectionFunctionAbstract
    {
        return is_array($callable)
            ? new \ReflectionMethod($callable[0], $callable[1])
            : new \ReflectionFunction(\Closure::fromCallable($callable));
    }

    /**
     * Resolve an array of ReflectionParameter values.
     * $extras: ['paramName' => value, ...] — from URL params or explicit overrides.
     */
    private function resolveParameters(array $params, array $extras, array $routeParams = []): array
    {
        $args = [];
        $namedRouteParams = $this->namedRouteParams($routeParams);

        foreach ($params as $param) {
            $name = $param->getName();
            $type = $param->getType();
            $className = $this->parameterClassName($type);

            // Explicit extra by name
            if (array_key_exists($name, $extras)) {
                $args[] = $extras[$name];
                continue;
            }

            // Route model binding: URL param -> Model::resolveRouteBinding()
            if ($className !== null && is_subclass_of($className, Model::class)) {
                [$foundBinding, $bindingValue] = $this->findRouteModelBindingValue($name, $className, $namedRouteParams);
                if ($foundBinding) {
                    $args[] = $className::resolveRouteBinding($bindingValue);
                    continue;
                }
            }

            // Primitive or raw route param by name
            [$foundRouteParam, $routeValue] = $this->findNamedRouteParameter($name, $namedRouteParams);
            if ($foundRouteParam) {
                $args[] = ($type && $type->isBuiltin())
                    ? $this->castPrimitive($routeValue, $type->getName())
                    : $routeValue;
                continue;
            }

            // Typed class parameter → resolve from container
            if ($className !== null) {
                try {
                    $args[] = $this->make($className);
                    continue;
                } catch (\RuntimeException) {}
            }

            // Primitive type with matching URL param name → cast and inject
            if ($type && $type->isBuiltin()) {
                $inputVal = input($name) ?? query($name);
                if ($inputVal !== null) {
                    $args[] = $this->castPrimitive($inputVal, $type->getName());
                    continue;
                }
            }

            // Default value
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            // Nullable
            if ($param->allowsNull()) {
                $args[] = null;
                continue;
            }

            throw new \RuntimeException("Container: cannot resolve parameter \${$name}");
        }
        return $args;
    }

    /**
     * @return array<string, mixed>
     */
    private function namedRouteParams(array $routeParams): array
    {
        return array_filter(
            $routeParams,
            static fn(mixed $key): bool => is_string($key),
            ARRAY_FILTER_USE_KEY
        );
    }

    private function parameterClassName(?\ReflectionType $type): ?string
    {
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        return $type->getName();
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function findNamedRouteParameter(string $parameterName, array $routeParams): array
    {
        return $this->matchRouteParamCandidates($routeParams, [
            $parameterName,
            $this->toSnake($parameterName),
        ]);
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function findRouteModelBindingValue(string $parameterName, string $modelClass, array $routeParams): array
    {
        $modelBase = (new \ReflectionClass($modelClass))->getShortName();
        $modelBase = lcfirst($modelBase);
        $modelSnake = $this->toSnake($modelBase);
        $parameterSnake = $this->toSnake($parameterName);

        $candidates = [
            $parameterName,
            $parameterSnake,
            $parameterName . 'Id',
            $parameterSnake . '_id',
            $modelBase,
            $modelSnake,
            $modelBase . 'Id',
            $modelSnake . '_id',
        ];

        if (array_key_exists('id', $routeParams)) {
            $candidates[] = 'id';
        }

        if (count($routeParams) === 1) {
            $candidates[] = array_key_first($routeParams);
        }

        return $this->matchRouteParamCandidates($routeParams, array_values(array_unique(array_filter($candidates))));
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function matchRouteParamCandidates(array $routeParams, array $candidates): array
    {
        foreach ($candidates as $candidate) {
            if (array_key_exists($candidate, $routeParams)) {
                return [true, $routeParams[$candidate]];
            }
        }

        $normalized = [];
        foreach ($routeParams as $key => $value) {
            $normalized[$this->normalizeRouteParamName((string) $key)] = $value;
        }

        foreach ($candidates as $candidate) {
            $normalizedCandidate = $this->normalizeRouteParamName((string) $candidate);
            if (array_key_exists($normalizedCandidate, $normalized)) {
                return [true, $normalized[$normalizedCandidate]];
            }
        }

        return [false, null];
    }

    private function toSnake(string $value): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $value) ?? $value);
    }

    private function normalizeRouteParamName(string $value): string
    {
        return strtolower(str_replace(['-', '_'], '', $value));
    }

    private function castPrimitive(mixed $value, string $type): mixed
    {
        return match ($type) {
            'int'    => (int) $value,
            'float'  => (float) $value,
            'bool'   => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'string' => (string) $value,
            default  => $value,
        };
    }

    /**
     * Check if a binding is registered.
     */
    public function has(string $abstract): bool
    {
        return isset($this->instances[$abstract])
            || isset($this->singletons[$abstract])
            || isset($this->bindings[$abstract]);
    }
}
