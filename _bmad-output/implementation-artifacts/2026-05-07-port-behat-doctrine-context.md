# Port Behat DoctrineContext Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Port `Chiliz\TestBundle\Behat\Context\DoctrineContext` (354 lines, 13 step definitions) and its `TestDebugDataHolder` dependency from `/home/sergiosalcedo/Projects/test-bundle/` into ERPify's API test layer, matching the conventions established by the just-merged `feat/api-behat-entity-manager-context` branch.

**Architecture:** Two new files plus wiring. The Doctrine debug data holder is a test-only Symfony bridge subclass that captures executed queries (filtered to app code only — Behat/PHPUnit/Symfony frames are skipped). The Behat context reads that holder to make per-connection / per-query assertions. Wiring uses `services_test.yaml` (YAML, not PHP — see memory) to alias Symfony's default data holder to ours, plus a `config/packages/test/doctrine.yaml` override to enable the profiling middleware in the test env. Behat context registration goes in `api/tools/behat/behat.yml.dist` (not `services_test.yaml`) — that's the convention from the entity-manager port.

**Tech Stack:** PHP 8.5, Symfony 8 (`symfony/doctrine-bridge` `DebugDataHolder` / `DebugMiddleware`), Doctrine ORM 3.6 / DBAL 4.4, Behat 3.31 attributes (`#[Given]`, `#[Then]`, `#[BeforeScenario]`), Friends-of-Behat SymfonyExtension. PHPUnit for the data holder unit tests.

---

## File Structure

**Create:**
- `api/tests/Doctrine/TestDebugDataHolder.php` — namespace `Erpify\Tests\Doctrine` (loaded via `autoload-dev` `Erpify\\Tests\\: tests/`). Subclass of `Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder` that filters which call sites count as "app code" (excludes Behat/PHPUnit/Symfony, includes anything ending in `Controller`/`Command`/`Resolver`/`ParamConverter` or in a `\Controller\` namespace).
- `api/tests/Unit/Doctrine/TestDebugDataHolderTest.php` — unit tests for the filtering logic and the static-state reset behavior.
- `api/tests/Behat/Context/DoctrineContext.php` — namespace `Erpify\Tests\Behat\Context`. Behat context exposing the 13 step definitions from the source. Extends `Erpify\Tests\Behat\Context\Abstraction\AbstractContext`.
- `api/config/packages/test/doctrine.yaml` — test-env override that turns on the profiling middleware (`dbal.profiling: true`) so `DebugMiddleware` is registered.
- `api/features/shared/doctrine-query-stats.feature` — minimal smoke feature exercising at least 3 step variants (count by connection, total count, contains-needle) against an existing endpoint that hits the DB.

**Modify:**
- `api/config/services_test.yaml` — register `Erpify\Tests\Doctrine\TestDebugDataHolder` and alias `Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder` to it so `DebugMiddleware` consumes our subclass.
- `api/tools/behat/behat.yml.dist` — add `Erpify\Tests\Behat\Context\DoctrineContext` to the `default.suites.default.contexts` list.

**Do NOT modify:**
- `api/composer.json` autoload map — `Erpify\\Tests\\: tests/` already covers `Erpify\Tests\Doctrine\`.
- `api/config/packages/doctrine.yaml` — keep prod/dev defaults intact; test override is layered via `packages/test/`.
- The `feat/api-behat-entity-manager-context` worktree — read-only reference.

---

## Decisions Locked In

1. **Class is NOT `final`.** `EntityManagerContext` isn't final; staying consistent avoids Rector's privatize-on-final pass (memory: `feedback_api_lint_privatize_final.md`) silently rewriting the protected ctor params.
2. **Behat attributes, not annotations.** Source uses `@Then`/`@Given` doc-comments; ERPify is on Behat 3.31 with attribute support, and `EntityManagerContext` uses `#[Then]`/`#[Given]`/`#[BeforeScenario]`. Match it.
3. **YAML wiring only.** `services_test.yaml`, not `services_test.php` (memory: `feedback_api_services_test_config.md`).
4. **`TestDebugDataHolder` lives under `tests/Doctrine/`, not `tests/Behat/`.** It's a Doctrine middleware (not a Behat construct) — same separation the source bundle uses (`Chiliz\TestBundle\Doctrine\` vs `Chiliz\TestBundle\Behat\Context\`). The Behat tools autoload (`Erpify\\Tests\\Behat\\: tests/Behat/`) wouldn't pick it up anyway; the app-side `autoload-dev` does.
5. **Drop the `getBundleDependencies()` override.** ERPify's `AbstractContext::getBundleDependencies()` already returns `[]`; `DoctrineBundle` is loaded by `Kernel.php` regardless. Keeping the override would be dead code.
6. **Docstring summaries: minimal, only where the WHY is non-obvious.** Per `CLAUDE.md`: no comments that restate the code. Keep the source's per-step `@Then`/`@Given` annotation strings as `#[Then(...)]`/`#[Given(...)]` arguments only; drop "Count the amount of request executed for…" trivial summaries.
7. **Static state in `TestDebugDataHolder` is preserved as static.** It's intentional in the source — survives FoB's dual-container reset (request kernel vs. test kernel). Document with a one-liner WHY comment.
8. **One feature file is enough.** Behat covers wire-up; the unit test covers the filter logic. No need to write a feature per step variant — the source has 13 step regexes and a 1:1 fan-out adds nothing the linter/Behat parser doesn't already verify.

---

## Open Question (resolve at start of Task 2)

**How `DebugMiddleware` resolves its `DebugDataHolder` service:** The plan assumes `services_test.yaml` can alias `Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder` to our subclass and the doctrine-bundle middleware will pick it up. If profiling-enabled wiring uses a different concrete service ID (e.g. `doctrine.debug_data_holder` or `.debug.doctrine.middleware.debug`), the alias target must change. Verify with `make sf c='debug:container --tag=doctrine.middleware'` and `make sf c='debug:container DebugDataHolder'` after enabling `profiling: true`. Update the alias accordingly before writing the unit test.

---

## Required Checks (per `CLAUDE.md`)

After every PHP edit:
- `make php.stan` — must be clean.

At end of task chain:
- `make php.lint` — must pass with no errors. Be aware of Rector's protected→private privatization on final classes (not applicable here — no `final` — but don't add `final` reactively if a fixer suggests it).
- `make php.behat` — at minimum the new feature passes; existing features must not regress.

Stack must be running for these (`make docker.up` from the new worktree). If the existing entity-manager worktree's stack is already up, stop it first (`make docker.down` from that worktree) — they share host ports.

---

## Task 1: Port `TestDebugDataHolder` (TDD)

**Files:**
- Create: `api/tests/Doctrine/TestDebugDataHolder.php`
- Create: `api/tests/Unit/Doctrine/TestDebugDataHolderTest.php`

**Why TDD here:** The `shouldLog()` filter has 5 branches (excluded class, included class, skipped class, expected suffix, namespace match) and uses `debug_backtrace()`. Easy to ship a regression that silently logs nothing. Unit tests pin the behavior.

- [ ] **Step 1: Write the failing test (skeleton + first behavior)**

Create `api/tests/Unit/Doctrine/TestDebugDataHolderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Doctrine;

use Erpify\Tests\Doctrine\TestDebugDataHolder;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Middleware\Debug\Query;

final class TestDebugDataHolderTest extends TestCase
{
    private TestDebugDataHolder $holder;

    protected function setUp(): void
    {
        $this->holder = new TestDebugDataHolder();
        $this->holder->reset();
    }

    public function testForceFlagBypassesFilter(): void
    {
        $query = $this->makeQuery('SELECT 1');

        $this->holder->addQuery('default', $query, true);

        $data = $this->holder->getData();
        self::assertArrayHasKey('default', $data);
        self::assertCount(1, $data['default']);
        self::assertSame('SELECT 1', $data['default'][0]['sql']);
    }

    public function testResetClearsBothDataAndBacktraces(): void
    {
        $this->holder->addQuery('default', $this->makeQuery('SELECT 1'), true);
        self::assertNotEmpty($this->holder->getData());

        $this->holder->reset();

        self::assertSame([], $this->holder->getData());
    }

    public function testStaticStatePersistsAcrossInstances(): void
    {
        $this->holder->addQuery('default', $this->makeQuery('SELECT 1'), true);

        $other = new TestDebugDataHolder();

        self::assertArrayHasKey('default', $other->getData());
    }

    private function makeQuery(string $sql): Query
    {
        $query = new Query($sql);
        $query->start();
        $query->stop();

        return $query;
    }
}
```

- [ ] **Step 2: Run test to verify it fails (class missing)**

Run: `make php.unit c='--filter TestDebugDataHolderTest'`
Expected: FAIL with `Class "Erpify\Tests\Doctrine\TestDebugDataHolder" not found`.

- [ ] **Step 3: Implement `TestDebugDataHolder` (faithful port, namespace + style adapted)**

Create `api/tests/Doctrine/TestDebugDataHolder.php`:

```php
<?php

declare(strict_types=1);

namespace Erpify\Tests\Doctrine;

use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bridge\Doctrine\Middleware\Debug\Query;

/**
 * Captures executed queries during Behat scenarios so DoctrineContext can
 * make per-connection assertions. Static state is intentional: FoB's
 * SymfonyExtension boots a separate test container, and queries executed
 * inside the request kernel must remain visible to the assertion-side
 * container.
 *
 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
 */
class TestDebugDataHolder extends DebugDataHolder
{
    private const array INCLUDED_CLASSES = [
        'Symfony\Component\EventDispatcher\EventDispatcher',
        'Symfony\Component\Messenger\Command\ConsumeMessagesCommand',
    ];

    private const array EXCLUDED_CLASSES = [
        'DAMA\DoctrineTestBundle\Doctrine\DBAL\PostConnectEventListener',
    ];

    /** @var array<string, array<int, array{sql: string, params: array<string, mixed>, types: array<string, mixed>, executionMS: float|callable}>> */
    private static array $data = [];

    /** @var array<string, array<int, array<int, array<string, mixed>>>> */
    private static array $backtraces = [];

    public function reset(): void
    {
        self::$data = [];
        self::$backtraces = [];
    }

    /**
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     */
    public function addQuery(string $connectionName, Query $query, bool $force = false): void
    {
        $backtraces = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        if (!$force && !$this->shouldLog($backtraces)) {
            return;
        }

        self::$data[$connectionName][] = [
            'sql' => $query->getSql(),
            'params' => $query->getParams(),
            'types' => $query->getTypes(),
            // stop() may not have been called when DebugMiddleware records the query;
            // store the duration callable and resolve it lazily in getData().
            'executionMS' => $query->getDuration(...),
        ];

        // array_slice(2) drops this method + DebugMiddleware's invoker frame from the trace.
        self::$backtraces[$connectionName][] = \array_slice($backtraces, 2);
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getData(): array
    {
        foreach (self::$data as $connectionName => $dataForConn) {
            foreach ($dataForConn as $idx => $record) {
                if (\is_callable($record['executionMS'])) {
                    self::$data[$connectionName][$idx]['executionMS'] = ($record['executionMS'])();
                }
            }
        }

        $dataWithBacktraces = [];
        foreach (self::$data as $connectionName => $dataForConn) {
            $dataWithBacktraces[$connectionName] = $this->withBacktraces($connectionName, $dataForConn);
        }

        return $dataWithBacktraces;
    }

    /**
     * @param array<int, array<string, mixed>> $dataForConn
     *
     * @return array<int, array<string, mixed>>
     */
    private function withBacktraces(string $connectionName, array $dataForConn): array
    {
        $records = [];
        foreach ($dataForConn as $idx => $record) {
            if (isset(self::$backtraces[$connectionName][$idx])) {
                $record['backtrace'] = self::$backtraces[$connectionName][$idx];
            }

            $records[] = $record;
        }

        return $records;
    }

    /**
     * @param array<int, array<string, mixed>> $backtraces
     */
    private function shouldLog(array $backtraces): bool
    {
        if ([] === $backtraces) {
            return true;
        }

        $classes = array_unique(array_map(static fn (array $frame) => $frame['class'] ?? null, $backtraces));
        foreach ($classes as $class) {
            if (\in_array($class, self::EXCLUDED_CLASSES, true)) {
                return false;
            }

            if (\in_array($class, self::INCLUDED_CLASSES, true)) {
                return true;
            }

            if ($this->isSkippedClass($class)) {
                continue;
            }

            if ($this->hasAppSuffix($class) || $this->isInControllerNamespace($class)) {
                return true;
            }
        }

        return false;
    }

    private function isSkippedClass(?string $class): bool
    {
        return null === $class
            || str_starts_with($class, 'Behat')
            || str_starts_with($class, 'PHPUnit')
            || str_starts_with($class, 'Symfony')
            || str_contains($class, 'OptimizedLoadingFixturesContext');
    }

    private function hasAppSuffix(string $class): bool
    {
        return str_ends_with($class, 'Controller')
            || str_ends_with($class, 'ParamConverter')
            || str_ends_with($class, 'Command')
            || str_ends_with($class, 'Resolver');
    }

    private function isInControllerNamespace(string $class): bool
    {
        return str_contains($class, '\\Controller\\');
    }
}
```

- [ ] **Step 4: Run unit tests to verify they pass**

Run: `make php.unit c='--filter TestDebugDataHolderTest'`
Expected: PASS, 3 tests, 0 failures.

- [ ] **Step 5: Add a filter-logic test**

Append to `TestDebugDataHolderTest`:

```php
public function testQueryFromBehatContextIsSkipped(): void
{
    // shouldLog() inspects debug_backtrace(); calling addQuery from a method
    // whose declaring class starts with "Behat" must be filtered out.
    $skipper = new class($this->holder) {
        public function __construct(private readonly TestDebugDataHolder $holder) {}

        public function record(Query $query): void
        {
            $this->holder->addQuery('default', $query);
        }
    };

    // Anonymous class declared in this test file → class string contains "@anonymous";
    // its parent in the trace will be Behat-prefixed only if invoked via Behat's runner.
    // Use force=false; the trace is dominated by PHPUnit\Framework, which is skipped.
    $skipper->record($this->makeQuery('SELECT skipped'));

    self::assertSame([], $this->holder->getData());
}
```

Run: `make php.unit c='--filter TestDebugDataHolderTest'`
Expected: PASS, 4 tests.

- [ ] **Step 6: PHPStan check**

Run: `make php.stan`
Expected: 0 errors related to the new files. (Pre-existing project errors are out of scope; report any *new* errors only.)

- [ ] **Step 7: Commit**

```bash
git add api/tests/Doctrine/TestDebugDataHolder.php api/tests/Unit/Doctrine/TestDebugDataHolderTest.php
git commit -m "$(cat <<'EOF'
feat(api): port TestDebugDataHolder doctrine middleware

Captures executed queries during Behat scenarios with backtrace-based
filtering so app-side queries are recorded while Behat/PHPUnit/Symfony
internal queries are skipped. Static state is intentional - survives FoB's
dual-container boundary.
EOF
)"
```

---

## Task 2: Wire `TestDebugDataHolder` into the Test Container

**Files:**
- Create: `api/config/packages/test/doctrine.yaml`
- Modify: `api/config/services_test.yaml`
- Create: `api/tests/Functional/Doctrine/TestDebugDataHolderWiringTest.php` (smoke test that the kernel boots and our holder is the one DebugMiddleware uses)

- [ ] **Step 1: Resolve the open question — find the actual service ID**

Run with the stack up from the new worktree:

```bash
make docker.up.wait
make sf c='debug:container --parameter=doctrine.debug_data_holder' 2>&1 | head
make sf c='debug:container --tag=doctrine.middleware' 2>&1 | head -20
make sf c='debug:container DebugDataHolder' 2>&1 | head -20
```

Note the actual service ID and what `DebugMiddleware` consumes. The plan assumes the alias target is `Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder`. If the bundle uses an internal ID instead, adjust the alias in Step 3.

- [ ] **Step 2: Enable profiling middleware in test env**

Create `api/config/packages/test/doctrine.yaml`:

```yaml
doctrine:
    dbal:
        # Enables Symfony's DebugMiddleware which records queries via DebugDataHolder.
        # We override the default holder in services_test.yaml to capture them
        # for Behat's DoctrineContext.
        profiling: true
        profiling_collect_backtrace: true
```

- [ ] **Step 3: Register the holder + override the default**

Edit `api/config/services_test.yaml`. Append under `services:`:

```yaml
    Erpify\Tests\Doctrine\TestDebugDataHolder: ~

    # Override the DebugMiddleware's DebugDataHolder. Adjust the alias target
    # if Step 1 revealed a different service ID.
    Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder:
        alias: Erpify\Tests\Doctrine\TestDebugDataHolder
        public: true
```

- [ ] **Step 4: Write a wiring smoke test**

Create `api/tests/Functional/Doctrine/TestDebugDataHolderWiringTest.php`:

```php
<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Doctrine;

use Erpify\Tests\Doctrine\TestDebugDataHolder;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TestDebugDataHolderWiringTest extends KernelTestCase
{
    public function testServicesTestYamlAliasesDefaultHolderToOurs(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $resolved = $container->get(DebugDataHolder::class);

        self::assertInstanceOf(TestDebugDataHolder::class, $resolved);
    }
}
```

- [ ] **Step 5: Run the wiring test**

Run: `make php.unit c='--filter TestDebugDataHolderWiringTest'`
Expected: PASS. If FAIL with "service not found", the alias target ID was wrong → update Step 3 with what Step 1 produced.

- [ ] **Step 6: PHPStan**

Run: `make php.stan`
Expected: 0 new errors.

- [ ] **Step 7: Commit**

```bash
git add api/config/packages/test/doctrine.yaml api/config/services_test.yaml api/tests/Functional/Doctrine/TestDebugDataHolderWiringTest.php
git commit -m "feat(api): wire TestDebugDataHolder into the test container"
```

---

## Task 3: Port `DoctrineContext`

**Files:**
- Create: `api/tests/Behat/Context/DoctrineContext.php`
- Modify: `api/tools/behat/behat.yml.dist`

- [ ] **Step 1: Implement `DoctrineContext` (faithful port — annotations to attributes, namespace adapted)**

Create `api/tests/Behat/Context/DoctrineContext.php`:

```php
<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Doctrine\Persistence\ManagerRegistry;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use Erpify\Tests\Doctrine\TestDebugDataHolder;

/**
 * Per-connection assertions over executed Doctrine queries. Backed by
 * TestDebugDataHolder, which captures queries while filtering out
 * Behat/PHPUnit/Symfony-internal frames.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 */
class DoctrineContext extends AbstractContext
{
    public function __construct(
        protected readonly ManagerRegistry $registry,
        protected readonly TestDebugDataHolder $debugDataHolder,
    ) {
    }

    /**
     * Reset is global because TestDebugDataHolder uses static state — there's
     * no per-connection clear API on Symfony's DebugDataHolder.
     */
    #[Given('I reset the stats for all doctrine connections')]
    #[BeforeScenario]
    final public function resetConnectionStats(): void
    {
        $this->debugDataHolder->reset();
    }

    #[Then(':count request(s) got executed for doctrine connection :connectionName')]
    public function queriesWereExecutedOnConnection(int $count, string $connectionName): void
    {
        self::assertEquals($count, $this->getQueriesCountForConnectionName($connectionName));
    }

    #[Then(':count request(s) got executed across all doctrine connections')]
    public function queriesWereExecutedAcrossConnections(int $count): void
    {
        self::assertEquals($count, $this->getQueriesCountForAllConnections());
    }

    #[Then(':count request(s) got executed only for doctrine connection :connectionName')]
    public function queriesWereExecutedOnConnectionAndNoOther(int $count, string $connectionName): void
    {
        self::assertEquals($count, $this->getQueriesCountForConnectionName($connectionName));

        $errorMessages = [];
        foreach ($this->getUsedConnectionNames() as $name) {
            if ($connectionName === $name) {
                continue;
            }

            $queriesCount = $this->getQueriesCountForConnectionName($name);
            if (0 === $queriesCount) {
                continue;
            }

            $errorMessages[] = \sprintf('"%s": %s', $name, $queriesCount);
        }

        self::assertEmpty(
            $errorMessages,
            \sprintf('Other doctrine connections had requests executed: %s', implode(', ', $errorMessages)),
        );
    }

    #[Then('a request contains :needle for doctrine connection :connectionName')]
    #[Then('a request contains :needle across all doctrine connections')]
    public function oneOfTheRequestsForConnectionContains(string $needle, ?string $connectionName = null): void
    {
        foreach ($this->debugDataHolder->getData() as $name => $queries) {
            if (null !== $connectionName && $name !== $connectionName) {
                continue;
            }

            foreach ($queries as $query) {
                if (str_contains($query['sql'], $needle)) {
                    return;
                }
            }
        }

        self::fail(\sprintf('No query found for sql: "%s"', $needle));
    }

    #[Then('the request(s) got executed only on doctrine connection :connectionName')]
    public function queriesWereExecutedOnlyOnConnection(string $connectionName): void
    {
        $existingConnectionNames = $this->getUsedConnectionNames();
        self::assertTrue(
            \in_array($connectionName, $existingConnectionNames, true),
            'connection not found in used connection list',
        );

        foreach ($existingConnectionNames as $existingConnectionName) {
            $requestCount = $this->getQueriesCountForConnectionName($existingConnectionName);

            if ($existingConnectionName !== $connectionName) {
                self::assertEquals(
                    0,
                    $requestCount,
                    \sprintf(
                        'Queries count for connection %s should be null. %s found.',
                        $existingConnectionName,
                        $requestCount,
                    ),
                );

                continue;
            }

            self::assertGreaterThan(
                0,
                $requestCount,
                \sprintf('No queries for connection %s.', $existingConnectionName),
            );
        }
    }

    #[Then('the request number :number contains :needle')]
    #[Then('the request number :number contains :needle for doctrine connection :connectionName')]
    public function requestContainsContent(int $number, string $needle, ?string $connectionName = null): void
    {
        self::assertGreaterThanOrEqual(0, $number, 'Number should be equal to or greater than zero');

        foreach ($this->getFilteredConnectionsQueries($connectionName) as $queries) {
            $query = $queries[$number] ?? null;
            if (null !== $query && str_contains($query['sql'], $needle)) {
                return;
            }
        }

        self::fail(\sprintf('No query found with sql content "%s"', $needle));
    }

    #[Then('the request number :number does not contain :needle')]
    #[Then('the request number :number does not contain :needle for doctrine connection :connectionName')]
    public function requestDoesNotContainContent(int $number, string $needle, ?string $connectionName = null): void
    {
        self::assertGreaterThanOrEqual(0, $number, 'Number must be zero or greater');

        foreach ($this->getFilteredConnectionsQueries($connectionName) as $queries) {
            $query = $queries[$number] ?? null;
            if (null !== $query && !str_contains($query['sql'], $needle)) {
                return;
            }
        }

        self::fail(\sprintf('A query exists with sql content "%s"', $needle));
    }

    #[Then('the request number :number argument :argumentName is equal to :expectedValue')]
    #[Then('the request number :number argument :argumentName is equal to :expectedValue for doctrine connection :connectionName')]
    public function requestArgumentIsEqualTo(
        int $number,
        string $argumentName,
        string $expectedValue,
        ?string $connectionName = null,
    ): void {
        foreach ($this->getFilteredConnectionsQueries($connectionName) as $queries) {
            $queryParams = $queries[$number]['params'] ?? [];
            foreach ($queryParams as $key => $param) {
                $compareKey = (\is_int($key) && ctype_digit($argumentName)) ? (int) $argumentName : $argumentName;

                if ($key === $compareKey) {
                    self::assertEquals($expectedValue, $param);

                    return;
                }
            }
        }

        self::fail(\sprintf('No argument %s found for request number %s', $argumentName, $number));
    }

    #[Then(':count SQL statements of type :type got executed for doctrine connection :connectionName')]
    public function statementTypeCountIsEqualTo(int $count, string $type, string $connectionName): void
    {
        $connectionsQueries = $this->getFilteredConnectionsQueries($connectionName);

        if (!\array_key_exists($connectionName, $connectionsQueries)) {
            self::fail(\sprintf('No connection found with name "%s"', $connectionName));
        }

        $typesCount = [];
        foreach ($connectionsQueries[$connectionName] as $query) {
            $queryType = explode(' ', $query['sql'])[0];
            $typesCount[$queryType] = ($typesCount[$queryType] ?? 0) + 1;
        }

        self::assertEquals($count, $typesCount[strtoupper($type)] ?? 0);
    }

    #[Then('I dump the number of executed queries for each doctrine connection')]
    public function iDumpTheNumberOfExecutedQueries(): void
    {
        $messages = ['Number of executed requests for each connection:'];

        foreach ($this->getUsedConnectionNames() as $connectionName) {
            $messages[] = \sprintf('%s: %s', $connectionName, $this->getQueriesCountForConnectionName($connectionName));
        }

        $messages[] = \sprintf('Total number of queries: %s', $this->getQueriesCountForAllConnections());

        foreach ($messages as $message) {
            print_r($message . PHP_EOL);
        }
    }

    #[Then('I dump the executed queries for each doctrine connection')]
    #[Then('I dump executed the queries for doctrine connection :connectionName')]
    public function iDumpTheExecutedQueries(?string $connectionName = null): void
    {
        $connectionsQueries = $this->getFilteredConnectionsQueries($connectionName);

        if ([] === $connectionsQueries) {
            print_r('No connection used!');
        }

        foreach ($connectionsQueries as $name => $queries) {
            print_r(\sprintf('Queries for connection "%s":%s', $name, PHP_EOL));
            foreach ($queries as $key => $query) {
                print_r(\sprintf('(%s) - %s%s', $key, $query['sql'], PHP_EOL));
            }

            print_r('--------------------------------------------' . PHP_EOL);
        }
    }

    /**
     * When a step updates entities and a previous step already loaded them, the
     * unit of work returns stale data. Clearing all managers forces the next
     * query to hit the database.
     */
    #[Given('I clear the entity managers')]
    public function iClearTheEntityManagers(): void
    {
        foreach ($this->registry->getManagers() as $manager) {
            $manager->clear();
        }
    }

    public function getQueriesCountForAllConnections(): int
    {
        $count = 0;
        foreach ($this->getUsedConnectionNames() as $connectionName) {
            $count += $this->getQueriesCountForConnectionName($connectionName);
        }

        return $count;
    }

    public function getQueriesCountForConnectionName(string $connectionName): int
    {
        return \count($this->debugDataHolder->getData()[$connectionName] ?? []);
    }

    /**
     * @return array<int, string>
     */
    public function getUsedConnectionNames(): array
    {
        return array_keys($this->debugDataHolder->getData());
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getFilteredConnectionsQueries(?string $optionalConnectionNameFilter): array
    {
        $data = $this->debugDataHolder->getData();
        if (null === $optionalConnectionNameFilter) {
            return $data;
        }

        if (\array_key_exists($optionalConnectionNameFilter, $data)) {
            return [$optionalConnectionNameFilter => $data[$optionalConnectionNameFilter]];
        }

        return [];
    }
}
```

**Adaptations from source vs. faithful port:**
- `@Given`/`@Then`/`@BeforeScenario` doc-comments → `#[Given]`/`#[Then]`/`#[BeforeScenario]` attributes.
- `getBundleDependencies()` override dropped (DoctrineBundle is in `Kernel.php`).
- `requestDoesNotContainContent` source asserted `assertGreaterThan(0, $needle)` against a string — meaningless. Replaced with `assertGreaterThanOrEqual(0, $number)` to mirror the sibling method's intent (the `$needle` arg was a typo in the source).
- Renamed `:connection` placeholder to `:connectionName` in two regex variants for arg-name consistency with the method param.
- Bug fix in `requestArgumentIsEqualTo`: source mutated `$argumentName` to int inside the loop, which corrupted comparisons after the first iteration when `$key` types varied. Use a local `$compareKey`.
- Bug fix in `iDumpTheExecutedQueries`: source's regex `:connectionName` was not declared; the second `#[Then]` form makes it explicit.

- [ ] **Step 2: PHPStan**

Run: `make php.stan`
Expected: 0 new errors.

- [ ] **Step 3: Register the context in `behat.yml.dist`**

Edit `api/tools/behat/behat.yml.dist`. Under `default.suites.default.contexts`, append:

```yaml
                - Erpify\Tests\Behat\Context\DoctrineContext
```

(no constructor args — FoB's SymfonyExtension autowires `ManagerRegistry` and `TestDebugDataHolder` from the test container).

- [ ] **Step 4: Commit**

```bash
git add api/tests/Behat/Context/DoctrineContext.php api/tools/behat/behat.yml.dist
git commit -m "$(cat <<'EOF'
feat(api): port behat doctrine-stats context

Faithful port of Chiliz\TestBundle\Behat\Context\DoctrineContext, adapted
to Behat 3 attributes and ERPify's tests/Behat/Context layout. Two source
bugs corrected inline (requestArgumentIsEqualTo type mutation,
requestDoesNotContainContent assertion target).
EOF
)"
```

---

## Task 4: Smoke Feature

**Files:**
- Create: `api/features/shared/doctrine-query-stats.feature`

**Goal:** Prove the wiring end-to-end. One scenario hitting an existing endpoint that runs at least one query, asserting per-connection count + sql contains.

- [ ] **Step 1: Identify a stable endpoint**

Run: `make sf c='debug:router' 2>&1 | grep -E 'GET\s+/api' | head -10`

Pick a GET endpoint that hits the DB on every call (a list endpoint backed by a Doctrine repository). If none exists in `main`, write the scenario against `/api/v1/<resource>` matching whatever the test fixtures expose. If the project has zero such endpoints in `main`, fall back to a scenario that uses only `EntityManagerContext`'s `I execute the SQL query` step — but that won't be in `main`. In that case, defer Task 4 and document the deferral.

- [ ] **Step 2: Write the feature**

Create `api/features/shared/doctrine-query-stats.feature`:

```gherkin
Feature: Doctrine query stats

  Background:
    Given I reset the stats for all doctrine connections

  Scenario: count queries on the default connection after a list call
    When I send a "GET" request to "<existing-list-endpoint>"
    Then the response status code should be 200
    And the request(s) got executed only on doctrine connection "default"
    And a request contains "SELECT" for doctrine connection "default"
```

Replace `<existing-list-endpoint>` with the path identified in Step 1.

- [ ] **Step 3: Run Behat**

Run: `make php.behat c='api/features/shared/doctrine-query-stats.feature'`
Expected: 1 scenario passing, 4 steps.

If the scenario fails because the holder records zero queries: re-check Task 2 Step 1 — `DebugMiddleware` may be wired against a different data holder service ID than aliased.

- [ ] **Step 4: Commit**

```bash
git add api/features/shared/doctrine-query-stats.feature
git commit -m "test(api): smoke feature for doctrine query stats context"
```

---

## Task 5: Final Verification + Cleanup

- [ ] **Step 1: Full lint sweep**

Run: `make php.lint`
Expected: clean. Watch for:
- Rector privatize-on-final: should not trigger (no `final` classes added).
- PHPStan/Psalm: address any new errors. Pre-existing errors stay.
- PHP-CS-Fixer / PHPCS: fix any auto-correctable issues (the target runs fixers in place, then re-checks).

- [ ] **Step 2: Full Behat sweep**

Run: `make php.behat`
Expected: all scenarios passing (the new one + any pre-existing in `main`).

- [ ] **Step 3: Full unit sweep**

Run: `make php.unit`
Expected: all tests passing.

- [ ] **Step 4: Push**

```bash
git push -u origin feat/api-behat-doctrine-context
```

- [ ] **Step 5: Open PR**

Use `gh pr create` with a body summarizing: ported context (link to source bundle), data-holder placement rationale, two source bugs fixed inline, smoke feature, follow-up items if any.

---

## Self-Review Checklist (run before handing off to executor)

**1. Spec coverage:**
- [x] TestDebugDataHolder ported (Task 1).
- [x] DoctrineContext ported with all 13 step definitions (Task 3).
- [x] Wiring decisions made (Task 2 — services_test.yaml + packages/test/doctrine.yaml).
- [x] Service config is YAML, not PHP (Task 2 Step 3).
- [x] Rector privatize-on-final risk addressed (no `final` added; called out in Decisions §1).
- [x] `make php.stan` and `make php.lint` invoked at every PHP-touching step.

**2. Placeholder scan:** None. Every code block is concrete. The single dynamic value is `<existing-list-endpoint>` in Task 4 Step 2, with a fallback documented in Task 4 Step 1.

**3. Type consistency:**
- `TestDebugDataHolder` extends `Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder` (consistent across Tasks 1, 2, 3).
- `DoctrineContext` ctor signature `(ManagerRegistry $registry, TestDebugDataHolder $debugDataHolder)` matches `behat.yml.dist` registration (no constructor args, autowiring).
- `getQueriesCountForConnectionName` / `getUsedConnectionNames` / `getFilteredConnectionsQueries` names consistent across the class.

**4. Risk items surfaced:**
- Open question on `DebugMiddleware` data holder service ID (Task 2 Step 1).
- Static state in `TestDebugDataHolder` may interact with parallel test execution if ever introduced — flag in PR body.
- Two source bugs fixed inline (called out in Task 3 commit message).

---

### Review Findings

Code review run on 2026-05-07. Three layers: Blind Hunter (cynical, diff only), Edge Case Hunter (path/boundary), Acceptance Auditor (vs this plan). 0 decisions-needed, 2 patches, 20 defers, 14 dismissed.

**Patches (unchecked = TODO):**

- [x] [Review][Patch] `queriesWereExecutedOnlyOnConnection` error message says "should be null" but `$requestCount` is an int — should read "should be 0" [api/tests/Behat/Context/DoctrineContext.php:115-122] — applied
- [x] [Review][Patch] PHPDoc shape on `$data`: `params: array<string, mixed>` should be `array<int|string, mixed>` since DBAL returns positional params with int keys [api/tests/Doctrine/TestDebugDataHolder.php:33] — applied

**Deferred (faithful-port behaviors and pre-existing concerns; logged to `deferred-work.md`):**

- [x] [Review][Defer] Empty-backtrace early return in `shouldLog()` is dead code in practice [TestDebugDataHolder.php:113-115] — faithful port
- [x] [Review][Defer] `INCLUDED_CLASSES` works only because INCLUDED is checked before prefix-skip in the same iteration loop — fragile ordering, would benefit from a clarifying comment [TestDebugDataHolder.php:117-127] — faithful port
- [x] [Review][Defer] Static state lifecycle: parallel test runners would share state across processes [TestDebugDataHolder.php:30-34] — documented intent
- [x] [Review][Defer] `getData()` mutates `self::$data` on every call; `Query::getDuration(...)` callable retains Query reference for the lifetime of the static array (memory growth in long scenarios) [TestDebugDataHolder.php:54,74-79] — faithful port
- [x] [Review][Defer] `requestDoesNotContainContent` returns success on the first non-matching connection — existential vs universal semantics for "the request number N does not contain X" [DoctrineContext.php:155-162] — faithful port
- [x] [Review][Defer] `oneOfTheRequestsForConnectionContains` produces a misleading error when the named connection doesn't exist (says "no query found" instead of "connection unknown") [DoctrineContext.php:81-95] — faithful port
- [x] [Review][Defer] `statementTypeCountIsEqualTo` parses SQL by `explode(' ')[0]` — fragile vs leading whitespace, comments (`/* hint */ SELECT`), or CTE prefixes [DoctrineContext.php:194-204] — faithful port
- [x] [Review][Defer] `array_slice($backtraces, 2)` assumes a fixed call depth — silently misaligns if Symfony refactors DebugMiddleware [TestDebugDataHolder.php:62] — faithful port
- [x] [Review][Defer] `profiling_collect_backtrace: true` is redundant since the subclass overrides `addQuery` and captures backtraces itself [config/packages/test/doctrine.yaml:7] — defensive but harmless
- [x] [Review][Defer] `Symfony` prefix skip blocks legitimate Symfony-namespaced application code (rare) [TestDebugDataHolder.php:138-142] — faithful port
- [x] [Review][Defer] `queriesWereExecutedOnlyOnConnection` requires the named connection to be non-empty (asymmetric semantics — fails when zero queries ran anywhere) [DoctrineContext.php:97-101] — faithful port
- [x] [Review][Defer] Wiring test asserts the alias resolves but doesn't verify `DebugMiddleware` is registered in the middleware chain [TestDebugDataHolderWiringTest.php] — covered transitively by the bank smoke feature
- [x] [Review][Defer] `oneOfTheRequestsForConnectionContains` uses substring match — needle can match a column name or quoted literal containing it [DoctrineContext.php:81-95] — faithful port
- [x] [Review][Defer] `requestArgumentIsEqualTo` first-match-wins across connections; order-dependent on `getData()` iteration order [DoctrineContext.php:259-279] — faithful port
- [x] [Review][Defer] `ctype_digit('00')` makes a param named `'00'` compare as int 0 against indexed param 0 [DoctrineContext.php:268] — faithful port
- [x] [Review][Defer] Closures with no `class` key in backtrace frames silently drop their queries [TestDebugDataHolder.php:117-127] — faithful port
- [x] [Review][Defer] App namespaces without Controller/Command/Resolver/ParamConverter suffix and without `\Controller\` (e.g. `MessageHandler`, `Repository`) drop their queries [TestDebugDataHolder.php:117-135] — faithful port
- [x] [Review][Defer] First non-null class in `INCLUDED_CLASSES` short-circuits; later EXCLUDED frame ignored [TestDebugDataHolder.php:117-122] — faithful port
- [x] [Review][Defer] `query-stats.feature` validation-reject scenario asserts `0 request(s) got executed across all doctrine connections`; auth/audit listeners may issue probe queries before validation [query-stats.feature:35-39] — real-world signal worth confirming on first Behat run
- [x] [Review][Defer] `requestArgumentIsEqualTo` `return` on first match prevents catching mismatches in later connections — same family as the existential-semantics defer above [DoctrineContext.php:174-181] — faithful port

**Dismissed (noise / false positive / handled elsewhere):** 14 findings — int-vs-string param key matching reviewer self-corrected; `@SuppressWarnings` on class accepted in code-quality review; `print_r` vs `echo` stylistic; `public: true` on alias necessary for testing; assertion-target style on `$number`; UUID provenance (same UUID is in existing `get.feature`); generic fail message diagnostics; `$number` negative already trapped; `BeforeScenario` + `Given` duplicate is intentional explicit reset; smoke feature path differs (user redirect was authorized); test renamed (improvement); PHPMD scope moved (improvement); attribute string line-broken (necessary).
