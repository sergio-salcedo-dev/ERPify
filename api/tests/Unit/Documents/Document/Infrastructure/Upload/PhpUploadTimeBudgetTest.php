<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Infrastructure\Upload;

use Erpify\Documents\Document\Infrastructure\Upload\PhpUploadTimeBudget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use const PHP_SAPI;

/**
 * @internal
 */
#[CoversClass(PhpUploadTimeBudget::class)]
final class PhpUploadTimeBudgetTest extends TestCase
{
    public function testItAcquiresNoTimeLimitOnTheCommandLine(): void
    {
        $this->assertSame('cli', PHP_SAPI, 'this test only means something on the CLI');
        $before = \ini_get('max_execution_time');

        (new PhpUploadTimeBudget())->arm(5);

        $this->assertSame($before, \ini_get('max_execution_time'));
    }
}
