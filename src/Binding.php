<?php

declare(strict_types=1);

namespace LombokClarion\Container;

/**
 * A recorded binding definition. Kept as data (not just a resolved value)
 * so that ContainerCompiler can inspect it ahead-of-time and bake a
 * reflection-free factory for services.compiled.php.
 */
final class Binding
{
    /**
     * @param 'class'|'closure' $kind
     * @param class-string|callable $concrete
     */
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly mixed $concrete,
        public readonly bool $shared,
    ) {
    }
}
