<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every container's log sink is bounded, and the bound is committed to the repository.
 *
 * Monolog's prod `nested` handler writes to `php://stderr`, so a flushed `fingers_crossed` buffer leaves the
 * application and becomes a container log. With no `logging:` block Docker applies the json-file driver with
 * NO rotation and NO limit, which is the state every compose file here was in: a record that reached that
 * sink outlived every other retention control this application has — the `audit_log` prune, the
 * `messenger_messages` prune, the GDPR erasure use cases — all of which have owners, while this had none.
 *
 * **What this gate proves is the declaration, and the declaration is the half that can drift silently.** It
 * does not prove the deployed daemon honours it (a `daemon.json` default is overridden by the block, not the
 * other way round, but nothing here reads the host), and it does not prove anything about a container started
 * outside these files. Nor does it make the sink erasable: rotation evicts by VOLUME, so a busy deployment
 * keeps a bounded window of hours while an IDLE one keeps its oldest line indefinitely. There is still no TTL
 * and no erasure path, which is recorded as a residual in `PRODUCTION_SECURITY_CHECKLIST.md` §7 rather than
 * implied closed by the presence of this gate.
 *
 * **Merge semantics are read rather than assumed.** Compose merges SERVICE maps across files, so a service an
 * overlay merely extends inherits `compose.yaml`'s block untouched and does not need to respell it — while a
 * service an overlay is the first to INTRODUCE (`mailpit` in dev, `scheduler_worker` in prod) has no base to
 * inherit from and would ship unbounded. Checking each file in isolation would demand redundant blocks;
 * checking only `compose.yaml` would miss both of those. This resolves the way Compose does.
 *
 * @internal
 */
#[CoversNothing]
final class BoundedContainerLogRetentionGateTest extends TestCase
{
    /**
     * The base every environment layers on, and the two overlays `make/config.mk` applies over it.
     */
    private const string BASE_FILE = 'compose.yaml';

    private const array COMPOSE_FILES = ['compose.yaml', 'compose.dev.yaml', 'compose.prod.yaml'];

    /**
     * The bound, spelled once here and compared verbatim.
     *
     * Asserted as the WHOLE block rather than as "some rotation is configured", for the reason
     * `deploy.replicas` is asserted as a literal on `scheduler_worker`: a retention control loosened by one
     * option is indistinguishable from one that was never tightened, and a gate that accepts any bounded-ish
     * shape turns a deliberate widening into a silent one. Changing the numbers is meant to cost a diff here.
     *
     * @var array<string, mixed>
     */
    private const array EXPECTED_BOUND = [
        'driver' => 'json-file',
        'options' => [
            'max-size' => '10m',
            'max-file' => '5',
        ],
    ];

    /**
     * The services the deployment actually runs, pinned so the sweep cannot go vacuous.
     *
     * Without this, a file that stopped declaring services — a rename, a restructure, a parse that silently
     * yielded an empty map — would satisfy every per-service assertion below by having nothing to check, and
     * report green over a stack with no bound at all.
     *
     * @var array<string, list<string>>
     */
    private const array EXPECTED_SERVICES = [
        'compose.yaml' => ['php', 'database', 'pwa', 'messenger_worker'],
        'compose.dev.yaml' => ['mailpit', 'php', 'pwa', 'database', 'messenger_worker'],
        'compose.prod.yaml' => ['php', 'pwa', 'database', 'messenger_worker', 'scheduler_worker'],
    ];

    #[Test]
    #[DataProvider('composeFiles')]
    public function everyServiceItDeclaresResolvesToTheBound(string $composeFile): void
    {
        $services = $this->servicesIn($composeFile);
        $base = self::BASE_FILE === $composeFile ? [] : $this->servicesIn(self::BASE_FILE);

        foreach ($services as $name => $definition) {
            // A service key with no map under it parses to null, which would otherwise reach the
            // null-coalescing chain below and read as "inherits the base" rather than as the malformed
            // file it is.
            $this->assertIsArray(
                $definition,
                \sprintf('service "%s" in "%s" has no definition to read', $name, $composeFile),
            );

            $inherited = $base[$name] ?? [];
            $this->assertIsArray($inherited);

            $logging = $definition['logging'] ?? $inherited['logging'] ?? null;

            $this->assertSame(
                self::EXPECTED_BOUND,
                $logging,
                \sprintf(
                    'Service "%s" resolves to no bounded log retention in "%s". Docker\'s default json-file '
                    . 'driver keeps every line for ever, so this service\'s stderr — where Monolog\'s prod '
                    . 'handler flushes its buffer — becomes an unbounded sink with no owner.',
                    $name,
                    $composeFile,
                ),
            );
        }
    }

    /**
     * A bound the environment supplies is a bound the deployment can remove without touching the repository,
     * which is the same reason `ScheduleConsumptionGateTest` refuses an interpolated replica count: it looks
     * pinned while the real value sits in an env file no gate opens.
     */
    #[Test]
    #[DataProvider('composeFiles')]
    public function theBoundIsNotSuppliedByTheEnvironment(string $composeFile): void
    {
        $declared = $this->declaredBoundsIn($composeFile);

        $this->assertNotEmpty(
            $declared,
            \sprintf('"%s" declares no logging block at all, so there is nothing to read.', $composeFile),
        );

        foreach ($declared as $service => $logging) {
            foreach ($this->scalarsOf($logging) as $path => $value) {
                $this->assertStringNotContainsString(
                    '${',
                    $value,
                    \sprintf(
                        '"%s" lets the environment supply "%s" on service "%s". A retention bound that an '
                        . 'env file can widen is not a bound.',
                        $composeFile,
                        $path,
                        $service,
                    ),
                );
            }
        }
    }

    /**
     * The anchor cannot cross files, so each overlay respells the bound for the services it introduces — and
     * a value respelled by hand is a value that drifts. Three files quietly disagreeing would mean the
     * environment a record actually lands in decides how long it is kept.
     */
    #[Test]
    public function theThreeFilesAgreeOnTheBound(): void
    {
        foreach (self::COMPOSE_FILES as $composeFile) {
            foreach ($this->declaredBoundsIn($composeFile) as $service => $logging) {
                $this->assertSame(
                    self::EXPECTED_BOUND,
                    $logging,
                    \sprintf('"%s" gives service "%s" a different bound.', $composeFile, $service),
                );
            }
        }
    }

    /**
     * @see EXPECTED_SERVICES for why emptiness would otherwise read as compliance
     */
    #[Test]
    #[DataProvider('composeFiles')]
    public function itSweepsTheServicesTheDeploymentRuns(string $composeFile): void
    {
        $declared = \array_keys($this->servicesIn($composeFile));
        $expected = self::EXPECTED_SERVICES[$composeFile];

        \sort($declared);
        \sort($expected);

        $this->assertSame(
            $expected,
            $declared,
            \sprintf(
                'The services in "%s" are not the ones this gate was written against. Add the new service to '
                . 'EXPECTED_SERVICES together with its bound — arriving here silently is what would let one '
                . 'ship unbounded.',
                $composeFile,
            ),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function composeFiles(): iterable
    {
        foreach (self::COMPOSE_FILES as $composeFile) {
            yield $composeFile => [$composeFile];
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function servicesIn(string $composeFile): array
    {
        $parsed = Yaml::parseFile($this->composeDirectory() . '/' . $composeFile);

        if (!\is_array($parsed) || !\is_array($parsed['services'] ?? null)) {
            $this->fail(\sprintf('"%s" declares no services to read.', $composeFile));
        }

        /** @var array<string, array<string, mixed>> $services */
        $services = $parsed['services'];

        return $services;
    }

    /**
     * The blocks a file spells itself, as opposed to the ones it inherits — the only ones it can get wrong.
     *
     * @return array<string, mixed>
     */
    private function declaredBoundsIn(string $composeFile): array
    {
        $declared = [];

        foreach ($this->servicesIn($composeFile) as $name => $definition) {
            if (\array_key_exists('logging', $definition)) {
                $declared[$name] = $definition['logging'];
            }
        }

        return $declared;
    }

    /**
     * @return iterable<string, string>
     */
    private function scalarsOf(mixed $logging, string $path = 'logging'): iterable
    {
        if (\is_array($logging)) {
            foreach ($logging as $key => $value) {
                yield from $this->scalarsOf($value, $path . '.' . (string) $key);
            }

            return;
        }

        if (\is_scalar($logging)) {
            yield $path => (string) $logging;
        }
    }

    private function composeDirectory(): string
    {
        $apiRoot = \dirname(__DIR__, 4);

        foreach ([\dirname($apiRoot), \dirname($apiRoot) . '/repo'] as $candidate) {
            if (\is_file($candidate . '/' . self::BASE_FILE)) {
                return $candidate;
            }
        }

        $this->fail(
            'The root compose files are not reachable, so this gate cannot check anything. Inside the '
            . 'container they come from the read-only `./` bind mount at /app/repo declared in '
            . 'compose.dev.yaml — restore it rather than relaxing this failure into a skip.',
        );
    }
}
