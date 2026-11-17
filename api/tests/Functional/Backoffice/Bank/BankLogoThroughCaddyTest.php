<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\Bank;

use CurlHandle;
use Erpify\Tests\Functional\Shared\Monitoring\DevStackLogs;
use Erpify\Tests\Functional\Shared\Monitoring\DevStackUploadRows;
use Erpify\Tests\Support\Dig;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The bank logo `PUT`, sent through the RUNNING Caddy: what the Caddyfile does to an image body, which no kernel test
 * can show because the kernel never runs a Caddy directive.
 *
 * Two facts, each the reason a matcher exists:
 *  - a valid image over 1 MB is stored whole and answered `200`, so the 1 MB bound every other route carries is NOT
 *    applied to an image write (`@imageWrite` lifts it to `erpify.images.max_input_bytes`);
 *  - a body that ends short of its declared length — the shape Caddy gives PHP when it cuts a body, a clean, shorter
 *    stream — is a `411` and stores nothing, so a truncated image is never filed.
 *
 * The bank is created on the dev stack through the API as the e2e administrator and deleted through the API afterwards,
 * which queues the release of its logo; no other row is touched ({@see DevStackUploadRows}).
 *
 * **What a green does NOT prove.** It observes the development Caddy, not the prod image. The cut body is a client that
 * stops sending (a body Caddy truncates at its own bound is refused by the declared length before it is read, so the
 * two cannot be told apart from outside), and its `411` is read from the access log, because that client is gone before
 * any answer.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversNothing]
final class BankLogoThroughCaddyTest extends TestCase
{
    private const string ORIGIN = 'https://localhost';

    private const int SHORT_BODY_BYTES = 65_536;

    private const int DECLARED_BYTES = 1_500_000;

    /** @var non-empty-string|null */
    private static ?string $cookieJar = null;

    private static ?string $session = null;

    private ?string $bankId = null;

    public static function setUpBeforeClass(): void
    {
        DevStackUploadRows::prepare();

        $jar = \tempnam(\sys_get_temp_dir(), 'bank-logo-probe');
        self::$cookieJar = \is_string($jar) ? $jar : null;
        self::$session = DevStackUploadRows::signIn((string) self::$cookieJar);
    }

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$session) {
            DevStackUploadRows::forgetSession(self::$session);
        }

        if (null !== self::$cookieJar && \is_file(self::$cookieJar)) {
            \unlink(self::$cookieJar);
        }
    }

    #[Override]
    protected function setUp(): void
    {
        if (!\function_exists('imagecreatetruecolor')) {
            $this->fail('GD is needed to build an image over 1 MB; the php image installs it.');
        }

        $token = \bin2hex(\random_bytes(5));
        [$status, $body] = $this->json('POST', '/api/v1/backoffice/banks', [
            'name' => 'Caddy Logo ' . $token,
            'shortName' => 'CL' . \strtoupper($token),
        ]);
        $this->assertSame(201, $status, $body);
        $this->bankId = Dig::string(\json_decode($body, true), 'data', 'id');
    }

    protected function tearDown(): void
    {
        if (null !== $this->bankId) {
            $this->json('DELETE', '/api/v1/backoffice/banks/' . $this->bankId, null);
        }

        parent::tearDown();
    }

    #[Test]
    public function anImageOverOneMegabyteIsStoredWholeAndServedBack(): void
    {
        $image = $this->noisePng(900);
        $this->assertGreaterThan(1_000_000, \strlen($image), 'the probe must exceed the generic 1 MB bound');
        $this->assertLessThanOrEqual(2 * 1024 * 1024 + 700_000, \strlen($image));

        [$status, $body] = $this->putLogo($image);

        $this->assertSame(200, $status, 'the 1 MB bound was applied to an image write: ' . $body);
        $imageId = Dig::string(\json_decode($body, true), 'imageId');

        [$read, , $contentType] = $this->get('/api/v1/images/' . $imageId);
        $this->assertSame(200, $read, 'what was stored is served back');
        $this->assertStringStartsWith('image/', $contentType);

        [, $detail] = $this->get('/api/v1/backoffice/banks/' . $this->bankId);
        $this->assertSame($imageId, Dig::string(\json_decode($detail, true), 'data', 'logoImageId'));
    }

    #[Test]
    public function aBodyThatEndsShortOfItsDeclaredLengthIsA411AndStoresNothing(): void
    {
        $rows = new DevStackUploadRows();
        $before = $rows->imageIds();
        $anchor = 'logo-cut-' . \bin2hex(\random_bytes(8));
        $position = DevStackLogs::accessLogPosition();

        $this->putShortBody($anchor);

        $written = DevStackLogs::accessLogWrittenSince($position, $anchor);
        $this->assertSame(411, $this->statusLoggedFor($written, $anchor), 'a cut image body is refused for its length');
        // The dev worker may drain an earlier probe's released logo meanwhile, so the set may shrink; it must not grow.
        $this->assertSame([], \array_diff($rows->imageIds(), $before), 'a truncated image was filed');

        [, $detail] = $this->get('/api/v1/backoffice/banks/' . $this->bankId);
        $this->assertNull(Dig::value(\json_decode($detail, true), 'data', 'logoImageId'));
    }

    /**
     * @return array{int, string}
     */
    private function putLogo(string $image): array
    {
        $handle = $this->handle(self::ORIGIN . '/api/v1/backoffice/banks/' . $this->bankId . '/logo');
        \curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $image,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => ['Content-Type: image/png', 'Origin: ' . self::ORIGIN, 'Expect:'],
        ]);
        $body = \curl_exec($handle);

        return [\curl_getinfo($handle, CURLINFO_RESPONSE_CODE), \is_string($body) ? $body : ''];
    }

    /**
     * Declares more than it sends and then stops, so the application is handed a stream that ends short.
     *
     * @param non-empty-string $anchor
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter") the read callback's signature is curl's
     */
    private function putShortBody(string $anchor): void
    {
        $sent = 0;
        $handle = $this->handle(self::ORIGIN . '/api/v1/backoffice/banks/' . $this->bankId . '/logo');
        \curl_setopt_array($handle, [
            CURLOPT_UPLOAD => true,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_INFILESIZE => self::DECLARED_BYTES,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_USERAGENT => $anchor,
            CURLOPT_HTTPHEADER => ['Content-Type: image/png', 'Origin: ' . self::ORIGIN, 'Expect:'],
        ]);
        $feed = static function (CurlHandle $curl, mixed $stream, int $want) use (&$sent): string {
            $chunk = \max(0, \min($want, self::SHORT_BODY_BYTES - $sent));
            $sent += $chunk;

            return \str_repeat("\x89", $chunk);
        };
        \curl_setopt($handle, CURLOPT_READFUNCTION, $feed);

        \curl_exec($handle);
    }

    private function statusLoggedFor(string $written, string $anchor): int
    {
        foreach (\explode("\n", $written) as $line) {
            if (\str_contains($line, $anchor) && 1 === \preg_match('/"status":(\d+)/', $line, $matches)) {
                return (int) $matches[1];
            }
        }

        $this->fail("The probe's access-log line records no status.");
    }

    /**
     * An incompressible truecolor image: noise defeats PNG's deflate, so the file is close to its raw size.
     *
     * @param int<1, max> $side
     */
    private function noisePng(int $side): string
    {
        $image = \imagecreatetruecolor($side, $side);
        $this->assertNotFalse($image);

        for ($y = 0; $y < $side; ++$y) {
            for ($x = 0; $x < $side; ++$x) {
                $colour = (\random_int(0, 255) << 16) | (\random_int(0, 255) << 8) | \random_int(0, 255);
                \imagesetpixel($image, $x, $y, $colour);
            }
        }

        \ob_start();
        \imagepng($image, null, 0);

        return (string) \ob_get_clean();
    }

    /**
     * @param non-empty-string          $method
     * @param array<string, mixed>|null $payload
     *
     * @return array{int, string}
     */
    private function json(string $method, string $path, ?array $payload): array
    {
        $handle = $this->handle(self::ORIGIN . $path);
        \curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => null === $payload ? '' : (string) \json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Origin: ' . self::ORIGIN,
            ],
        ]);
        $body = \curl_exec($handle);

        return [\curl_getinfo($handle, CURLINFO_RESPONSE_CODE), \is_string($body) ? $body : ''];
    }

    /**
     * @return array{int, string, string}
     */
    private function get(string $path): array
    {
        $handle = $this->handle(self::ORIGIN . $path);
        \curl_setopt($handle, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        $body = \curl_exec($handle);

        return [
            \curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            \is_string($body) ? $body : '',
            (string) \curl_getinfo($handle, CURLINFO_CONTENT_TYPE),
        ];
    }

    private function handle(string $url): CurlHandle
    {
        $handle = \curl_init($url);

        if (!$handle instanceof CurlHandle || null === self::$cookieJar) {
            throw new RuntimeException('Could not initialise the probe.');
        }

        \curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_COOKIEFILE => self::$cookieJar,
            CURLOPT_COOKIEJAR => self::$cookieJar,
        ]);

        return $handle;
    }
}
