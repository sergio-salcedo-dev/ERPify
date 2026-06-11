<?php

declare(strict_types=1);

namespace Erpify\Shared\Application\Problem;

/**
 * Exact-key case-insensitive denylist for sensitive context fields.
 *
 * Stripped from {@see ProblemDetailsFactory::redactKeys()} (body extensions before the
 * whitelist branch, so a denylisted `JsonSerializable` value cannot survive) AND from
 * {@see \Erpify\Shared\Infrastructure\Http\EventListener\ExceptionResponder::buildLogContext()}
 * (log-record context, defense-in-depth: the canonical 8-field log shape contains no
 * denylist-named keys today, so the call is a runtime no-op — it pins the architectural
 * invariant that caller-controlled keys never reach the listener without filtering).
 *
 * **Strip semantics, not sentinel.** A denylisted key is REMOVED from the returned array;
 * its value is NOT replaced with `'[redacted]'`. Rationale: the presence of a key labelled
 * `password` is itself a signal to an attacker, and stripping composes cleanly with the
 * factory's sibling {@see ProblemDetailsFactory::RESERVED_KEYS} `unset()` layer.
 *
 * **Match scope:** substring match, case-insensitive ASCII (`strtolower`),
 * single-level (no recursion into nested arrays). Value-pattern redaction
 * (e.g. `password=secret` inside `$throwable->getMessage()`) is out of scope.
 *
 * **Extending the denylist:** add the new key to {@see KEYS} and add four casing rows to
 * {@see \Erpify\Tests\Unit\Shared\Application\Problem\RedactionDenylistTest::denylistCasingProvider}.
 * The `testDataProviderRowCountMatchesKeysCountTimesFour` assertion fails CI if a key is
 * added without its parameterised tests.
 *
 * **Static-only utility shape:** declared as a caseless `enum` rather than a `final class`
 * with a private constructor. PHP enums cannot be instantiated by any means (no `new`, no
 * reflection bypass) — a strictly stronger invariant than a private-ctor sketch, and one
 * the project's Rector `deadCode` preset cannot unwind by pruning an "unused" private method.
 */
enum RedactionDenylist
{
    public const array KEYS = [
        'password',
        'token',
        'secret',
        'authorization',
        'cookie',
        'ssn',
        'iban',
        'email',
        'phone_number',
        'address',
    ];

    /**
     * Strips denylisted string keys from `$input` (case-insensitive ASCII, substring match).
     * Numeric-coerced integer keys pass through unchanged — the filter only acts on string
     * keys, even though the canonical caller {@see ProblemDetailsFactory::redactKeys} only
     * passes `array<string, mixed>`. The runtime `is_string` guard is defense-in-depth
     * against future callers (`buildLogContext`'s 8-field map is also string-keyed; the
     * guard pins the invariant cheaply).
     *
     * @param array<array-key, mixed> $input
     *
     * @return array<array-key, mixed>
     */
    public static function filter(array $input): array
    {
        /** @var array<int|string, mixed> $filtered */
        $filtered = [];

        foreach ($input as $key => $value) {
            if (\is_string($key)) {
                $lowerKey = \strtolower($key);

                $isDenied = \array_any(
                    self::KEYS,
                    static fn (string $deniedKey): bool => \str_contains(
                        $lowerKey,
                        $deniedKey,
                    ),
                );

                if ($isDenied) {
                    continue;
                }
            }

            $filtered[$key] = $value;
        }

        return $filtered;
    }
}
