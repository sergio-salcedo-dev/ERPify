<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use RuntimeException;

/**
 * Runs `scripts/deploy/lib/prod-env.sh`, the one reader of a prod env file that make and every deploy script
 * share, so a gate pins what it ANSWERS rather than a second implementation of what it should answer.
 *
 * The child gets PATH and nothing else from the suite's environment: the script lets a shell-exported
 * `CADDY_SERVER_EXTRA_DIRECTIVES` beat the file, as Compose does, so an ambient value would otherwise decide
 * the profile under test. A caller passes such a variable explicitly when that precedence is the subject —
 * an EMPTY one through `$emptyExports`, because proc_open drops an environment entry whose value is empty.
 */
final class ProdEnvScript
{
    private const string SCRIPT = 'scripts/deploy/lib/prod-env.sh';

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $environment
     * @param list<string>          $emptyExports
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    public static function run(
        string $repositoryRoot,
        array $arguments,
        array $environment = [],
        array $emptyExports = [],
    ): array {
        $process = \proc_open(
            [
                'env',
                ...\array_map(static fn (string $name): string => $name . '=', $emptyExports),
                'bash',
                $repositoryRoot . '/' . self::SCRIPT,
                ...$arguments,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => (string) \getenv('PATH'), ...$environment],
        );

        $out = $pipes[1] ?? null;
        $err = $pipes[2] ?? null;

        if (!\is_resource($process) || !\is_resource($out) || !\is_resource($err)) {
            throw new RuntimeException('Cannot start bash for ' . self::SCRIPT);
        }

        $stdout = (string) \stream_get_contents($out);
        $stderr = (string) \stream_get_contents($err);
        \fclose($out);
        \fclose($err);

        return ['exit' => \proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
