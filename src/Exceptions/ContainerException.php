<?php

declare(strict_types=1);

namespace LombokClarion\Container\Exceptions;

use RuntimeException;

/**
 * Thrown when the container cannot resolve a dependency: a scalar/union
 * constructor parameter has no explicit binding, a class is not
 * instantiable, or a circular dependency is detected.
 */
class ContainerException extends RuntimeException
{
}
