<?php

declare(strict_types=1);

namespace LombokClarion\Container;

use LombokClarion\Container\Exceptions\ContainerException;
use LombokClarion\Container\Exceptions\NotFoundException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;

/**
 * The development-time container.
 *
 * Design rules (see master prompt §2, §4.1):
 *  - No auto-discovery. Interfaces/abstract classes MUST be bound explicitly
 *    via bind()/singleton()/instance(). There is no "scan the filesystem and
 *    guess the implementation" behaviour anywhere in this class.
 *  - Concrete, instantiable classes MAY be resolved by reflecting their
 *    constructor ("autowiring"), but only when every parameter is itself
 *    either: a type-hinted class/interface the container can resolve, or a
 *    parameter with a default value. A scalar/union parameter with no
 *    default is a hard error — the developer must bind it explicitly.
 *  - This class uses reflection freely; it is NOT what runs in production.
 *    `lombokclarion optimize` runs ContainerCompiler against an instance of
 *    this class to bake a reflection-free CompiledContainer for request-time
 *    use (see §5, cold-start budget).
 */
final class Container implements ContainerInterface
{
    /** @var array<string, Binding> */
    private array $bindings = [];

    /** @var array<string, object> */
    private array $instances = [];

    /** @var list<string> resolution stack, used to detect circular deps */
    private array $resolving = [];

    /**
     * @param class-string|callable $concrete
     */
    public function bind(string $id, string|callable $concrete, bool $shared = false): void
    {
        $kind = is_string($concrete) ? 'class' : 'closure';
        $this->bindings[$id] = new Binding($id, $kind, $concrete, $shared);
        unset($this->instances[$id]);
    }

    /**
     * @param class-string|callable $concrete
     */
    public function singleton(string $id, string|callable $concrete): void
    {
        $this->bind($id, $concrete, shared: true);
    }

    public function instance(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
        unset($this->bindings[$id]);
    }

    public function has(string $id): bool
    {
        if (isset($this->instances[$id]) || isset($this->bindings[$id])) {
            return true;
        }

        return class_exists($id) && (new ReflectionClass($id))->isInstantiable();
    }

    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (in_array($id, $this->resolving, true)) {
            throw new ContainerException(sprintf(
                'Circular dependency detected while resolving "%s": %s -> %s',
                $id,
                implode(' -> ', $this->resolving),
                $id
            ));
        }

        $this->resolving[] = $id;

        try {
            return $this->resolveUncached($id);
        } finally {
            array_pop($this->resolving);
        }
    }

    /** @return array<string, Binding> */
    public function bindings(): array
    {
        return $this->bindings;
    }

    private function resolveUncached(string $id): mixed
    {
        if (isset($this->bindings[$id])) {
            $binding = $this->bindings[$id];
            $value = $binding->kind === 'closure'
                ? ($binding->concrete)($this)
                : $this->autowire($binding->concrete);

            if ($binding->shared) {
                $this->instances[$id] = $value;
            }

            return $value;
        }

        if (interface_exists($id) || (class_exists($id) && (new ReflectionClass($id))->isAbstract())) {
            throw NotFoundException::forId($id);
        }

        if (!class_exists($id)) {
            throw NotFoundException::forId($id);
        }

        return $this->autowire($id);
    }

    /**
     * @param class-string $class
     */
    private function autowire(string $class): object
    {
        try {
            $reflection = new ReflectionClass($class);
        } catch (Throwable $e) {
            throw new ContainerException("Cannot reflect class \"$class\": {$e->getMessage()}", previous: $e);
        }

        if (!$reflection->isInstantiable()) {
            throw new ContainerException(
                "\"$class\" is not instantiable (interface/abstract). Bind it explicitly."
            );
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return new $class();
        }

        $args = array_map(
            fn (ReflectionParameter $param) => $this->resolveParameter($class, $param),
            $constructor->getParameters()
        );

        return $reflection->newInstanceArgs($args);
    }

    private function resolveParameter(string $ownerClass, ReflectionParameter $param): mixed
    {
        $type = $param->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            /** @var class-string $typeName */
            $typeName = $type->getName();

            try {
                return $this->get($typeName);
            } catch (NotFoundException $e) {
                if ($param->isDefaultValueAvailable()) {
                    return $param->getDefaultValue();
                }
                if ($type->allowsNull()) {
                    return null;
                }
                throw $e;
            }
        }

        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        if ($type !== null && $type->allowsNull()) {
            return null;
        }

        throw new ContainerException(sprintf(
            'Cannot autowire "%s": constructor parameter "$%s" of %s::__construct() has no ' .
            'class type hint and no default value. Bind %s explicitly (e.g. via bind() with a ' .
            'closure that supplies this scalar), or give it a default value.',
            $ownerClass,
            $param->getName(),
            $ownerClass,
            $ownerClass
        ));
    }
}
