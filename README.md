# lombokclarion/container

**Compile-checked DI container — zero reflection at request time once compiled.**

> **[READ-ONLY]** This is a subtree split of the [LombokClarion](https://github.com/codinglombok/LombokClarion) monorepo.  
> Do not send pull requests here — contribute to the [main repository](https://github.com/codinglombok/LombokClarion) instead.

## Install

```bash
composer require lombokclarion/container
```

## Namespace

```php
LombokClarion\Container
```

## What's Inside

| Class | Role |
|-------|------|
| `Container` | Runtime container: `bind()`, `singleton()`, `instance()`, `get()`, `has()` |
| `ContainerCompiler` | Compiles a Container → flat PHP file (zero reflection) |
| `CompiledContainer` | Loads the compiled file; same `ContainerInterface` |
| `ContainerInterface` | `get(id)` / `has(id)` — PSR-11-compatible contract |
| `Binding` | Value object wrapping a single binding definition |
| `Plugin` | Plugin interface: `name()`, `capabilities()`, `register()` |
| `PluginRegistrar` | Registers plugins with a capability allow-list; rejects duplicates |

## Usage

```php
use LombokClarion\Container\Container;

$c = new Container();

// Interface → concrete
$c->bind(LoggerInterface::class, FileLogger::class);

// Shared instance (resolved once)
$c->singleton(PDO::class, fn () => new PDO('sqlite::memory:'));

// Pre-built instance
$c->instance('config', $configArray);

// Resolve
$logger = $c->get(LoggerInterface::class);
```

### AOT Compilation

```php
use LombokClarion\Container\ContainerCompiler;

$compiler = new ContainerCompiler();
$compiler->compile($container, '/path/to/compiled.php', [
    // IDs the compiled container must be able to resolve
    Controller::class,
    Middleware::class,
], externallyProvided: [PDO::class]); // non-serializable handles

// Boot from compiled (zero reflection):
$compiled = require '/path/to/compiled.php';
$compiled->instance(PDO::class, $pdo); // bind externals
$controller = $compiled->get(Controller::class);
```

## License

Apache-2.0 — see [LICENSE](https://github.com/codinglombok/LombokClarion/blob/main/LICENSE) in the main repository.
