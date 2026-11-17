<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application\Double;

use Erpify\Shared\Images\Domain\CanonicalImage;
use Erpify\Shared\Images\Domain\Exception\ImageDecodingFailed;
use Erpify\Shared\Images\Domain\ImageProcessor;
use Override;
use RuntimeException;

/**
 * An {@see ImageProcessor} that decodes nothing: every body is refused the way the real one refuses garbage.
 *
 * @internal
 */
final class RefusingImageProcessor implements ImageProcessor
{
    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter") the port's signature
     */
    #[Override]
    public function process(string $bytes, ?string $declaredMediaType = null): CanonicalImage
    {
        throw new ImageDecodingFailed(new RuntimeException('not an image'));
    }
}
