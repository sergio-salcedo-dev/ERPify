<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Infrastructure\Upload;

use Erpify\Documents\Document\Infrastructure\Upload\PhpUploadBodySpool;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Which spool file the discard destroys, over a planted `upload_tmp_dir`: only the one file that can be this
 * request's — never another in-flight request's, and nothing at all when more than one could be.
 *
 * @internal
 */
#[CoversClass(PhpUploadBodySpool::class)]
final class PhpUploadBodySpoolTest extends TestCase
{
    private string $directory;

    private TestHandler $log;

    #[Override]
    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir() . '/spool-' . \bin2hex(\random_bytes(6));
        (new Filesystem())->mkdir($this->directory);
        $this->log = new TestHandler();
    }

    #[Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testItDestroysTheOneFileHoldingExactlyTheBytesRead(): void
    {
        $body = \random_bytes(200_000);
        $ours = $this->plant('phpOurs', $body);
        $sameSizeOther = $this->plant('phpOther', \random_bytes(200_000));
        $growingOther = $this->plant('phpGrowing', \random_bytes(120_000));
        $notASpool = $this->plant('session-file', $body);

        $this->discard($body);

        $this->assertFileDoesNotExist($ours);
        $this->assertFileExists($sameSizeOther, "another request's body of the same size");
        $this->assertFileExists($growingOther, 'another request still being read');
        $this->assertFileExists($notASpool, "only `php*` names are PHP's spools");
    }

    public function testItAcceptsTheReadAheadPastTheBytesRead(): void
    {
        $body = \random_bytes(200_000);
        $ours = $this->plant('phpOurs', $body . \random_bytes(8_192));

        $this->discard($body);

        $this->assertFileDoesNotExist($ours);
    }

    /**
     * Two in-flight uploads of the same bytes: either file could be this request's, so neither is touched.
     */
    public function testItLeavesEveryCandidateWhenMoreThanOneMatches(): void
    {
        $body = \random_bytes(200_000);
        $first = $this->plant('phpFirst', $body);
        $second = $this->plant('phpSecond', $body);

        $this->discard($body);

        $this->assertFileExists($first);
        $this->assertFileExists($second);
        $this->assertTrue($this->log->hasWarningThatContains('left for its worker thread'));
    }

    /**
     * A body this small never left memory, so a matching file can only be another request's.
     */
    public function testABodyThatStayedInMemoryTouchesNothing(): void
    {
        $body = \random_bytes(PhpUploadBodySpool::SPOOL_THRESHOLD_BYTES - 1);
        $other = $this->plant('phpOther', $body);

        $this->discard($body);

        $this->assertFileExists($other);
    }

    /**
     * PHP spills a body of exactly the threshold to a file (measured), so that one is destroyed.
     */
    public function testABodyOfExactlyTheThresholdIsDestroyed(): void
    {
        $body = \random_bytes(PhpUploadBodySpool::SPOOL_THRESHOLD_BYTES);
        $ours = $this->plant('phpOurs', $body);

        $this->discard($body);

        $this->assertFileDoesNotExist($ours);
    }

    /**
     * A same-size candidate whose head differs is refused on the head alone, whatever its tail.
     */
    public function testACandidateWhoseHeadDiffersIsNotOurs(): void
    {
        $body = \random_bytes(200_000);
        $tail = \substr($body, PhpUploadBodySpool::HEAD_BYTES);
        $other = $this->plant('phpOther', \random_bytes(PhpUploadBodySpool::HEAD_BYTES) . $tail);

        $this->discard($body);

        $this->assertFileExists($other);
    }

    public function testAMissingSpoolIsLoggedAndNothingThrown(): void
    {
        $this->discard(\random_bytes(200_000));

        $this->assertTrue($this->log->hasWarningThatContains('left for its worker thread'));
    }

    private function discard(string $body): void
    {
        (new PhpUploadBodySpool(new Logger('observability', [$this->log]), $this->directory))->discard(
            \strlen($body),
            \hash('sha256', $body),
            \hash('sha256', \substr($body, 0, PhpUploadBodySpool::HEAD_BYTES)),
        );
    }

    private function plant(string $name, string $bytes): string
    {
        $path = $this->directory . '/' . $name;
        \file_put_contents($path, $bytes);

        return $path;
    }
}
