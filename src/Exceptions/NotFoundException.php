<?php

declare(strict_types=1);

namespace LombokClarion\Container\Exceptions;

/**
 * Thrown when an interface, abstract class, or unbound identifier is
 * requested. LombokClarion never auto-wires interfaces — every interface
 * must have an explicit binding registered in bootstrap/services.php.
 */
class NotFoundException extends ContainerException
{
    public static function forId(string $id): self
    {
        return new self(sprintf(
            'No binding registered for "%s". LombokClarion never auto-wires ' .
            'interfaces or abstract classes — add an explicit binding in ' .
            'bootstrap/services.php (e.g. $services->bind(%s::class, ...)).',
            $id,
            $id
        ));
    }
}
