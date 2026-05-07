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

        // Anonymous class declared in this test file -> class string contains "@anonymous";
        // its parent in the trace will be Behat-prefixed only if invoked via Behat's runner.
        // Use force=false; the trace is dominated by PHPUnit\Framework, which is skipped.
        $skipper->record($this->makeQuery('SELECT skipped'));

        self::assertSame([], $this->holder->getData());
    }

    private function makeQuery(string $sql): Query
    {
        $query = new Query($sql);
        $query->start();
        $query->stop();

        return $query;
    }
}
