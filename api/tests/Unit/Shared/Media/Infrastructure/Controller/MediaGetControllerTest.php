<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Media\Infrastructure\Controller;

use Erpify\Shared\Http\Infrastructure\ContentAddressedHttpCache;
use Erpify\Shared\Media\Domain\Exception\MediaNotFoundException;
use Erpify\Shared\Media\Infrastructure\Controller\MediaGetController;
use Erpify\Tests\Unit\Shared\Media\Application\RecordingMediaRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @internal
 */
#[CoversClass(MediaGetController::class)]
#[CoversClass(MediaNotFoundException::class)]
final class MediaGetControllerTest extends TestCase
{
    private const string CONTENT_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function testThrowsMediaNotFoundWhenNoRowMatchesTheContentHash(): void
    {
        // A missing media is a request-target error routed through the RFC 9457 pipeline
        // (MediaNotFoundException implements NotFound → 404 Problem Details), never a hand-rolled
        // plain-text Response that bypasses the contract.
        $controller = new MediaGetController(new RecordingMediaRepository(), new ContentAddressedHttpCache());

        $this->expectException(MediaNotFoundException::class);
        $this->expectExceptionMessageMatches('/' . self::CONTENT_HASH . '/');

        $controller(new Request(), self::CONTENT_HASH);
    }
}
