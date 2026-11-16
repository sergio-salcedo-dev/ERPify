<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Infrastructure\Http;

use Erpify\Shared\ErrorContract\Domain\Exception\ContentTooLarge;
use Erpify\Shared\ErrorContract\Domain\Exception\LengthRequired;
use Erpify\Shared\Images\Domain\Exception\ImageLengthRequired;
use Erpify\Shared\Images\Domain\Exception\ImageProblemType;
use Erpify\Shared\Images\Domain\Exception\ImageResourceLimitExceeded;
use Erpify\Shared\Images\Infrastructure\Http\ImageRequestBodyReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * The raw-body edge of an image write: the length contract, the cap, the media-type hint and the session lock.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
#[CoversClass(ImageRequestBodyReader::class)]
final class ImageRequestBodyReaderTest extends TestCase
{
    private const int CAP = 64;

    #[Test]
    public function aBodyWhoseLengthIsDeclaredAndEqualIsReadWhole(): void
    {
        $body = $this->reader()->read($this->request(\str_repeat('x', 10), '10'));

        $this->assertSame(\str_repeat('x', 10), $body->bytes);
        $this->assertNull($body->declaredMediaType);
    }

    #[Test]
    public function aBodyOfExactlyTheCapIsAccepted(): void
    {
        $bytes = \str_repeat('x', self::CAP);

        $this->assertSame($bytes, $this->reader()->read($this->request($bytes, (string) self::CAP))->bytes);
    }

    #[Test]
    public function aBodyOfTheCapPlusOneByteIsRefusedAsTooLargeBeforeItIsRead(): void
    {
        $request = $this->request(\str_repeat('x', self::CAP + 1), (string) (self::CAP + 1));

        try {
            $this->reader()->read($request);
            $this->fail('over the cap');
        } catch (ImageResourceLimitExceeded $imageResourceLimitExceeded) {
            $this->assertContains(ContentTooLarge::class, \class_implements($imageResourceLimitExceeded));
            $this->assertSame(ImageProblemType::TOO_LARGE, $imageResourceLimitExceeded->type());
        }
    }

    #[Test]
    public function aDeclaredLengthOverTheCapIsRefusedWithoutReadingABody(): void
    {
        $request = new Request();
        $request->headers->set('Content-Length', (string) (self::CAP + 1));

        $this->expectException(ImageResourceLimitExceeded::class);

        $this->reader()->read($request);
    }

    #[DataProvider('provideALengthThatIsNotTheBytesReadIsRefusedAsLengthRequiredCases')]
    #[Test]
    public function aLengthThatIsNotTheBytesReadIsRefusedAsLengthRequired(string $body, string $declared): void
    {
        try {
            $this->reader()->read($this->request($body, $declared));
            $this->fail('the length is wrong');
        } catch (ImageLengthRequired $imageLengthRequired) {
            $this->assertContains(LengthRequired::class, \class_implements($imageLengthRequired));
            $this->assertSame(ImageProblemType::LENGTH_REQUIRED, $imageLengthRequired->type());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideALengthThatIsNotTheBytesReadIsRefusedAsLengthRequiredCases(): iterable
    {
        yield 'one byte fewer than the body' => [\str_repeat('x', 10), '9'];
        yield 'one byte more than the body' => [\str_repeat('x', 10), '11'];
        yield 'a declared zero over a body' => ['x', '0'];
        yield 'a declared length over an empty body' => ['', '1'];
        yield 'not a number' => ['xx', 'abc'];
        yield 'a signed number' => ['xx', '-2'];
        yield 'an exponent' => ['xx', '2e0'];
        yield 'a number with a space' => ['xx', '2 '];
        yield 'a number with a trailing newline' => ['xx', "2\n"];
    }

    #[Test]
    public function aMissingLengthIsRefusedAsLengthRequired(): void
    {
        $this->expectException(ImageLengthRequired::class);

        $this->reader()->read(new Request(content: 'xx'));
    }

    #[Test]
    public function anEmptyBodyWithADeclaredZeroIsReadAsEmptyForTheProcessorToRefuse(): void
    {
        $this->assertSame('', $this->reader()->read($this->request('', '0'))->bytes);
    }

    #[DataProvider('provideTheMediaTypeIsPassedOnOnlyWhenItIsExactlyOneTheProcessorAcceptsCases')]
    #[Test]
    public function theMediaTypeIsPassedOnOnlyWhenItIsExactlyOneTheProcessorAccepts(string $header, ?string $hint): void
    {
        $request = $this->request('xx', '2');
        $request->headers->set('Content-Type', $header);

        $this->assertSame($hint, $this->reader()->read($request)->declaredMediaType);
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function provideTheMediaTypeIsPassedOnOnlyWhenItIsExactlyOneTheProcessorAcceptsCases(): iterable
    {
        yield 'png' => ['image/png', 'image/png'];
        yield 'jpeg' => ['image/jpeg', 'image/jpeg'];
        yield 'webp' => ['image/webp', 'image/webp'];
        yield 'gif' => ['image/gif', 'image/gif'];
        yield 'an alias nobody registers' => ['image/jpg', null];
        yield 'a binary default' => ['application/octet-stream', null];
        yield 'a form type' => ['multipart/form-data; boundary=x', null];
        yield 'a parameter' => ['image/png; charset=binary', null];
        yield 'a different case' => ['IMAGE/PNG', null];
        yield 'a type outside the allowlist' => ['image/svg+xml', null];
        yield 'a wildcard' => ['image/*', null];
        yield 'a token with padding' => [' image/png', null];
    }

    #[Test]
    public function theSessionLockIsReleasedBeforeTheBodyIsRead(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('seen', true);
        $this->assertTrue($session->isStarted());
        $request = $this->request('xx', '2');
        $request->setSession($session);

        $this->reader()->read($request);

        $this->assertFalse($session->isStarted(), 'a slow PUT must not hold the session lock for the whole stream');
    }

    #[Test]
    public function aRefusedLengthLeavesTheSessionAloneBecauseNothingWasStreamed(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('seen', true);

        $request = new Request(content: 'xx');
        $request->setSession($session);

        try {
            $this->reader()->read($request);
        } catch (ImageLengthRequired) {
            // expected
        }

        $this->assertTrue($session->isStarted());
    }

    private function reader(): ImageRequestBodyReader
    {
        return new ImageRequestBodyReader(self::CAP);
    }

    private function request(string $body, string $declaredLength): Request
    {
        $request = new Request(content: $body);
        $request->headers->set('Content-Length', $declaredLength);

        return $request;
    }
}
