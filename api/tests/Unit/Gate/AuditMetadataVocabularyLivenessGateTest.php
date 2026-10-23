<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Shared\Audit\Domain\AuditMetadataKey;
use Erpify\Tests\Support\ApiSourceFiles;
use Erpify\Tests\Support\PhpSource;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Every key of the `audit_log.metadata` vocabulary is still written by something under `api/src`.
 *
 * **The other direction is not a gate, and that is the design.** A key nobody declared is refused by
 * `AuditLogEntry::create()` the first time it is written, wherever its producer computed it — which a sweep
 * of source text could not promise, because three producers assemble their keys away from the call. What no
 * runtime check can see is a key that STOPPED being written: its shape stays admitted, so the vocabulary
 * would go on licensing a value nobody decided to keep. That is the half this reads.
 *
 * A key counts as written when the tree names it outside its declaration — as the enum case
 * (`AuditMetadataKey::Changes`), as the literal array key a producer spells (`'affected_rows' =>`), or as the
 * subscript it assigns (`$diff['operation'] = …`, which is how the change-capture listener stamps the write
 * kind beside the diff). A subscript that is only READ (`$m['unlocked'] === true`) is not a write.
 *
 * **What a green does NOT prove.** It is text over comment-stripped sources, so a literal `'operation' =>` in
 * an unrelated log context keeps that key alive — the tree holds three such — and a key written only through
 * a variable is invisible. It never proves a key is written to `audit_log` rather than to a sibling array,
 * and it says nothing about the shape: whether the shape still admits what the producer writes is
 * `AuditLogEntryMetadataVocabularyTest`'s claim, which holds one written value per key.
 *
 * @internal
 */
#[CoversNothing]
final class AuditMetadataVocabularyLivenessGateTest extends TestCase
{
    /** The declaration itself names every key, so it cannot testify that any of them is written. */
    private const string DECLARATION = 'Shared/Audit/Domain/AuditMetadataKey.php';

    public function testEveryKeyOfTheVocabularyIsStillWrittenUnderSrc(): void
    {
        $this->assertSame([], $this->unwrittenKeysIn($this->sources()), \sprintf(
            'These keys are admitted by %s but nothing under api/src writes them any more. Delete the case, '
            . 'or the vocabulary goes on admitting a value nobody decided to keep.',
            self::DECLARATION,
        ));
    }

    /**
     * Falsifiability: each spelling keeps a key alive on its own, a key named only inside a comment or only
     * inside its declaration does not, and the sweep therefore reports it.
     */
    public function testAKeyNamedOnlyInACommentOrItsDeclarationIsReportedUnwritten(): void
    {
        $everyOtherKey = \implode("\n", \array_map(
            static fn (AuditMetadataKey $key): string => \sprintf("        '%s' => 1,", $key->value),
            \array_filter(
                AuditMetadataKey::cases(),
                static fn (AuditMetadataKey $key): bool => !\in_array(
                    $key,
                    [
                        AuditMetadataKey::Resweep,
                        AuditMetadataKey::Operation,
                        AuditMetadataKey::LockedUntil,
                        AuditMetadataKey::Unlocked,
                        AuditMetadataKey::MalformedRoles,
                    ],
                    true,
                ),
            ),
        ));
        $sources = [
            'Producer.php' => "<?php\nreturn [\n" . $everyOtherKey . "\n];\n",
            'ByCase.php' => "<?php\n\$m[AuditMetadataKey::Operation->value] = 'UPDATED';\n",
            'Comment.php' => "<?php\n// metadata: ['resweep' => true]\n",
            'Subscript.php' => "<?php\n\$m['unlocked'] = true;\n",
            'Read.php' => "<?php\nreturn \$m['malformed_roles'] === 0 && \$m['malformed_roles'] == 0;\n",
            self::DECLARATION => "<?php\nenum AuditMetadataKey: string { case LockedUntil = 'lockedUntil'; }\n"
                . "\$x = ['lockedUntil' => 1];\n",
        ];

        $this->assertSame(['resweep', 'lockedUntil', 'malformed_roles'], $this->unwrittenKeysIn($sources));
    }

    /**
     * @param iterable<string, string> $sources raw PHP, keyed by path relative to `api/src`
     *
     * @return list<string>
     */
    private function unwrittenKeysIn(iterable $sources): array
    {
        $code = '';

        foreach ($sources as $path => $source) {
            if (self::DECLARATION !== $path) {
                $code .= PhpSource::withoutComments($source) . "\n";
            }
        }

        $unwritten = [];

        foreach (AuditMetadataKey::cases() as $key) {
            $byCase = \str_contains($code, 'AuditMetadataKey::' . $key->name);
            $quoted = "'" . \preg_quote($key->value, '/') . "'";
            $byLiteral = 1 === \preg_match('/' . $quoted . '\s*=>|\[\s*' . $quoted . '\s*\]\s*=(?!=)/', $code);

            if (!$byCase && !$byLiteral) {
                $unwritten[] = $key->value;
            }
        }

        return $unwritten;
    }

    /**
     * @return iterable<string, string>
     */
    private function sources(): iterable
    {
        $root = ApiSourceFiles::root();

        foreach (ApiSourceFiles::phpFiles($root) as $file) {
            $source = \file_get_contents($file->getPathname());

            if (false === $source) {
                throw new RuntimeException(
                    \sprintf('Could not read %s; the sweep would skip it.', $file->getPathname()),
                );
            }

            yield \ltrim(\str_replace($root, '', $file->getPathname()), '/') => $source;
        }
    }
}
