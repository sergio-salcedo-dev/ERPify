<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Monitoring;

use CurlHandle;
use Erpify\Tests\Support\Dig;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Destroying a request's own spool must never reach another in-flight request's: PHP reads an upload's body back
 * out of its spool as it goes, so a spool cut short under a live upload is that upload aborted. Through the running
 * Caddy, a fast upload completes — and discards its own spool — while a second one still streams at the minimum
 * accepted throughput; the second must then be filed with exactly its own digest.
 *
 * The session is the e2e administrator's, seeded by {@see DevStackUploadRows::prepare()}.
 *
 * @internal
 */
#[CoversNothing]
final class UploadSpoolConcurrencyGateTest extends TestCase
{
    private const string ORIGIN = 'https://localhost';

    private const int SIZE = 1_100_000;

    private const int SLOW_BYTES_PER_SECOND = 262_144;

    private static ?string $cookieJar = null;

    private static ?string $session = null;

    /** @var list<string> */
    private array $intents = [];

    /** @var list<string> */
    private array $documents = [];

    public static function setUpBeforeClass(): void
    {
        DevStackUploadRows::prepare();
        $jar = \tempnam(\sys_get_temp_dir(), 'spool-concurrency');
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

    protected function tearDown(): void
    {
        (new DevStackUploadRows())->remove($this->intents, $this->documents);
        parent::tearDown();
    }

    #[Test]
    public function aCompletedUploadLeavesAnotherInFlightUploadsSpoolAlone(): void
    {
        $slowBody = \random_bytes(self::SIZE);
        $fastBody = \random_bytes(self::SIZE);
        $slow = $this->put($this->declare(), $slowBody, self::SLOW_BYTES_PER_SECOND);
        $fast = $this->put($this->declare(), $fastBody, 0);

        $this->runTogether($slow, $fast);

        foreach ([[$fast, $fastBody, 'the fast upload'], [$slow, $slowBody, 'the upload still streaming']] as $run) {
            [$handle, $body, $label] = $run;
            $this->assertSame(201, \curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $label . ' is filed');
            $answer = \json_decode((string) \curl_multi_getcontent($handle), true);
            $this->assertIsArray($answer);
            $this->documents[] = Dig::string($answer, 'data', 'id');
            $this->assertSame(
                \hash('sha256', $body),
                Dig::value($answer, 'data', 'digest'),
                $label . ' kept its bytes',
            );
        }
    }

    private function runTogether(CurlHandle ...$handles): void
    {
        $multi = \curl_multi_init();

        foreach ($handles as $handle) {
            \curl_multi_add_handle($multi, $handle);
        }

        do {
            $status = \curl_multi_exec($multi, $running);

            if ($running > 0) {
                \curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && CURLM_OK === $status);
    }

    private function declare(): string
    {
        $handle = $this->handle('/api/v1/documents/uploads');
        \curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => (string) \json_encode([
                'size' => self::SIZE,
                'retentionReason' => 'technical-file',
                'legalBasis' => 'contract',
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        $answer = \json_decode((string) \curl_exec($handle), true);
        $this->assertIsArray($answer);
        $id = Dig::string($answer, 'data', 'id');
        $this->intents[] = $id;

        return $id;
    }

    private function put(string $upload, string $body, int $bytesPerSecond): CurlHandle
    {
        $handle = $this->handle(\sprintf('/api/v1/documents/uploads/%s/content?filename=spool.bin', $upload));
        \curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_MAX_SEND_SPEED_LARGE => $bytesPerSecond,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'Expect:'],
        ]);

        return $handle;
    }

    private function handle(string $path): CurlHandle
    {
        $handle = \curl_init(self::ORIGIN . $path);

        if (!$handle instanceof CurlHandle || null === self::$cookieJar || '' === self::$cookieJar) {
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
