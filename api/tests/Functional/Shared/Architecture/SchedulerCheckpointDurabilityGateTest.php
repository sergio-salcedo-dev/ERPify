<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Architecture;

use Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance\IdentityMaintenanceSchedule;
use Erpify\Shared\Audit\Infrastructure\Messenger\Maintenance\AuditLogMaintenanceSchedule;
use Erpify\Shared\Event\Infrastructure\Messenger\Maintenance\HandledDomainEventMaintenanceSchedule;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Gate over the property that makes a period longer than a release mean anything: the scheduler checkpoint
 * survives the container being REPLACED, not merely restarted.
 *
 * `stateful()` already has a witness — each schedule's unit test asserts `getState()` is not null — and that
 * witness is satisfied by any pool at all, which is how the checkpoint sat on the `app` filesystem pool while
 * every gate stayed green. That pool lives under `var/cache`, no production service mounts a volume over it,
 * and a deploy recreates the container: `Checkpoint::acquire()` then seeds the checkpoint to `now` and
 * `PeriodicalTrigger` anchors the period there, because `'1 day'` does not match its
 * `canBeConvertedToSeconds()` regex and takes the `DatePeriod` branch. The first run therefore lands a full
 * day after each deploy, and at a deploy cadence of a day or less it never lands at all — measured over seven
 * simulated days as 0 daily fires against 7 expected, at cadences of 6 h, 12 h and 24 h alike.
 *
 * Two halves, and neither alone is the property: a durable pool nobody uses proves nothing, and a schedule
 * pointed at a pool that is not durable is the defect itself.
 *
 * **What a green does not prove:** that any tick ever ran, that the worker consuming the transport is alive
 * (`make php.lint.schedule-consumption` proves the name is wired and explicitly not that), or that the store
 * is reachable at boot. It proves where the checkpoint is written, which is the half that was wrong.
 *
 * @internal
 */
#[CoversNothing]
final class SchedulerCheckpointDurabilityGateTest extends KernelTestCase
{
    private const string POOL = 'cache.scheduler_checkpoint';

    /**
     * Adapters whose store is reachable from a container that did not write it. The filesystem, array and
     * APCu adapters are absent deliberately: each is scoped to the process or to the writable layer that a
     * deploy discards, which is the whole defect.
     */
    private const array DURABLE_ADAPTERS = [
        'cache.adapter.doctrine_dbal',
        'cache.adapter.pdo',
        'cache.adapter.redis',
    ];

    private const string RULE = 'A scheduler checkpoint on a pool the deploy discards re-anchors every '
        . 'period at the deploy instant. A period at or below the deploy cadence then never comes due, '
        . 'silently, with every other gate green. Keep the checkpoint on `' . self::POOL . '`.';

    #[Test]
    public function everyScheduleTakesItsCheckpointFromTheDurablePool(): void
    {
        $providers = $this->scheduleProviders();

        // A walk that reaches nothing passes vacuously and is indistinguishable from a healthy tree.
        // `assertNotEmpty` is too weak at this population size — dropping one of three would satisfy it —
        // so the set is pinned and a new schedule costs a deliberate edit here.
        $this->assertSame(
            [
                IdentityMaintenanceSchedule::class,
                AuditLogMaintenanceSchedule::class,
                HandledDomainEventMaintenanceSchedule::class,
            ],
            $providers,
            self::RULE . PHP_EOL . 'The set of #[AsSchedule] providers under src/ changed. If that is '
                . 'deliberate, update this list; if the walk stopped reaching one, fix the walk.',
        );

        self::bootKernel();
        $container = self::getContainer();

        $durablePool = $container->get(self::POOL);
        $this->assertInstanceOf(CacheInterface::class, $durablePool);

        $strayed = [];

        foreach ($providers as $provider) {
            $service = $container->get($provider);
            $this->assertInstanceOf(ScheduleProviderInterface::class, $service);

            $state = $service->getSchedule()->getState();

            if ($state !== $durablePool) {
                $strayed[] = \sprintf(
                    '%s -> %s',
                    $provider,
                    $state instanceof CacheInterface
                        ? \sprintf('%s (not the %s service)', $state::class, self::POOL)
                        : 'no state at all',
                );
            }
        }

        $this->assertSame(
            [],
            $strayed,
            self::RULE . PHP_EOL . 'Schedules whose checkpoint is not on the durable pool:' . PHP_EOL
                . \implode(PHP_EOL, $strayed),
        );
    }

    /**
     * The other half. Read from configuration rather than from the built pool: the adapter arrives wrapped
     * (traceable in dev/test, tag-aware where tags are used), so the instance says less about durability
     * than the declaration that produced it.
     */
    #[Test]
    public function thePoolIsBackedByAStoreThatOutlivesTheContainer(): void
    {
        $configFile = \dirname(__DIR__, 4) . '/config/packages/cache.yaml';
        $this->assertFileExists($configFile);

        $parsed = Yaml::parseFile($configFile);
        $this->assertIsArray($parsed);

        $framework = $parsed['framework'] ?? null;
        $this->assertIsArray($framework, 'cache.yaml declares no framework block.');

        $cache = $framework['cache'] ?? null;
        $this->assertIsArray($cache, 'cache.yaml declares no framework.cache block.');

        $pools = $cache['pools'] ?? null;
        $this->assertIsArray($pools, \sprintf('cache.yaml declares no pools, so `%s` cannot exist.', self::POOL));

        $pool = $pools[self::POOL] ?? null;
        $this->assertIsArray($pool, \sprintf(
            '%s%sNo `%s` pool in cache.yaml. Without its own pool the checkpoint falls back to `app`, which '
            . 'is the filesystem adapter under var/cache and dies with the container.',
            self::RULE,
            PHP_EOL,
            self::POOL,
        ));

        $this->assertContains($pool['adapter'] ?? null, self::DURABLE_ADAPTERS, \sprintf(
            '%s%sThe `%s` pool is not on a durable adapter. Durable here means "readable from a container '
            . 'that did not write it": %s. Postgres is the only one this stack actually deploys.',
            self::RULE,
            PHP_EOL,
            self::POOL,
            \implode(', ', self::DURABLE_ADAPTERS),
        ));
    }

    /**
     * @return list<class-string<ScheduleProviderInterface>>
     */
    private function scheduleProviders(): array
    {
        $sourceRoot = \dirname(__DIR__, 4) . '/src';
        $prefix = \strlen($sourceRoot) + 1;
        $found = [];

        $tree = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($tree as $file) {
            // Unreachable at runtime — the iterator only ever yields SplFileInfo — but the iterator is
            // untyped, so PHPStan rejects the calls below without it.
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            if ('php' !== $file->getExtension()) {
                continue;
            }

            $relative = \substr($file->getPathname(), $prefix);
            /** @var class-string $class */
            $class = 'Erpify\\' . \str_replace(['/', '.php'], ['\\', ''], $relative);

            if (!\class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ([] === $reflection->getAttributes(AsSchedule::class)) {
                continue;
            }

            if (!$reflection->implementsInterface(ScheduleProviderInterface::class)) {
                continue;
            }

            /** @var class-string<ScheduleProviderInterface> $class */
            $found[] = $class;
        }

        \sort($found);

        return $found;
    }
}
