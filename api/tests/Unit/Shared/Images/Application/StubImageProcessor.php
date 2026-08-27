<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application;

use Erpify\Shared\Images\Domain\CanonicalImage;
use Erpify\Shared\Images\Domain\ImageProcessor;

/**
 * Test-double pattern (not an alternative implementation of the decode/normalize/encode
 * algorithm) — answers with a fixed {@see CanonicalImage} and records the arguments it received,
 * per `docs/rules/testing.md` → "Test double naming convention".
 */
final class StubImageProcessor implements ImageProcessor
{
    /** @var list<array{bytes: string, declaredMediaType: ?string}> */
    public array $receivedCalls = [];

    public function __construct(private readonly CanonicalImage $response)
    {
    }

    public function process(string $bytes, ?string $declaredMediaType = null): CanonicalImage
    {
        $this->receivedCalls[] = ['bytes' => $bytes, 'declaredMediaType' => $declaredMediaType];

        return $this->response;
    }
}
