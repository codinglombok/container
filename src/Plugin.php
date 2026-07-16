<?php

declare(strict_types=1);

namespace LombokClarion\Container;

/**
 * Plugin contract (§10 — "optional magic").
 *
 * A plugin is the ONLY sanctioned way for a package to add bindings,
 * routes, or commands to an app — and it is always registered by hand:
 *
 *   // bootstrap/services.php
 *   $plugins = new PluginRegistrar($container);
 *   $plugins->register(new \Vendor\Analytics\AnalyticsPlugin());
 *
 * There is no composer-extra scanning, no vendor-dir sweep, no
 * "package auto-registers itself on install" (§2.5). If it isn't listed
 * in services.php, it isn't installed — grep still tells the whole truth.
 *
 * Plugins declare what they touch via capabilities(), and the registrar
 * can be constructed with an allow-list so an app can state, in code,
 * "plugins may add commands but never routes".
 */
interface Plugin
{
    public function name(): string;

    /**
     * What this plugin does to the app. Must be a subset of:
     * 'bindings', 'routes', 'commands'.
     *
     * @return list<'bindings'|'routes'|'commands'>
     */
    public function capabilities(): array;

    /**
     * Called once at registration. The plugin receives the container and
     * performs its explicit bind()/singleton() calls here.
     */
    public function register(Container $container): void;
}
