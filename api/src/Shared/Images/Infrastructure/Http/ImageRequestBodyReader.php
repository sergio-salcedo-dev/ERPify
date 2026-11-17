<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Infrastructure\Http;

use Erpify\Shared\Images\Domain\Exception\ImageLengthRequired;
use Erpify\Shared\Images\Domain\Exception\ImageResourceLimitExceeded;
use Erpify\Shared\Images\Infrastructure\ImagePreflightGuard;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the raw body of an image write into bytes. Called by a consumer's controller AFTER it has authenticated,
 * authorised, validated the owner's id syntax and found the owner, so a refusal on any of those never costs a read.
 *
 * **`Content-Length` is mandatory and must equal the bytes read.** A body the edge cut at its bound (Caddy applies
 * one as the body is read and promises no 413) reaches PHP as a clean, shorter end of stream — indistinguishable
 * from a complete shorter image, and a truncated image can still decode. The declared length is the only thing that
 * tells the two apart, so a missing header, a malformed one, and one that is not the number of bytes read are all
 * refused before anything is stored.
 *
 * **The read is capped before it allocates.** A declared length above the byte cap is refused without reading a
 * byte; otherwise at most one byte past the declared length is read, which is enough to see a body longer than it
 * said and never more memory than the cap.
 *
 * **The session lock is not held while the body streams.** The session is saved before the read, so a slow `PUT`
 * does not make every other request of the same caller wait behind it. The session may be opened again afterwards —
 * the security layer saves the token on the response — which is why no claim is made about what writes it later.
 *
 * **The declared media type is a hint, not an input.** It is passed on only when it is exactly one of the types the
 * processor accepts; anything else (`application/octet-stream`, `image/jpg`, a parameter, a form type) is dropped,
 * because the processor refuses a declaration that contradicts the detected format and a label outside the
 * allowlist must not turn a good image into a refusal. A label that IS on the allowlist but names the wrong format
 * (`image/png` over JPEG bytes) is passed on and refused as unsupported. The served type always comes from detection.
 */
final readonly class ImageRequestBodyReader
{
    private const string LENGTH_PATTERN = '/^\d+$/D';

    /** More significant digits than this cannot be held in an int, and no cap is that large. */
    private const int MAX_DECLARED_DIGITS = 18;

    public function __construct(
        #[Autowire(param: 'erpify.images.max_input_bytes')]
        private int $maxBytes,
    ) {
    }

    /**
     * @throws ImageLengthRequired        the length is missing, malformed or not the bytes read
     * @throws ImageResourceLimitExceeded the declared length is above the byte cap, however many digits it has
     */
    public function read(Request $request): ImageRequestBody
    {
        $declared = $this->declaredLength($request);

        if ($declared > $this->maxBytes) {
            throw ImageResourceLimitExceeded::inputTooLarge();
        }

        $this->releaseSession($request);

        $bytes = $this->readAtMost($request, $declared + 1);

        if (\strlen($bytes) !== $declared) {
            throw ImageLengthRequired::notEqualToBytesRead();
        }

        return new ImageRequestBody($bytes, $this->cleanMediaTypeHint($request->headers->get('Content-Type')));
    }

    private function declaredLength(Request $request): int
    {
        $header = $request->headers->get('Content-Length');

        if (null === $header) {
            throw ImageLengthRequired::missing();
        }

        if (1 !== \preg_match(self::LENGTH_PATTERN, $header)) {
            throw ImageLengthRequired::malformed();
        }

        if (\strlen(\ltrim($header, '0')) > self::MAX_DECLARED_DIGITS) {
            throw ImageResourceLimitExceeded::inputTooLarge();
        }

        return (int) $header;
    }

    private function releaseSession(Request $request): void
    {
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->save();
        }
    }

    private function readAtMost(Request $request, int $limit): string
    {
        $stream = $request->getContent(true);
        $bytes = \stream_get_contents($stream, $limit);

        return false === $bytes ? '' : $bytes;
    }

    private function cleanMediaTypeHint(?string $contentType): ?string
    {
        if (null === $contentType) {
            return null;
        }

        return isset(ImagePreflightGuard::SUPPORTED_MEDIA_TYPES[$contentType]) ? $contentType : null;
    }
}
