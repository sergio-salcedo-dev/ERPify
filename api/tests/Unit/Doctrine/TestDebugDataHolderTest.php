<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Doctrine;

use Erpify\Tests\Behat\State\FixturesChangeTracker;
use Erpify\Tests\Doctrine\TestDebugDataHolder;
use Erpify\Tests\Unit\Doctrine\Stubs\Controller\FakeAction;
use Erpify\Tests\Unit\Doctrine\Stubs\FakeCommand;
use Erpify\Tests\Unit\Doctrine\Stubs\FakeController;
use Override;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Middleware\Debug\Query;

/**
 * Branch coverage notes: shouldLog()'s EXCLUDED_CLASSES and INCLUDED_CLASSES paths
 * cannot be exercised here without declaring stubs inside the
 * DAMA\DoctrineTestBundle and Symfony\Component namespaces, which would be
 * invasive. Those branches stay uncovered at the unit level and are left to
 * integration coverage.
 *
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class TestDebugDataHolderTest extends TestCase
{
    private TestDebugDataHolder $testDebugDataHolder;

    #[Override]
    protected function setUp(): void
    {
        $this->testDebugDataHolder = new TestDebugDataHolder();
        $this->testDebugDataHolder->resetScenario();
    }

    /**
     * A false flag would skip a fixture restore, so the tracker goes back to its fail-safe state.
     */
    #[Override]
    protected function tearDown(): void
    {
        FixturesChangeTracker::markChanged();
    }

    public function testForceFlagBypassesFilter(): void
    {
        $query = $this->makeQuery('SELECT 1');

        $this->testDebugDataHolder->addQuery('default', $query, true);

        $data = $this->testDebugDataHolder->getData();
        $this->assertArrayHasKey('default', $data);
        $this->assertCount(1, $data['default']);
        $this->assertArrayHasKey(0, $data['default']);
        $this->assertSame('SELECT 1', $data['default'][0]['sql']);
    }

    public function testResetScenarioClearsBothDataAndBacktraces(): void
    {
        $this->testDebugDataHolder->addQuery('default', $this->makeQuery('SELECT 1'), true);
        $this->assertNotEmpty($this->testDebugDataHolder->getData());

        $this->testDebugDataHolder->resetScenario();

        $this->assertSame([], $this->testDebugDataHolder->getData());
    }

    public function testResetIsNoOpSoProfilerCollectorCannotWipeAccumulator(): void
    {
        $this->testDebugDataHolder->addQuery('default', $this->makeQuery('SELECT 1'), true);

        $this->testDebugDataHolder->reset();

        $this->assertNotEmpty($this->testDebugDataHolder->getData());
    }

    public function testStaticStatePersistsAcrossInstances(): void
    {
        $this->testDebugDataHolder->addQuery('default', $this->makeQuery('SELECT 1'), true);

        $testDebugDataHolder = new TestDebugDataHolder();

        $this->assertArrayHasKey('default', $testDebugDataHolder->getData());

        $testDebugDataHolder->resetScenario();

        $this->assertSame([], $this->testDebugDataHolder->getData());
    }

    public function testQueryFromPhpUnitFrameIsSkipped(): void
    {
        // shouldLog() inspects debug_backtrace(); when invoked from a PHPUnit
        // test method the trace is dominated by PHPUnit\Framework\* frames.
        // isSkippedClass() matches the "PHPUnit" prefix (and "Symfony"/"Behat"
        // prefixes in adjacent runner frames), so every frame is `continue`d
        // and shouldLog() falls through to `return false`.
        $this->testDebugDataHolder->addQuery('default', $this->makeQuery('SELECT skipped'));

        $this->assertSame([], $this->testDebugDataHolder->getData());
    }

    public function testQueryFromControllerSuffixIsLogged(): void
    {
        (new FakeController())->record($this->testDebugDataHolder, $this->makeQuery('SELECT controller'));

        $this->assertCount(1, $this->testDebugDataHolder->getData()['default'] ?? []);
    }

    public function testQueryFromCommandSuffixIsLogged(): void
    {
        (new FakeCommand())->record($this->testDebugDataHolder, $this->makeQuery('SELECT command'));

        $this->assertCount(1, $this->testDebugDataHolder->getData()['default'] ?? []);
    }

    public function testQueryFromControllerNamespaceIsLogged(): void
    {
        (new FakeAction())->record($this->testDebugDataHolder, $this->makeQuery('SELECT namespace'));

        $this->assertCount(1, $this->testDebugDataHolder->getData()['default'] ?? []);
    }

    public function testRawWriteMarksTheFixturesTrackerEvenWhenTheQueryIsFilteredOut(): void
    {
        FixturesChangeTracker::reset();

        $this->testDebugDataHolder->addQuery('default', $this->makeQuery('INSERT INTO audit_log (id) VALUES (1)'));

        $this->assertTrue(FixturesChangeTracker::hasChanged());
        // The query accounting is untouched: this frame is PHPUnit's, so shouldSkip() still drops it.
        $this->assertSame([], $this->testDebugDataHolder->getData());
    }

    public function testPlainSelectLeavesTheFixturesTrackerClean(): void
    {
        FixturesChangeTracker::reset();

        $sql = "SELECT id FROM audit_log WHERE action = 'INSERT_BANK'";
        (new FakeController())->record($this->testDebugDataHolder, $this->makeQuery($sql));

        $this->assertFalse(FixturesChangeTracker::hasChanged());
        // A read still counts for the per-connection budgets, so the accounting must have kept it.
        $this->assertCount(1, $this->testDebugDataHolder->getData()['default'] ?? []);
    }

    private function makeQuery(string $sql): Query
    {
        $query = new Query($sql);
        $query->start();
        $query->stop();

        return $query;
    }
}
