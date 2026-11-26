<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\ApiSourceFiles;
use PhpToken;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A console command never prints a throwable's own message; it prints `ThrowableSummary::of()`.
 *
 * A database driver quotes the values involved in a failure (`DETAIL: Key (email)=(…)`, a whole failing row), a
 * command's output goes to the container log with no TTL and no erasure path, and the commands of this kind run
 * against the very identifier, address or account whose handling they report on. Eleven sites printed the raw
 * message before one sweep converted them; nothing stopped the twelfth.
 *
 * What a green proves: no file under an `Infrastructure/Cli/` directory of `api/src` calls `getMessage()` on
 * anything. It reads tokens, so a call in a comment or a string is no call.
 *
 * What it does not prove: a message reached through `(string) $throwable`, `getPrevious()->getMessage()` held in a
 * variable named elsewhere, a callable array, or a command living outside a `Cli/` directory. It also never judges
 * that `ThrowableSummary` masks enough — its own docblock names what it lets through.
 *
 * @internal
 */
#[CoversNothing]
final class ConsoleFailureMessageGateTest extends TestCase
{
    /** A floor, so the sweep itself is falsifiable: a walk narrowed to nothing would pass over an empty set. */
    private const int MIN_CLI_FILES = 15;

    #[Test]
    public function noConsoleCommandPrintsAThrowablesOwnMessage(): void
    {
        $root = ApiSourceFiles::root();
        $swept = 0;
        $offenders = [];

        foreach (ApiSourceFiles::phpFiles($root) as $file) {
            $path = \str_replace('\\', '/', $file->getPathname());

            if (!\str_contains($path, '/Infrastructure/Cli/')) {
                continue;
            }

            ++$swept;
            $source = \file_get_contents($file->getPathname());
            $this->assertIsString($source, $path . ' could not be read, so it was never swept');

            if ($this->callsGetMessage($source)) {
                $offenders[] = \substr($path, \strlen($root) + 1);
            }
        }

        $this->assertGreaterThanOrEqual(self::MIN_CLI_FILES, $swept, 'the sweep reached too few command files');
        $this->assertSame(
            [],
            $offenders,
            "a console command prints a throwable's own message; use ThrowableSummary::of() instead",
        );
    }

    #[Test]
    public function theEngineSeesACallAndIgnoresProseAndStrings(): void
    {
        $this->assertTrue($this->callsGetMessage('<?php $io->error($e->getMessage());'));
        $this->assertTrue($this->callsGetMessage('<?php echo $e?->getMessage();'));
        $this->assertTrue($this->callsGetMessage("<?php echo \$e\n    ->getMessage();"));
        $this->assertFalse($this->callsGetMessage('<?php // $e->getMessage()'));
        $this->assertFalse($this->callsGetMessage('<?php $s = "->getMessage()";'));
        $this->assertFalse($this->callsGetMessage('<?php /** see $e->getMessage() */ $x = 1;'));
        $this->assertFalse($this->callsGetMessage('<?php echo ThrowableSummary::of($e);'));
    }

    private function callsGetMessage(string $source): bool
    {
        $tokens = \array_values(\array_filter(
            PhpToken::tokenize($source),
            static fn (PhpToken $token): bool => !$token->isIgnorable(),
        ));

        return \array_any(
            $tokens,
            fn (PhpToken $token, int $position): bool => $token->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])
                && $this->isGetMessage($tokens[$position + 1] ?? null),
        );
    }

    private function isGetMessage(?PhpToken $token): bool
    {
        return $token instanceof \PhpToken && $token->is(T_STRING) && 'getmessage' === \strtolower($token->text);
    }
}
