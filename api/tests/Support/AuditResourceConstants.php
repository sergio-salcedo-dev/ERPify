<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use RuntimeException;

/**
 * The constant half of the audit-resource sweep: which literal a `Holder::CONSTANT` reference resolves to.
 *
 * Split from {@see AuditResourceTypeRegistry} because it answers a question of its own — reading PHP source
 * for constant declarations and references — while the registry's subject is the classification file and what
 * it must agree with. Keeping them in one class made the sweep the largest thing in the support tree and put
 * two unrelated reasons to change behind one name.
 *
 * Both directions of ambiguity raise instead of degrading, because degrading here is invisible: a reference
 * that resolves to nothing means no type in the universe, so no registry line demanded, so nobody named as
 * obliged to erase it — with every gate still green.
 *
 * @internal test support
 */
final class AuditResourceConstants
{
    /**
     * Every `const [string] NAME = '…'` the tree declares, keyed by the short name of the class holding it.
     *
     * Keyed on the short name rather than the FQCN because that is what a call site spells once the class is
     * imported, and because this gate already derives one class per file named after its path — the same
     * assumption its registry header states. The type declaration is optional in the pattern: `const string X`
     * is the house style, but a bare `const X` holds the same literal and a sweep that only saw the typed
     * spelling would resolve nothing for it.
     *
     * @param array<string, string> $sources path relative to `api/` => file contents
     *
     * @return array<string, string>
     */
    public static function literalsIn(array $sources): array
    {
        $literals = [];

        foreach ($sources as $file => $source) {
            $holder = \basename($file, '.php');

            \preg_match_all("/const\\s+(?:string\\s+)?(\\w+)\\s*=\\s*'([^']+)'/", $source, $found, PREG_SET_ORDER);

            foreach ($found as [, $constant, $literal]) {
                self::record($literals, $holder, $constant, $literal);
            }
        }

        return $literals;
    }

    /**
     * Resolves `AuditResource::of(SomeHolder::SOME_TYPE, …)` back to the literal the constant holds, whether
     * the holder is the calling class itself (`self` / `static`) or another class the file imports.
     *
     * The cross-file half is exercised, not merely prevention: `ChangeUserRoles` writes the role-change
     * security row as `AuditResource::of(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, …)`, reaching the one
     * person-denoting type through the class declared to erase it rather than re-spelling the literal. That is
     * the shape this half exists for — a type whose literal lives with the owner of the vocabulary, so one
     * declaration serves the writer, the anonymiser and the reconciler. A sweep that only understood `self::`
     * would classify such a writer as no writer at all.
     *
     * Note it does NOT make that file a *carrier*: {@see AuditResourceTypeRegistry::sourceFilesCarrying()}
     * matches the quoted literal, so the owner rule still sees exactly one deriving file. Re-spelling `'User'`
     * here instead of reaching the constant is what would turn the gate red.
     *
     * @param array<string, string> $constants `<ShortClassName>::<CONSTANT>` => the literal it holds
     *
     * @return list<string>
     */
    public static function typesHeldIn(string $code, array $constants): array
    {
        \preg_match_all('/AuditResource::of\(\s*(\w+)::(\w+)/', $code, $references, PREG_SET_ORDER);

        $types = [];

        foreach ($references as [, $holder, $constant]) {
            if ('self' === $holder || 'static' === $holder) {
                $declaration = \sprintf("/const\\s+(?:string\\s+)?%s\\s*=\\s*'([^']+)'/", \preg_quote($constant, '/'));

                if (1 === \preg_match($declaration, $code, $literal) && isset($literal[1])) {
                    $types[] = $literal[1];
                }

                continue;
            }

            $types[] = self::resolve($constants, $holder . '::' . $constant);
        }

        return $types;
    }

    /**
     * @param array<string, string> $constants
     */
    private static function resolve(array $constants, string $reference): string
    {
        if (!isset($constants[$reference])) {
            throw new RuntimeException(\sprintf(
                'An `AuditResource::of(%s, …)` call names a constant this sweep cannot resolve to a literal, '
                . 'so the type it writes would silently stay out of the universe and no registry line would '
                . 'be demanded for it. Spell the literal at the call, or declare the constant in a class whose '
                . 'file is named after it.',
                $reference,
            ));
        }

        return $constants[$reference];
    }

    /**
     * Two classes of the same short name in different namespaces collapse onto one key. While their literals
     * agree that over-includes, which fails loudly through the completeness and staleness checks — the
     * direction this gate must err in. When they disagree, last-wins would silently resolve a call site to
     * its NAMESAKE's literal, so the type it really writes disappears: under-inclusion, and silent.
     *
     * @param array<string, string> $literals
     */
    private static function record(array &$literals, string $holder, string $constant, string $literal): void
    {
        $key = $holder . '::' . $constant;

        if (isset($literals[$key]) && $literals[$key] !== $literal) {
            throw new RuntimeException(\sprintf(
                'Two classes named `%s` declare `%s` with different literals (%s vs %s), so this sweep cannot '
                . 'tell which one a call site means and would resolve some of them to the wrong type. Rename '
                . 'one of the classes, or spell the literal at the call.',
                $holder,
                $constant,
                $literals[$key],
                $literal,
            ));
        }

        $literals[$key] = $literal;
    }
}
