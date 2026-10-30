<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Shared\Event\Infrastructure\Cli\AssertTransactionalTransportCommand;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * The container entrypoint still asks the process whether `async` keeps its after-commit guarantee, and
 * still refuses to boot on a "no".
 *
 * `MESSENGER_TRANSPORT_DSN` is interpolated per service in the compose files, so the only reader that sees
 * the value a process will actually use is that process. The command is the reader and the entrypoint is the
 * single place every service running the image passes through; delete the call and the command still exists,
 * its unit test still passes, and a deployment pointing `async` at a broker boots clean.
 *
 * The call must be the WHOLE line `if ! php bin/console <name>[ --no-interaction]; then` — a trailing
 * `|| true` or `&& …` would make the failure branch unreachable — and it must sit at the same nesting depth
 * as the readiness announcement, so wrapping it in a condition that can skip it goes red. Depth is counted
 * from the shell's block keywords (`if`/`case`/`for`/`while`/`until` against `fi`/`esac`/`done`).
 *
 * A green proves the entrypoint carries an unconditional, fatal call to the command under the name the class
 * declares. It reads text and never runs the shell: it does not prove the entrypoint runs (a service started
 * with `--entrypoint` skips it), and a call assembled through a variable or a sourced file simply fails to
 * match.
 *
 * @internal
 */
#[CoversNothing]
final class AsyncTransportBootAssertionGateTest extends TestCase
{
    private const string READY = "echo 'PHP app ready!'";

    #[Test]
    public function theEntrypointRefusesToBootWhenTheAssertionFails(): void
    {
        $scan = $this->scan();

        $this->assertNotNull(
            $scan['call'],
            \sprintf(
                'api/frankenphp/docker-entrypoint.sh no longer holds the whole line '
                . '`if ! php bin/console %s --no-interaction; then`.',
                $this->commandName(),
            ),
        );
        $this->assertTrue($scan['fatal'], 'The assertion is called but its failure branch no longer exits 1.');
    }

    #[Test]
    public function theAssertionIsUnconditionalAndRunsBeforeTheContainerReportsReady(): void
    {
        $scan = $this->scan();

        $this->assertNotNull($scan['call'], 'The entrypoint does not call the assertion.');
        $this->assertNotNull($scan['ready'], 'The entrypoint no longer announces readiness; this gate anchors on it.');
        $this->assertLessThan($scan['ready']['index'], $scan['call']['index'], 'The assertion must precede readiness.');
        $this->assertSame(
            $scan['ready']['depth'],
            $scan['call']['depth'],
            'The assertion sits inside a block the readiness line does not, so it can be skipped.',
        );
    }

    /**
     * @return array{
     *     call: array{index: int, depth: int}|null,
     *     ready: array{index: int, depth: int}|null,
     *     fatal: bool
     * }
     */
    private function scan(): array
    {
        $call = '/^if ! php bin\/console ' . \preg_quote($this->commandName(), '/') . '( --no-interaction)?; then$/';
        $result = ['call' => null, 'ready' => null, 'fatal' => false];
        $depth = 0;
        $inCall = false;

        foreach ($this->liveEntrypointLines() as $index => $line) {
            if (1 === \preg_match($call, $line)) {
                $result['call'] = ['index' => $index, 'depth' => $depth];
                $inCall = true;
            } elseif ($inCall && 'exit 1' === $line) {
                $result['fatal'] = true;
            }

            if (self::READY === $line) {
                $result['ready'] = ['index' => $index, 'depth' => $depth];
            }

            if (1 === \preg_match('/^(if|case|for|while|until)\b/', $line)) {
                ++$depth;
            } elseif (1 === \preg_match('/^(fi|esac|done)$/', $line)) {
                --$depth;
                $inCall = false;
            }
        }

        return $result;
    }

    /**
     * Non-comment, trimmed, non-empty lines: a call that only exists in a comment is no call.
     *
     * @return list<string>
     */
    private function liveEntrypointLines(): array
    {
        $path = \dirname(__DIR__, 3) . '/frankenphp/docker-entrypoint.sh';
        $source = \file_get_contents($path);

        if (false === $source) {
            $this->fail('Cannot read ' . $path);
        }

        $lines = [];

        foreach (\explode("\n", $source) as $line) {
            $line = \trim($line);

            if ('' === $line || \str_starts_with($line, '#')) {
                continue;
            }

            $lines[] = $line;
        }

        return $lines;
    }

    private function commandName(): string
    {
        $attributes = (new ReflectionClass(AssertTransactionalTransportCommand::class))
            ->getAttributes(AsCommand::class)
        ;
        $this->assertCount(1, $attributes);

        return $attributes[0]->newInstance()->name;
    }
}
