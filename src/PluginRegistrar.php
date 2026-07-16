<?php

declare(strict_types=1);

namespace LombokClarion\Container;

use LombokClarion\Container\Exceptions\ContainerException;

final class PluginRegistrar
{
    /** @var list<string> */
    private array $registered = [];

    /**
     * @param list<'bindings'|'routes'|'commands'>|null $allowedCapabilities
     *        null = allow all. An app can restrict, e.g. ['bindings'] to
     *        forbid plugins from touching routes/commands.
     */
    public function __construct(
        private readonly Container $container,
        private readonly ?array $allowedCapabilities = null,
    ) {
    }

    public function register(Plugin $plugin): void
    {
        if (in_array($plugin->name(), $this->registered, true)) {
            throw new ContainerException("Plugin \"{$plugin->name()}\" is already registered.");
        }

        if ($this->allowedCapabilities !== null) {
            $illegal = array_diff($plugin->capabilities(), $this->allowedCapabilities);
            if ($illegal !== []) {
                throw new ContainerException(sprintf(
                    'Plugin "%s" declares capabilities [%s] but this app only allows [%s]. ' .
                    'Widen the allow-list in services.php explicitly if this is intended.',
                    $plugin->name(),
                    implode(', ', $illegal),
                    implode(', ', $this->allowedCapabilities)
                ));
            }
        }

        $plugin->register($this->container);
        $this->registered[] = $plugin->name();
    }

    /** @return list<string> */
    public function registeredPlugins(): array
    {
        return $this->registered;
    }
}
