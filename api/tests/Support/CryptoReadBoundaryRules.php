<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use Erpify\Shared\Crypto\Application\EnvelopeEncryptor;
use Erpify\Shared\Crypto\Application\StreamEnvelopeEncryptor;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * The rules `CryptoReadBoundaryGateTest` asserts over the real tree and `CryptoReadBoundaryRulesGateTest` drives
 * with planted input: which methods of the encryption ports are reads, which files call one, and whether a
 * registered reader can hold the contract it stands for.
 *
 * Tokens rather than text: a string, a comment or a docblock is never a call, and whitespace or a comment inside
 * a chain is skipped. Nothing here follows anything — a call through an alias, a callable array or a static
 * receiver is simply absent, and the gate's header says so.
 *
 * @internal test support
 */
final class CryptoReadBoundaryRules
{
    /**
     * @var list<class-string>
     */
    public const array PORTS = [EnvelopeEncryptor::class, StreamEnvelopeEncryptor::class];

    /**
     * The methods a caller outside `Shared/Crypto/` may use freely: sealing a value or a stream, destroying a scope.
     *
     * @var list<string>
     */
    public const array WRITE_METHODS = ['encrypt', 'seal', 'destroyScope'];

    public const string CRYPTO_DIRECTORY = 'Shared/Crypto/';

    public const string RECIPE = 'A file that calls a read method of EnvelopeEncryptor / StreamEnvelopeEncryptor owes '
        . 'the contract #418 defers to it: translate at the read boundary, so dek-destroyed answers a deliberate '
        . 'status (candidate 410 Gone) and decryption-failed stays a 5xx integrity fault, WITHOUT a marker on '
        . 'DekDestroyed / DecryptionFailed (the write path needs their 500). Add a test that provokes both and '
        . 'holds dek-destroyed and decryption-failed as string literals (a *Test.php under api/tests/Functional) '
        . 'or outside a comment (a .feature under api/features), a single line of docs/api-error-contract.md that '
        . 'cites api/src/<file> and names dek-destroyed, and register it in READERS of CryptoReadBoundaryGateTest '
        . "as 'Documents/X.php' => ['test' => 'tests/Functional/Documents/XTest.php'] (the reader relative "
        . 'to api/src, the test relative to api/).';

    /**
     * Public methods of the ports that are not writes, lower-cased and sorted. A method a port grows tomorrow is a
     * read until it is named in {@see self::WRITE_METHODS}.
     *
     * @param list<class-string|object> $ports
     *
     * @return list<string>
     */
    public static function readMethodsOf(array $ports): array
    {
        $write = \array_map(strtolower(...), self::WRITE_METHODS);
        $read = [];

        foreach ($ports as $port) {
            foreach ((new ReflectionClass($port))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $name = \strtolower($method->getName());

                if (!\str_starts_with($name, '__') && !\in_array($name, $write, true)) {
                    $read[$name] = $name;
                }
            }
        }

        \sort($read);

        return $read;
    }

    /**
     * The distinct names among `$methods` that `$source` calls as `->name(` or `?->name(`, lower-cased and sorted.
     *
     * @param list<string> $methods
     *
     * @return list<string>
     */
    public static function callsIn(string $source, array $methods): array
    {
        $wanted = \array_map(strtolower(...), $methods);
        $tokens = \array_values(\array_filter(
            \token_get_all($source),
            static fn (array|string $token): bool => !\is_array($token)
                || !\in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $found = [];

        foreach ($tokens as $i => $token) {
            if (!\is_array($token) || !\in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                continue;
            }

            $name = $tokens[$i + 1] ?? null;

            if (!\is_array($name) || T_STRING !== $name[0] || '(' !== ($tokens[$i + 2] ?? null)) {
                continue;
            }

            $lowered = \strtolower($name[1]);

            if (\in_array($lowered, $wanted, true)) {
                $found[$lowered] = $lowered;
            }
        }

        \sort($found);

        return $found;
    }

    /**
     * Files under `api/src` (or under `$root`, for a planted tree), outside `Shared/Crypto/`, that call one of
     * `$methods`, each with the methods it calls.
     *
     * @param list<string> $methods
     *
     * @return array<string, list<string>>
     */
    public static function readersIn(array $methods, ?string $root = null): array
    {
        $root ??= ApiSourceFiles::root();
        $found = [];

        foreach (ApiSourceFiles::phpFiles($root) as $file) {
            $path = \substr($file->getPathname(), \strlen($root) + 1);

            if (\str_starts_with($path, self::CRYPTO_DIRECTORY)) {
                continue;
            }

            $source = \file_get_contents($file->getPathname());

            if (!\is_string($source)) {
                throw new RuntimeException($file->getPathname() . ' could not be read, so it was never swept');
            }

            $calls = self::callsIn($source, $methods);

            if ([] !== $calls) {
                $found[$path] = $calls;
            }
        }

        \ksort($found);

        return $found;
    }

    /**
     * @param list<string> $reading    files under `api/src` calling a read method
     * @param list<string> $registered the keys of `CryptoReadBoundaryGateTest::READERS`
     *
     * @return list<string>
     */
    public static function readerProblems(array $reading, array $registered): array
    {
        $problems = [];

        foreach (\array_diff($reading, $registered) as $unregistered) {
            $problems[] = \sprintf("%s calls a read method of an encryption port.\n%s", $unregistered, self::RECIPE);
        }

        foreach (\array_diff($registered, $reading) as $stale) {
            $problems[] = \sprintf('%s is registered in READERS but no longer reads; remove it', $stale);
        }

        return $problems;
    }
}
