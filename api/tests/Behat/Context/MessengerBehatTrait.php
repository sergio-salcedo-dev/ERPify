<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use RuntimeException;

/**
 * Shared helpers for Doctrine Messenger queue, Mailpit, and console commands.
 *
 * @internal
 */
trait MessengerBehatTrait
{
    private function messengerBinConsole(): string
    {
        return dirname(__DIR__, 3) . '/bin/console';
    }

    /**
     * @param string ...$args Arguments after bin/console (e.g. dbal:run-sql, 'DELETE ...')
     */
    private function runDevConsole(string ...$args): void
    {
        $console = $this->messengerBinConsole();
        $escaped = array_map(
            static fn (string $a): string => escapeshellarg($a),
            $args,
        );

        $cmd = sprintf(
            'php %s %s --env=dev --no-ansi 2>&1',
            escapeshellarg($console),
            implode(' ', $escaped),
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException(
                sprintf("Console command failed (exit %d):\n%s\nCommand: %s", $exitCode, implode("\n", $output), $cmd),
            );
        }
    }

    private function purgeMessengerDoctrineQueue(): void
    {
        $this->runDevConsole('dbal:run-sql', 'DELETE FROM messenger_messages');
    }

    private function clearMailpitInbox(): void
    {
        $base = rtrim(getenv('MAILPIT_API_BASE_URL') ?: 'http://mailpit:8025', '/');
        $ch = curl_init($base . '/api/v1/messages');
        if ($ch === false) {
            return;
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_exec($ch);
    }

    private function consumePendingAsyncMessengerMessages(): void
    {
        // APP_DEBUG=0 avoids a dev-only TraceableEventDispatcher warning when the worker stops.
        $console = escapeshellarg($this->messengerBinConsole());
        $cmd = sprintf(
            'APP_DEBUG=0 php %s messenger:consume async --limit=20 --time-limit=5 -n --env=dev --no-ansi 2>&1',
            $console,
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException(
                sprintf("messenger:consume failed (exit %d):\n%s\nCommand: %s", $exitCode, implode("\n", $output), $cmd),
            );
        }
    }

    private function assertMessengerTransportCount(string $transport, int $expected): void
    {
        $console = $this->messengerBinConsole();
        $cmd = sprintf(
            'php %s messenger:stats %s --format=json --env=dev --no-ansi 2>&1',
            escapeshellarg($console),
            escapeshellarg($transport),
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException(
                sprintf("messenger:stats failed (exit %d):\n%s", $exitCode, implode("\n", $output)),
            );
        }

        $raw = implode("\n", $output);
        if (!preg_match('/\{[\s\S]*\}/', $raw, $m)) {
            throw new RuntimeException(sprintf('Could not parse JSON from messenger:stats: %s', $raw));
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($m[0], true, 512, JSON_THROW_ON_ERROR);
        $count = (int) ($decoded['transports'][$transport]['count'] ?? -1);

        if ($count !== $expected) {
            throw new RuntimeException(
                sprintf('Expected messenger transport %s count %d, got %d.', $transport, $expected, $count),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function httpGetJson(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($errno !== 0 || !is_string($body)) {
            throw new RuntimeException(sprintf('HTTP GET failed for %s (curl errno %s)', $url, (string) $errno));
        }

        if ($code !== 200) {
            throw new RuntimeException(sprintf('HTTP GET %s returned %d: %s', $url, $code, $body));
        }

        /** @var array<string, mixed> */
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    private function assertDomainEventNameFormat(string $eventName): void
    {
        if (!preg_match('/^[a-z0-9._]+$/i', $eventName)) {
            throw new RuntimeException(sprintf('Invalid event name: %s', $eventName));
        }
    }
}
