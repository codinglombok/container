<?php

declare(strict_types=1);

namespace LombokClarion\Container;

use LombokClarion\Container\Exceptions\ContainerException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Runs at `lombokclarion optimize` time (build/deploy step), never at
 * request time. It performs ALL reflection up front and emits a plain PHP
 * array of closures (services.compiled.php) that CompiledContainer loads
 * with zero reflection, zero filesystem scanning — satisfying the §5
 * cold-start budget.
 *
 * Contract for closure bindings: a binding registered with a real Closure
 * cannot be serialized into a static file, so this compiler requires
 * closure bindings to be given as an array callable `[FactoryClass::class,
 * 'method']`. This is enforced at compile time with a clear error — it is
 * an explicit constraint, not a silent fallback, in keeping with
 * "explicit over magic" (master prompt §2.6).
 *
 * Limitation (by design, not oversight): the compiler discovers transitive
 * dependencies by reflecting constructor signatures. It cannot see inside
 * a factory method's body, so anything a closure/array-callable factory
 * pulls out of the container via $c->get(...) must ALSO have its own
 * explicit binding registered in services.php — it will not be picked up
 * automatically just because a factory happens to reference it.
 */
final class ContainerCompiler
{
    /**
     * @param list<class-string> $rootIds extra classes to compile even if
     *        not explicitly bound (e.g. controller classes referenced only
     *        from the route table)
     * @param list<class-string> $externallyProvided ids that are supplied
     *        via CompiledContainer::instance() at request boot rather than
     *        compiled here — e.g. a per-request PDO connection, which
     *        cannot be serialized into a static file and, per §5, should
     *        not be assumed to be a persistent pooled connection anyway
     */
    public function compile(Container $dev, array $rootIds = [], array $externallyProvided = []): string
    {
        $bindings = $dev->bindings();
        $queue = array_values(array_unique([...array_keys($bindings), ...$rootIds]));
        $compiled = [];
        $skip = array_flip($externallyProvided);

        while ($queue !== []) {
            $id = array_shift($queue);

            if (isset($compiled[$id]) || isset($skip[$id])) {
                continue;
            }

            if (isset($bindings[$id])) {
                $binding = $bindings[$id];

                if ($binding->kind === 'closure') {
                    $compiled[$id] = [
                        'shared' => $binding->shared,
                        'source' => $this->compileClosureBinding($id, $binding->concrete),
                        'deps' => [],
                    ];
                    continue;
                }

                [$source, $deps] = $this->compileClassFactory($binding->concrete);
                $compiled[$id] = ['shared' => $binding->shared, 'source' => $source, 'deps' => $deps];
                foreach ($deps as $dep) {
                    if (!isset($compiled[$dep]) && !isset($skip[$dep])) {
                        $queue[] = $dep;
                    }
                }
                continue;
            }

            if (!class_exists($id)) {
                // Unbound interface / unknown id: left uncompiled on purpose.
                // Requesting it from CompiledContainer will correctly throw
                // NotFoundException, matching Container's own behaviour.
                continue;
            }

            $reflection = new ReflectionClass($id);
            if (!$reflection->isInstantiable()) {
                continue;
            }

            [$source, $deps] = $this->compileClassFactory($id);
            $compiled[$id] = ['shared' => false, 'source' => $source, 'deps' => $deps];
            foreach ($deps as $dep) {
                if (!isset($compiled[$dep]) && !isset($skip[$dep])) {
                    $queue[] = $dep;
                }
            }
        }

        return $this->render($compiled);
    }

    public function compileToFile(Container $dev, string $outputPath, array $rootIds = [], array $externallyProvided = []): void
    {
        $source = $this->compile($dev, $rootIds, $externallyProvided);
        $tmp = $outputPath . '.tmp';
        file_put_contents($tmp, $source);
        rename($tmp, $outputPath);
    }

    /**
     * @param callable|array{0: class-string, 1: string} $concrete
     */
    private function compileClosureBinding(string $id, $concrete): string
    {
        if (!is_array($concrete) || !isset($concrete[0], $concrete[1]) || !is_string($concrete[0]) || !is_string($concrete[1])) {
            throw new ContainerException(sprintf(
                'Cannot compile binding "%s": closure bindings must be registered as an array ' .
                'callable [FactoryClass::class, \'method\'] so they can be resolved statically. ' .
                'Raw Closure objects cannot be compiled into services.compiled.php.',
                $id
            ));
        }

        [$class, $method] = $concrete;

        return sprintf('\\%s::%s($c)', ltrim($class, '\\'), $method);
    }

    /**
     * @param class-string $class
     * @return array{0: string, 1: list<class-string>}
     */
    private function compileClassFactory(string $class): array
    {
        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new ContainerException("Cannot compile \"$class\": not instantiable.");
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return ["new \\" . ltrim($class, '\\') . "()", []];
        }

        $args = [];
        $deps = [];

        foreach ($constructor->getParameters() as $param) {
            [$argSource, $dep] = $this->compileParameter($class, $param);
            $args[] = $argSource;
            if ($dep !== null) {
                $deps[] = $dep;
            }
        }

        $source = "new \\" . ltrim($class, '\\') . "(" . implode(', ', $args) . ")";

        return [$source, $deps];
    }

    /**
     * @return array{0: string, 1: class-string|null}
     */
    private function compileParameter(string $ownerClass, ReflectionParameter $param): array
    {
        $type = $param->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            /** @var class-string $typeName */
            $typeName = $type->getName();

            return [sprintf("\$c->get('%s')", addslashes($typeName)), $typeName];
        }

        if ($param->isDefaultValueAvailable()) {
            return [var_export($param->getDefaultValue(), true), null];
        }

        if ($type !== null && $type->allowsNull()) {
            return ['null', null];
        }

        throw new ContainerException(sprintf(
            'Cannot compile "%s": constructor parameter "$%s" has no class type and no default ' .
            'value. Bind it explicitly or give it a default.',
            $ownerClass,
            $param->getName()
        ));
    }

    /**
     * @param array<string, array{shared: bool, source: string, deps: list<string>}> $compiled
     */
    private function render(array $compiled): string
    {
        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = 'declare(strict_types=1);';
        $lines[] = '';
        $lines[] = '// GENERATED FILE — do not edit by hand.';
        $lines[] = '// Produced by LombokClarion\\Container\\ContainerCompiler at `lombokclarion optimize` time.';
        $lines[] = '// Loaded by CompiledContainer::fromFile() with zero reflection at request time.';
        $lines[] = '';
        $lines[] = 'use LombokClarion\\Container\\ContainerInterface;';
        $lines[] = '';
        $lines[] = 'return [';

        foreach ($compiled as $id => $def) {
            $shared = $def['shared'] ? 'true' : 'false';
            $idLiteral = var_export($id, true);
            $lines[] = "    {$idLiteral} => [";
            $lines[] = "        'shared' => {$shared},";
            $lines[] = "        'factory' => static function (ContainerInterface \$c) { return {$def['source']}; },";
            $lines[] = "    ],";
        }

        $lines[] = '];';
        $lines[] = '';

        return implode("\n", $lines);
    }
}
