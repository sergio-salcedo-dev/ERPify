<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Http;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The edge bound on a request body, asserted as an EFFECT: a body past `request_body { max_size }` in
 * `frankenphp/Caddyfile` never reaches Symfony whole, on every spelling that routes to the API.
 *
 * The oracle is the anonymous login route, because it is the one an attacker reaches without a session and
 * because it reads the body whole: `json_login` decodes it, so a complete body — however large its password —
 * answers the uniform 401, while a truncated one is not JSON and answers 400 (or Caddy refuses it with 413
 * before PHP sees it). A 401 past the bound is therefore the regression: the whole body arrived.
 *
 * Both spellings are probed because the bound once keyed on an `/api*` path and Symfony routes on
 * REQUEST_URI, so `/index.php/api/…` reached the same route with no bound at all.
 *
 * It sends real requests through Caddy rather than through the kernel, because the subject is a Caddy
 * directive and the kernel never runs it.
 *
 * **What a green does NOT prove.** It says nothing about a route that streams its body, about a body sent to
 * the PWA or the hub (both routed before the bound), or about a deployment whose
 * `{$CADDY_SERVER_EXTRA_DIRECTIVES}` or `{$CADDY_EXTRA_CONFIG}` adds its own `request_body`. It proves the
 * bound sits between 0.9 MB and 2 MB, not where exactly.
 *
 * @internal
 */
#[CoversNothing]
final class RequestBodyBoundGateTest extends TestCase
{
    /** Below the 1,000,000-byte bound (`1MB` is SI in Caddy), with room for the JSON around the password. */
    private const int UNDER_BOUND_PASSWORD_BYTES = 900_000;

    private const int OVER_BOUND_PASSWORD_BYTES = 2_000_000;

    private const int CREDENTIALS_REFUSED = 401;

    #[Test]
    #[DataProvider('provideLoginSpellingCases')]
    public function aBodyUnderTheBoundReachesTheLoginWhole(string $path): void
    {
        $status = $this->postLogin($path, self::UNDER_BOUND_PASSWORD_BYTES);

        // The control the bounded case leans on: without it, a 400 below could be any refusal rather than a
        // truncation, and the gate would pass over a route that never read the body at all.
        $this->assertSame(self::CREDENTIALS_REFUSED, $status, \sprintf(
            'A %d-byte login body to %s was expected to arrive whole and be refused as bad credentials (401), '
                . 'which is what makes the over-bound case below mean anything. Got %d.',
            self::UNDER_BOUND_PASSWORD_BYTES,
            $path,
            $status,
        ));
    }

    #[Test]
    #[DataProvider('provideLoginSpellingCases')]
    public function aBodyOverTheBoundNeverReachesTheLoginWhole(string $path): void
    {
        $status = $this->postLogin($path, self::OVER_BOUND_PASSWORD_BYTES);

        $this->assertNotSame(self::CREDENTIALS_REFUSED, $status, \sprintf(
            'A %d-byte login body to %s reached Symfony whole: an anonymous caller can make a worker read as '
                . 'much as it sends. Check the `request_body` bound in frankenphp/Caddyfile and that it still '
                . 'covers every request the front controller serves.',
            self::OVER_BOUND_PASSWORD_BYTES,
            $path,
        ));
        $this->assertContains($status, [400, 413], \sprintf(
            'A %d-byte login body to %s was expected to be truncated (400, not JSON) or refused at the edge '
                . '(413). Got %d, which this gate cannot read as either.',
            self::OVER_BOUND_PASSWORD_BYTES,
            $path,
            $status,
        ));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideLoginSpellingCases(): iterable
    {
        yield 'the route as the client names it' => ['/api/v1/backoffice/login'];

        yield 'the same route through the front controller' => ['/index.php/api/v1/backoffice/login'];
    }

    private function postLogin(string $path, int $passwordBytes): int
    {
        $handle = \curl_init('https://localhost' . $path);

        if (false === $handle) {
            throw new RuntimeException('Could not initialise the probe.');
        }

        // A fresh address per probe, so the per-address login throttle can never be what answers.
        $body = \sprintf(
            '{"email":"body-bound-%s@erpify.test","password":"%s"}',
            \bin2hex(\random_bytes(8)),
            \str_repeat('a', $passwordBytes),
        );

        // Verification is off because the dev certificate is self-signed and the subject of this test is
        // what Caddy lets through, not what it presents. The request never leaves the container.
        \curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                // The login refuses a cross-origin POST before the firewall reads the body.
                'Origin: https://localhost',
                // curl would otherwise wait for a 100-continue on a large body.
                'Expect:',
            ],
        ]);

        $response = \curl_exec($handle);
        $status = (int) \curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        // A server that answers before reading the whole body closes the connection under the upload, which
        // curl reports as a send failure AFTER it has the status line — so a status wins over the error.
        if (false === $response && 0 === $status) {
            throw new RuntimeException(\sprintf('The probe could not reach Caddy: %s', \curl_error($handle)));
        }

        return $status;
    }
}
