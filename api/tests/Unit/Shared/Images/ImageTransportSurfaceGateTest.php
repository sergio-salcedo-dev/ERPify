<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images;

use Erpify\Tests\Support\PublicSignatures;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Two invariants of this module's public surface, on two different axes.
 *
 * **The type axis** — no transport type may appear in a signature of `Domain/` or `Application/`. Bytes
 * enter this module as bytes; the moment an inner layer names an `UploadedFile`, a `SplFileInfo` or a PSR-7
 * stream, a caller can hand it a handle to a file of its choosing and the module is reading the filesystem
 * on the caller's behalf.
 *
 * **The value axis** — no signature may take a caller-chosen path, filename, URL or storage key. The same
 * capability arrives as a string just as easily as as an object, and a string is the spelling nothing else
 * in the toolchain can see.
 *
 * **What this adds over deptrac, measured rather than implied.** `Shared.Application` already refuses
 * `Vendor.Symfony`, so an `UploadedFile` or a `File` breaks there too. Three things it cannot do: it cannot
 * speak about `Shared/Images` in particular, because its collector is one optional-segment pattern over
 * `src/Shared` that folds every shared module into one layer; it cannot see the value axis at all,
 * because a `string $path` is not a dependency; and it cannot refuse `Psr\Http\Message\*`, because
 * `Shared.Application` admits `Vendor.Psr` — genuine HTTP transport types live there. The third is
 * the least obvious, and the reason this gate is not redundant.
 *
 * **Where the value axis stops, and why.** It covers the inner layers plus the HTTP edge — the controller
 * and the cache validator — and deliberately not the storage adapter, whose constructor takes the
 * deployment's storage root. That parameter is a path, and it is not a CALLER's: it arrives from a
 * container parameter, and no request can influence it. Scanning it would either red on correct code or
 * teach the next reader to weaken the rule.
 *
 * **The type axis matches by SHAPE rather than by a list.** A name ending in `File`, `FileInfo`,
 * `FileObject`, `FileInterface`, `Stream` or `StreamInterface` is refused whatever namespace it comes from,
 * so a successor type nobody has heard of yet is refused on the day it is written. The cost is that an
 * alias (`use SplFileInfo as Thing`) evades it — stated rather than hidden, and the reason the value axis
 * exists beside it rather than instead of it.
 *
 * @internal
 */
#[CoversNothing]
final class ImageTransportSurfaceGateTest extends TestCase
{
    private const string MODULE = __DIR__ . '/../../../../src/Shared/Images';

    /** Suffixes that make a type a handle to a file or a byte stream, rather than the bytes themselves. */
    private const array TRANSPORT_TYPE_SUFFIXES = [
        'File',
        'FileInfo',
        'FileObject',
        'FileInterface',
        'Stream',
        'StreamInterface',
    ];

    /** Words that make a parameter a caller-chosen LOCATION rather than a caller-supplied value. */
    private const array CALLER_CHOSEN_VALUE_FRAGMENTS = ['path', 'filename', 'url', 'uri', 'key', 'directory'];

    /** The layers where a transport type is a boundary violation by construction. */
    private const array INNER_LAYERS = ['Domain', 'Application'];

    /** The inner layers plus the HTTP edge: everywhere a value can be chosen by whoever is calling. */
    private const array CALLER_FACING = ['Domain', 'Application', 'Infrastructure/Controller', 'Infrastructure/Http'];

    #[DataProvider('provideNoInnerLayerSignatureNamesATransportTypeCases')]
    public function testNoInnerLayerSignatureNamesATransportType(string $layer): void
    {
        $offenders = [];

        foreach ($this->signaturesIn($layer) as $signature) {
            foreach ($signature['types'] as $type) {
                if ($this->isTransportType($type)) {
                    $offenders[] = \sprintf(
                        '%s::%s() names %s',
                        \basename($signature['file']),
                        $signature['method'],
                        $type,
                    );
                }
            }
        }

        $this->assertSame([], $offenders, \sprintf(
            "A transport type reached a public signature of %s:\n  %s\n"
            . 'Bytes enter this module as bytes. A handle lets the caller choose which file is read.',
            $layer,
            \implode("\n  ", $offenders),
        ));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNoInnerLayerSignatureNamesATransportTypeCases(): iterable
    {
        foreach (self::INNER_LAYERS as $layer) {
            yield $layer => [$layer];
        }
    }

    #[DataProvider('provideNoCallerFacingSignatureTakesAChosenLocationCases')]
    public function testNoCallerFacingSignatureTakesAChosenLocation(string $layer): void
    {
        $offenders = [];

        foreach ($this->signaturesIn($layer) as $signature) {
            foreach ($signature['parameters'] as $parameter) {
                if ($this->isCallerChosenLocation($parameter)) {
                    $offenders[] = \sprintf(
                        '%s::%s() takes $%s',
                        \basename($signature['file']),
                        $signature['method'],
                        $parameter,
                    );
                }
            }
        }

        $this->assertSame([], $offenders, \sprintf(
            "A caller-chosen location reached a public signature of %s:\n  %s\n"
            . "Where the bytes live is the storage adapter's business, and an identity is the only thing "
            . 'this module accepts as an address.',
            $layer,
            \implode("\n  ", $offenders),
        ));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNoCallerFacingSignatureTakesAChosenLocationCases(): iterable
    {
        foreach (self::CALLER_FACING as $layer) {
            yield $layer => [$layer];
        }
    }

    /**
     * Without this, the two assertions above are satisfied perfectly by a sweep that reads nothing — a
     * renamed directory, a moved module, a tokeniser that stopped matching. An empty universe is the way
     * this gate goes quiet, so it is the thing asserted first.
     */
    #[Test]
    public function theSweepStillReachesEveryLayerItClaimsToCover(): void
    {
        foreach ([...self::INNER_LAYERS, ...self::CALLER_FACING] as $layer) {
            $this->assertNotSame([], $this->signaturesIn($layer), \sprintf(
                'The sweep of %s found no public signature at all. Either the module moved, or the '
                . 'derivation stopped matching — and an empty sweep satisfies every other assertion here.',
                $layer,
            ));
        }
    }

    /**
     * @return list<array{file: string, method: string, parameters: list<string>, types: list<string>}>
     */
    private function signaturesIn(string $layer): array
    {
        return PublicSignatures::under(self::MODULE . '/' . $layer);
    }

    private function isTransportType(string $type): bool
    {
        $shortName = \substr((string) \strrchr('\\' . $type, '\\'), 1);

        foreach (self::TRANSPORT_TYPE_SUFFIXES as $suffix) {
            if (\str_ends_with($shortName, $suffix)) {
                return true;
            }
        }

        return \str_contains($type, 'Psr\Http\Message');
    }

    /**
     * Compared WORD by word, never as a substring of the whole name.
     *
     * A substring match reds a correctly named parameter for containing a fragment by accident, and the
     * three that do it are ordinary: `$security` contains `uri`, `$curl` contains `url`, and `$monkey`
     * contains `key`. That failure is in the noisy direction rather than the silent one, which is why it
     * survived — but a gate that reds on a name it should accept trains a reader to reach for a rename or
     * an exemption instead of reading the finding, and the point of this one is that its findings get read.
     *
     * The split handles both camelCase boundaries: `storagePath` and also `URLPath`, where no lower-case
     * character precedes the second word and a lower-to-upper rule alone would keep it as one token.
     */
    private function isCallerChosenLocation(string $parameter): bool
    {
        $words = \preg_split(
            '/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])|[^A-Za-z0-9]+/',
            $parameter,
            -1,
            PREG_SPLIT_NO_EMPTY,
        );

        return \array_any(
            false === $words ? [] : $words,
            static fn (string $word): bool => \in_array(
                \strtolower($word),
                self::CALLER_CHOSEN_VALUE_FRAGMENTS,
                true,
            ),
        );
    }
}
