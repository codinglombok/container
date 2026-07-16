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
}
