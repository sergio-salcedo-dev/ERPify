<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session;

use Erpify\Tests\Support\ApiSourceFiles;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * NFR11 substitutability seam (AC5). The domain and application of the session subsystem must stay unaware of
 * the framework session: the only coupling to Symfony's session backend is the `iamSessionId` correlation in
 * the request bag, hidden behind the {@see \Erpify\Iam\Session\Application\CurrentSessionReference} port and its
 * sole adapter. So changing the backend (native → a shared/PDO store) touches only Infrastructure.
 *
 * This gate pins that invariant structurally: no class under `Iam/Session/{Domain,Application}` may reference
 * `Symfony\Component\HttpFoundation` (the session, request stack, or request) or the bag literal `iamSessionId`.
 * It complements deptrac (which confines `Symfony\*` to Infrastructure) by also catching the raw bag-key string
 * — a plain literal deptrac cannot see — and by naming the exact seam the spec asked to lock.
 *
 * @internal
 */
#[CoversNothing]
final class SessionSubstitutabilitySeamTest extends TestCase
{
    /**
     * References that would mean a domain/application class knows the framework session backend: an import of
     * Symfony's HttpFoundation (the session, request stack, or request), or the bag-key string literal as CODE.
     * The quoted forms are deliberate — a docblock may name `iamSessionId` to document the seam (that is what the
     * port is for); only a quoted `'iamSessionId'`/`"iamSessionId"` literal is the coupling this pins to the
     * single adapter (Decision D).
     *
     * @var list<string>
     */
    private const array FORBIDDEN_MARKERS = [
        'Symfony\Component\HttpFoundation',
        "'iamSessionId'",
        '"iamSessionId"',
    ];

    /**
     * Session layers that must stay framework-session-free, relative to `api/src`.
     *
     * @var list<string>
     */
    private const array GUARDED_LAYERS = [
        'Iam/Session/Domain',
        'Iam/Session/Application',
    ];

    public function testNoDomainOrApplicationClassReferencesTheFrameworkSession(): void
    {
        $breaches = $this->scanForFrameworkSessionReferences();

        $this->assertSame(
            [],
            $breaches,
            "NFR11 seam breach: a Session Domain/Application class references the framework session directly.\n"
            . "The `iamSessionId` correlation must stay behind the CurrentSessionReference port.\n"
            . \implode("\n", $breaches),
        );
    }

    public function testTheGuardScansTheSessionSource(): void
    {
        // A silent zero-file sweep would make the gate vacuous and let a seam breach merge unseen.
        $scanned = 0;

        foreach (self::GUARDED_LAYERS as $layer) {
            $scanned += \iterator_count(ApiSourceFiles::phpFiles(ApiSourceFiles::root() . '/' . $layer));
        }

        $this->assertGreaterThan(0, $scanned, 'The NFR11 seam gate scanned zero Session source files.');
    }

    /**
     * @return list<string>
     */
    private function scanForFrameworkSessionReferences(): array
    {
        $root = ApiSourceFiles::root();
        $breaches = [];

        foreach (self::GUARDED_LAYERS as $layer) {
            foreach (ApiSourceFiles::phpFiles($root . '/' . $layer) as $file) {
                $contents = \file_get_contents($file->getPathname());

                if (false === $contents) {
                    continue;
                }

                foreach (self::FORBIDDEN_MARKERS as $marker) {
                    if (\str_contains($contents, $marker)) {
                        $breaches[] = \sprintf('%s references %s', $file->getFilename(), $marker);
                    }
                }
            }
        }

        return $breaches;
    }
}
