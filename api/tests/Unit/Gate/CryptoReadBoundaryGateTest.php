<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\ApiSourceFiles;
use Erpify\Tests\Support\CryptoReadBoundaryRules;
use Erpify\Tests\Support\CryptoReadRegistration;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The first caller of a READ method of the encryption ports outside `api/src/Shared/Crypto/` reds the build until
 * it is registered beside the functional test that pins what that read route answers when the key is gone, and
 * a line of `docs/api-error-contract.md` that links the file and names the outcome. The decision this guards
 * is #418: `DekDestroyed`
 * (`dek-destroyed`) and `DecryptionFailed` (`decryption-failed`) carry no marker and map to 500, which is right
 * on the write path and wrong on a read path, where the first is an EXPECTED post-erasure outcome. The read
 * boundary translates (candidate 410 for the first, a 5xx that stays Sentry-visible for the second); neither
 * shared exception gains a marker, because the write path needs its 500.
 *
 * "Read" is DERIVED, never listed: every public method of `EnvelopeEncryptor` and `StreamEnvelopeEncryptor` that
 * is not in {@see CryptoReadBoundaryRules::WRITE_METHODS}. A list of forbidden names is blind to the next read a
 * port grows (`decrypt(` alone misses the sibling port's `unseal(`), where a list of ALLOWED names leaves that
 * method red by default. The ports themselves are listed ({@see CryptoReadBoundaryRules::PORTS}), not derived:
 * `Shared/Crypto/Application` also holds `Keystore`, whose callers are legitimate infrastructure, so a third
 * decrypting port is covered only once it is added there.
 *
 * {@see self::READERS} is `reading file => [functional test]`, empty while nothing reads. Its checks are driven
 * with planted repositories by `CryptoReadBoundaryRulesGateTest`, so they are exercised while the real map is
 * empty; the engine is `Tests\Support\CryptoReadBoundaryRules` and `Tests\Support\CryptoReadRegistration`.
 *
 * What a green proves: the files under `api/src` (outside `Shared/Crypto/`) that call a read method of either
 * port as `->method(` or `?->method(` are exactly the keys of {@see self::READERS}; the same engine finds the
 * write calls that exist today, so it reaches the tree and the call shape; each registered reader resolves, by
 * `realpath`, to a file under `api/src`; its test resolves to a `*Test.php` file under `api/tests/Functional`
 * holding both `dek-destroyed` and `decryption-failed` as string literals; and a line of
 * `docs/api-error-contract.md` links the reading file as a whole path and names `dek-destroyed`.
 *
 * What it does not prove:
 * - **That the registered test proves anything.** It exists and holds both types as literals; whether it drives
 *   the route, erases the scope and reads the status that comes back is review's to judge. One test may be
 *   registered for several readers, and the contract line may be prose rather than a table row.
 * - **That a reader is a route.** A delegating decorator or a port implementation outside `Shared/Crypto/` that
 *   forwards `->decrypt(` is reported like any reader and owes the same registration; so does a CLI or Messenger
 *   reader, whose test is then its own functional test of both outcomes.
 * - **That a write name is a write on both ports.** `WRITE_METHODS` is one set matched by name on any receiver,
 *   so a port growing a read named `encrypt` or `seal` would be invisible.
 * - **That the status is the right one.** 410 versus 404 versus anything else is the read route's decision, made
 *   when it exists. This gate only refuses a read path that ships without any.
 * - **A second route in an already-registered file.** A new call in a file that is a key here names no new file.
 * - **A read the tokens do not show**: `[$encryptor, 'decrypt']`, `Closure::fromCallable`, `call_user_func`, a
 *   computed method name, a static or `parent::` call (`Port::decrypt(`), a call from outside `api/src`, a file
 *   under a symlinked directory or without a `.php` extension (the sweep is `ApiSourceFiles`'), and any alias or
 *   wrapper that forwards to a port from inside `Shared/Crypto/`, which the sweep excludes by construction.
 * - It matches the METHOD NAME on any receiver, so an unrelated `->unseal(` on another type also reds it; the
 *   accepted cost of a match that needs no type information.
 *
 * @internal
 */
#[CoversNothing]
final class CryptoReadBoundaryGateTest extends TestCase
{
    /**
     * File under `api/src` calling a read method => the functional test under `api/tests` that pins what the path
     * answers for both outcomes.
     *
     * @var array<string, array{test: string}>
     */
    private const array READERS = [];

    #[Test]
    public function theFilesReadingSealedDataAreExactlyTheRegisteredReaders(): void
    {
        $reading = \array_keys(CryptoReadBoundaryRules::readersIn(
            CryptoReadBoundaryRules::readMethodsOf(CryptoReadBoundaryRules::PORTS),
        ));

        $this->assertSame(
            [],
            CryptoReadBoundaryRules::readerProblems($reading, \array_keys($this->readers())),
        );
    }

    #[Test]
    public function theReadMethodsAreDerivedFromTheRealPortsAndExcludeEveryWriteMethod(): void
    {
        $read = CryptoReadBoundaryRules::readMethodsOf(CryptoReadBoundaryRules::PORTS);

        $this->assertContains('decrypt', $read);
        $this->assertContains('unseal', $read);

        foreach (CryptoReadBoundaryRules::WRITE_METHODS as $write) {
            // The derived names are lower-cased; compare like with like or the check cannot fail for camelCase.
            $this->assertNotContains(\strtolower($write), $read);
        }

        $declared = [];

        foreach (CryptoReadBoundaryRules::PORTS as $port) {
            foreach ((new ReflectionClass($port))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $declared[] = $method->getName();
            }
        }

        $this->assertSame(
            [],
            \array_values(\array_diff(CryptoReadBoundaryRules::WRITE_METHODS, $declared)),
            'a write method no longer exists on either port; update WRITE_METHODS so it cannot hide a renamed read',
        );
    }

    #[Test]
    public function theEngineFindsTheWriteCallsThatExistToday(): void
    {
        $writing = CryptoReadBoundaryRules::readersIn(CryptoReadBoundaryRules::WRITE_METHODS);

        $this->assertNotSame(
            [],
            $writing,
            'the engine finds no write call in api/src, although callers of encrypt() and seal() exist: it misses '
            . 'a real call shape or the sweep no longer reaches the tree',
        );
    }

    #[Test]
    public function everyRegisteredReaderHasItsFunctionalTestAndItsContractRow(): void
    {
        $this->assertSame([], CryptoReadRegistration::problems(
            $this->readers(),
            \dirname(ApiSourceFiles::root(), 2),
        ));
    }

    #[Test]
    public function theSweepReachesTheSourceItIsPinnedOver(): void
    {
        $swept = [];

        foreach (ApiSourceFiles::phpFiles() as $file) {
            $swept[] = $file->getPathname();
        }

        $this->assertGreaterThan(500, \count($swept), 'the sweep walked a fraction of api/src');

        foreach (CryptoReadBoundaryRules::PORTS as $port) {
            $declaringFile = (string) (new ReflectionClass($port))->getFileName();

            $this->assertContains($declaringFile, $swept, $port . ' is never swept');
        }

        foreach (\array_keys($this->readers()) as $reader) {
            $path = ApiSourceFiles::root() . '/' . $reader;

            $this->assertContains($path, $swept, $reader . ' is registered but never swept');
        }
    }

    /**
     * Read through a method so the sweep is typed by what the constant holds, not by its literal value: while it
     * is empty, the code over it is still the code the first registration runs.
     *
     * @return array<string, array{test: string}>
     */
    private function readers(): array
    {
        return self::READERS;
    }
}
