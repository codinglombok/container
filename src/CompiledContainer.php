<?php

declare(strict_types=1);

namespace LombokClarion\Container;

use LombokClarion\Container\Exceptions\NotFoundException;

/**
 * Zero-reflection, request-time container. This is what actually boots on
 * every request in production (FPM, edge function, or Swoole worker).
 *
 * It is constructed from a flat definition array of the shape:
 *   ['id' => ['shared' => bool, 'factory' => Closure(ContainerInterface): mixed]]
 * which is exactly what ContainerCompiler::compile() writes to
 * services.compiled.php. There is no filesystem scanning, no
 * ReflectionClass, no attribute parsing anywhere in this class — this is
 * what keeps container boot under the ~5ms cold-start budget (master
 * prompt §5).
 */
final class CompiledContainer implements ContainerInterface
{
    /** @var array<string, object> */
    private array $instances = [];

    /**
     * @param array<string, array{shared: bool, factory: callable(ContainerInterface): mixed}> $definitions
     */
    public function __construct(private readonly array $definitions)
    {
    }

    public static function fromFile(string $compiledFilePath): self
    {
        /** @var array<string, array{shared: bool, factory: callable}> $definitions */
        $definitions = require $compiledFilePath;

        return new self($definitions);
    }

    public function instance(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->definitions[$id]);
    }

    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (!isset($this->definitions[$id])) {
            throw NotFoundException::forId($id);
        }

        $definition = $this->definitions[$id];
        $value = ($definition['factory'])($this);

        if ($definition['shared']) {
            $this->instances[$id] = $value;
        }

        return $value;
    }
}
