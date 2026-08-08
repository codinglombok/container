<?php

declare(strict_types=1);

namespace LombokClarion\Container;

use LombokClarion\Container\Exceptions\ContainerException;
use LombokClarion\Container\Exceptions\NotFoundException;

interface ContainerInterface
{
    /**
     * @throws NotFoundException if no binding/autowirable class exists for $id
     * @throws ContainerException on any other resolution failure
     */
    public function get(string $id): mixed;

    public function has(string $id): bool;

    /**
     * Bind an already-constructed object for $id, replacing any prior binding
     * for the rest of this container's life.
     *
     * On the interface, not just the concrete classes, because request-scoped
     * values (RequestContext, the resolved Tenant) are bound here per request by
     * Kernel::handle() — and Kernel only ever sees ContainerInterface. Both
     * Container and CompiledContainer already implement this; the interface was
     * simply narrower than reality, which is exactly what let RequestContext be
     * resolved fresh on every get() and silently drop whatever Authenticate had
     * bound into it (AUDIT-TRAIL #35).
     */
    public function instance(string $id, object $instance): void;

    /**
     * True only when an already-constructed object is bound for $id — as opposed
     * to has(), which is also true for anything the container could autowire.
     * The distinction matters for request-scoped values: Kernel::handle() must
     * know whether a RequestContext instance is actually present, not whether one
     * could in principle be built.
     */
    public function hasInstance(string $id): bool;
}
