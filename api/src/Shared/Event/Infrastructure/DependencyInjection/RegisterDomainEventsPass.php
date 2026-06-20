<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\DependencyInjection;

use Erpify\Shared\Event\Domain\DomainEvent;
use Erpify\Shared\Event\Infrastructure\Mapper\ReflectionDomainEventMapper;
use LogicException;
use Override;
use ReflectionClass;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Finder\Finder;

/**
 * Discovers every concrete {@see DomainEvent} under `src/` at compile time and feeds the
 * `(eventName, eventVersion) ⇒ class` / `⇒ aggregateType` tables into {@see ReflectionDomainEventMapper}.
 * The scan is the single registry of event identities — it **fails the build on an `eventName`
 * collision**, so two events can never claim the same key (the stable key the store reads by).
 */
final class RegisterDomainEventsPass implements CompilerPassInterface
{
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ReflectionDomainEventMapper::class)) {
            return;
        }

        $projectDir = $container->getParameter('kernel.project_dir');
        \assert(\is_string($projectDir));

        $classByKey = [];
        $aggregateTypeByKey = [];

        foreach ($this->discover($projectDir . '/src') as $eventClass) {
            $key = $eventClass::eventName() . ':' . $eventClass::eventVersion();

            if (isset($classByKey[$key])) {
                throw new LogicException(\sprintf(
                    'Domain event name collision on "%s" (v%d): declared by both %s and %s.',
                    $eventClass::eventName(),
                    $eventClass::eventVersion(),
                    $classByKey[$key],
                    $eventClass,
                ));
            }

            $classByKey[$key] = $eventClass;
            $aggregateTypeByKey[$key] = $eventClass::aggregateType();
        }

        $definition = $container->getDefinition(ReflectionDomainEventMapper::class);
        $definition->setArgument('$classByKey', $classByKey);
        $definition->setArgument('$aggregateTypeByKey', $aggregateTypeByKey);

        // Resources so a new or changed event file invalidates the compiled container in dev.
        $container->addResource(new DirectoryResource($projectDir . '/src', '/\.php$/'));
    }

    /**
     * @return iterable<class-string<DomainEvent>>
     */
    private function discover(string $srcDir): iterable
    {
        $finder = (new Finder())->files()->in($srcDir)->name('*.php');

        foreach ($finder as $file) {
            $class = 'Erpify\\' . \str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (!\class_exists($class)) {
                continue;
            }

            if (!\is_a($class, DomainEvent::class, true)) {
                continue;
            }

            if ((new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            yield $class;
        }
    }
}
