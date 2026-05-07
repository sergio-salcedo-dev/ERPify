<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Doctrine;

use Erpify\Tests\Doctrine\TestDebugDataHolder;
use Erpify\Tests\Unit\Doctrine\Stubs\Controller\FakeAction;
use Erpify\Tests\Unit\Doctrine\Stubs\FakeCommand;
use Erpify\Tests\Unit\Doctrine\Stubs\FakeController;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Middleware\Debug\Query;

/**
 * Branch coverage notes: shouldLog()'s EXCLUDED_CLASSES and INCLUDED_CLASSES paths
 * cannot be exercised here without declaring stubs inside the
 * DAMA\DoctrineTestBundle and Symfony\Component namespaces, which would be
 * invasive. Those branches stay uncovered at the unit level and are left to
 * integration coverage.
 */
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

        $other->reset();

        self::assertSame([], $this->holder->getData());
    }

    public function testQueryFromPhpUnitFrameIsSkipped(): void
    {
        // shouldLog() inspects debug_backtrace(); when invoked from a PHPUnit
        // test method the trace is dominated by PHPUnit\Framework\* frames.
        // isSkippedClass() matches the "PHPUnit" prefix (and "Symfony"/"Behat"
        // prefixes in adjacent runner frames), so every frame is `continue`d
        // and shouldLog() falls through to `return false`.
        $this->holder->addQuery('default', $this->makeQuery('SELECT skipped'));

        self::assertSame([], $this->holder->getData());
    }

    public function testQueryFromControllerSuffixIsLogged(): void
    {
        (new FakeController())->record($this->holder, $this->makeQuery('SELECT controller'));

        self::assertCount(1, $this->holder->getData()['default'] ?? []);
    }

    public function testQueryFromCommandSuffixIsLogged(): void
    {
        (new FakeCommand())->record($this->holder, $this->makeQuery('SELECT command'));

        self::assertCount(1, $this->holder->getData()['default'] ?? []);
    }

    public function testQueryFromControllerNamespaceIsLogged(): void
    {
        (new FakeAction())->record($this->holder, $this->makeQuery('SELECT namespace'));

        self::assertCount(1, $this->holder->getData()['default'] ?? []);
    }

    private function makeQuery(string $sql): Query
    {
        $query = new Query($sql);
        $query->start();
        $query->stop();

        return $query;
    }
}
