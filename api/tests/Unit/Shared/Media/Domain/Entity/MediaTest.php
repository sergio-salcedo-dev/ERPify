<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Media\Domain\Entity;

use Erpify\Shared\Media\Domain\Entity\Media;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * @internal
 */
#[CoversClass(Media::class)]
final class MediaTest extends TestCase
{
    private const string MEDIA_ID = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    private const string CONTENT_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function testReturnsRawBytesStoredAsString(): void
    {
        $media = Media::create(self::MEDIA_ID, self::CONTENT_HASH, 'image/png', 4, 'PNG.');

        $this->assertSame('PNG.', $media->getRawBytes());
    }

    public function testReadsRawBytesFromHydratedStreamResourceAndCachesThem(): void
    {
        $media = Media::create(self::MEDIA_ID, self::CONTENT_HASH, 'image/png', 9, 'seed');

        // Doctrine hydrates a BLOB column as a stream resource; reproduce that wire shape, which
        // the string-typed `create()` signature cannot express, by writing the property directly.
        $stream = \fopen('php://memory', 'r+');

        if (false === $stream) {
            $this->fail('Could not open an in-memory stream for the test fixture.');
        }

        \fwrite($stream, 'PNG-bytes');
        \rewind($stream);
        (new ReflectionProperty(Media::class, 'rawBytes'))->setValue($media, $stream);

        $this->assertSame('PNG-bytes', $media->getRawBytes());
        // The first read consumes the stream and caches the decoded string; a second read must
        // return the same bytes rather than re-reading the now-exhausted resource.
        $this->assertSame('PNG-bytes', $media->getRawBytes());

        \fclose($stream);
    }

    public function testReadsRawBytesFromAStreamLeftAtEof(): void
    {
        $media = Media::create(self::MEDIA_ID, self::CONTENT_HASH, 'image/png', 9, 'seed');

        $stream = \fopen('php://memory', 'r+');

        if (false === $stream) {
            $this->fail('Could not open an in-memory stream for the test fixture.');
        }

        // Leave the cursor at EOF (no rewind): a Doctrine BLOB resource can arrive already consumed.
        // Reading from the current position would yield '' — the getter must seek to the start.
        \fwrite($stream, 'PNG-bytes');
        (new ReflectionProperty(Media::class, 'rawBytes'))->setValue($media, $stream);

        $this->assertSame('PNG-bytes', $media->getRawBytes());

        \fclose($stream);
    }
}
