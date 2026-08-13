<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use Erpify\Shared\Audit\Application\AuditLogger;
use ReflectionClass;
use RuntimeException;

/**
 * Resolution engine behind the audit-evidence-action gate: it derives every audit action `src` declares as a
 * constant, reads their declared classification from `api/.audit-evidence-actions`, and reports the
 * disagreements with {@see \Erpify\Shared\Audit\Domain\AuditErasureEvidence}.
 *
 * Split from the gate test the way {@see PersistentTransportPolicy} is, so the derivation is exercisable
 * against a fixture directory without a dirty line ever existing in the real registry.
 *
 * The universe is derived, never listed: a class whose constructor takes {@see AuditLogger} is a writer of
 * audit rows, and the token it writes is a string constant on that class. Deriving it is the whole point —
 * a hand-maintained list of actions would go stale in the same silence the registry exists to break.
 *
 * @internal test support
 */
final readonly class AuditEvidenceActions
{
    public const string EVIDENCE = 'evidence';

    public const string ORDINARY = 'ordinary';

    /**
     * An action token: the uppercase, underscore-separated literal persisted verbatim in `audit_log.action`.
     * Two characters minimum, so a one-letter constant used for something else cannot pass as an action.
     */
    private const string ACTION_TOKEN = '/^[A-Z][A-Z0-9_]+$/';

    private const string REGISTRY = '.audit-evidence-actions';

    public function __construct(private string $apiRoot)
    {
    }

    /**
     * `api/` resolved from the gate's own directory, so the gate holds regardless of the working directory
     * the runner was invoked from.
     */
    public static function fromGateLocation(string $gateDirectory): self
    {
        return new self(\dirname($gateDirectory, 4));
    }

    public function registryPath(): string
    {
        return $this->apiRoot . '/' . self::REGISTRY;
    }

    /**
     * The verdict is validated rather than compared, and that is the difference between a gate and a
     * decoration: every comparison downstream is `self::EVIDENCE === $verdict`, so an unrecognised spelling
     * — `Evidence`, `evidenc`, a trailing comment — would fall through to "ordinary" in silence, and the
     * silence points at DELETION. A registry whose only unknown value means "prunable" fails open in the one
     * direction it exists to close. A duplicate key is refused for the same reason: last-wins lets a later
     * line shadow an earlier `evidence` with nothing to read in the diff.
     *
     * @return array<string, string> action token => {@see self::EVIDENCE} or {@see self::ORDINARY}
     */
    public function classification(): array
    {
        $classification = [];

        foreach (AllowlistFile::entries($this->registryPath()) as $entry) {
            $parts = \array_map(\trim(...), \explode('=>', $entry));

            if (2 !== \count($parts) || '' === $parts[0]) {
                throw new RuntimeException(\sprintf(
                    'Malformed line in %s: "%s". Expected `<ACTION_TOKEN> => evidence|ordinary`.',
                    self::REGISTRY,
                    $entry,
                ));
            }

            [$token, $verdict] = $parts;

            if (self::EVIDENCE !== $verdict && self::ORDINARY !== $verdict) {
                throw new RuntimeException(\sprintf(
                    'Unrecognised classification for "%s" in %s: "%s". Write exactly `%s` or `%s` — anything '
                    . 'else would read as ordinary, and ordinary means the prune deletes the row.',
                    $token,
                    self::REGISTRY,
                    $verdict,
                    self::EVIDENCE,
                    self::ORDINARY,
                ));
            }

            if (\array_key_exists($token, $classification)) {
                throw new RuntimeException(\sprintf(
                    'Duplicate registry line for "%s" in %s: the later classification silently shadows the '
                    . 'earlier one.',
                    $token,
                    self::REGISTRY,
                ));
            }

            $classification[$token] = $verdict;
        }

        return $classification;
    }

    /**
     * Every action token declared as a constant on a class that writes audit rows, mapped to the
     * `Fqcn::CONSTANT` sites declaring it — the sites travel with the token so a failure names where to go.
     *
     * @return array<string, list<string>>
     */
    public function actionsInSource(): array
    {
        $actions = [];

        foreach ($this->auditWriters() as $writer) {
            foreach ((new ReflectionClass($writer))->getReflectionConstants() as $constant) {
                $value = $constant->getValue();

                if (!\is_string($value)) {
                    continue;
                }

                if (1 !== \preg_match(self::ACTION_TOKEN, $value)) {
                    continue;
                }

                $actions[$value][] = $writer . '::' . $constant->getName();
            }
        }

        \ksort($actions);

        return $actions;
    }

    /**
     * Tokens the registry declares as evidence.
     *
     * @return list<string>
     */
    public function declaredEvidence(): array
    {
        $evidence = \array_keys(\array_filter(
            $this->classification(),
            static fn (string $verdict): bool => self::EVIDENCE === $verdict,
        ));
        \sort($evidence);

        return $evidence;
    }

    /**
     * The four ways the registry, the tree and the closed set can disagree. Pure, and over injected maps
     * rather than over the real tree, so every branch is reachable from a synthetic input — a rule whose red
     * nobody can provoke is indistinguishable from a rule that cannot fail.
     *
     * @param array<string, list<string>> $inSource       token => declaring `Fqcn::CONSTANT` sites
     * @param array<string, string>       $classification token => verdict
     * @param list<string>                $exemptActions  the closed set the pruner actually binds
     *
     * @return array{
     *     unclassified: list<string>,
     *     stale: list<string>,
     *     exemptButOrdinary: list<string>,
     *     evidenceButNotExempt: list<string>,
     * }
     */
    public static function disagreements(array $inSource, array $classification, array $exemptActions): array
    {
        $declaredEvidence = \array_keys(\array_filter(
            $classification,
            static fn (string $verdict): bool => self::EVIDENCE === $verdict,
        ));

        return [
            'unclassified' => self::sorted(\array_diff(\array_keys($inSource), \array_keys($classification))),
            'stale' => self::sorted(\array_diff(\array_keys($classification), \array_keys($inSource))),
            'exemptButOrdinary' => self::sorted(\array_diff($exemptActions, $declaredEvidence)),
            'evidenceButNotExempt' => self::sorted(\array_diff($declaredEvidence, $exemptActions)),
        ];
    }

    /**
     * @param array<array-key, string> $values
     *
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        $sorted = \array_values($values);
        \sort($sorted);

        return $sorted;
    }

    /**
     * Every concrete class under `src` whose constructor takes an {@see AuditLogger} — the closed set of
     * classes that can put a token in `audit_log.action` by naming it.
     *
     * @return list<class-string>
     */
    private function auditWriters(): array
    {
        $root = $this->apiRoot . '/src';
        $writers = [];

        foreach (ApiSourceFiles::phpFiles($root) as $file) {
            $relative = \substr($file->getPathname(), \strlen($root) + 1);
            $fqcn = 'Erpify\\' . \str_replace('/', '\\', \substr($relative, 0, -4));

            if (!\class_exists($fqcn)) {
                continue;
            }

            if (!\in_array(AuditLogger::class, ConstructorCollaboratorTypes::of($fqcn), true)) {
                continue;
            }

            $writers[] = $fqcn;
        }

        \sort($writers);

        return $writers;
    }
}
