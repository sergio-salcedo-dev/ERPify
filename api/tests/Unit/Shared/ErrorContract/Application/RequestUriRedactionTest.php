<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\ErrorContract\Application;

use Erpify\Shared\ErrorContract\Application\RequestUriRedaction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RequestUriRedaction::class)]
final class RequestUriRedactionTest extends TestCase
{
    #[Test]
    #[DataProvider('provideItReplacesTheSensitiveValueAndKeepsItsKeyCases')]
    public function itReplacesTheSensitiveValueAndKeepsItsKey(string $requestUri, string $expected): void
    {
        $this->assertSame($expected, RequestUriRedaction::redact($requestUri));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideItReplacesTheSensitiveValueAndKeepsItsKeyCases(): iterable
    {
        yield from self::namedKeyCases();
        yield from self::searchGrammarCases();
    }

    /**
     * Axes addressed by their own parameter name — the secrets, and the identity axes the audit screen puts
     * in the document URL.
     *
     * @return iterable<string, array{string, string}>
     */
    private static function namedKeyCases(): iterable
    {
        yield 'single-use invitation/reset secret' => [
            '/accept-invitation?token=019f.aSecret',
            '/accept-invitation?token=REDACTED',
        ];

        yield 'mercure subscriber jwt' => [
            '/.well-known/mercure?authorization=eyJhbGciOi',
            '/.well-known/mercure?authorization=REDACTED',
        ];

        yield 'acting person id, as the audit document URL carries it' => [
            '/api/v1/backoffice/audit?actorId=019f-abc',
            '/api/v1/backoffice/audit?actorId=REDACTED',
        ];

        yield 'subject person id' => [
            '/api/v1/backoffice/audit?resourceId=019f-abc',
            '/api/v1/backoffice/audit?resourceId=REDACTED',
        ];

        yield 'correlation id, which reconstructs a session' => [
            '/api/v1/backoffice/audit?correlationId=019f-abc',
            '/api/v1/backoffice/audit?correlationId=REDACTED',
        ];

        yield 'identity axes match irrespective of casing' => [
            '/api/v1/backoffice/audit?ACTORID=019f-abc&ResourceId=019f-def',
            '/api/v1/backoffice/audit?ACTORID=REDACTED&ResourceId=REDACTED',
        ];

        yield 'denylisted key as a substring' => [
            '/api/v1/backoffice/users?holder_email=someone@example.test',
            '/api/v1/backoffice/users?holder_email=REDACTED',
        ];
    }

    /**
     * The positional search grammar, which carries the same identities under a key no name-based rule
     * reaches. These are the cases the Caddy edge cannot cover with its finite enumeration.
     *
     * @return iterable<string, array{string, string}>
     */
    private static function searchGrammarCases(): iterable
    {
        yield 'search value axis, percent-encoded as it travels on the wire' => [
            '/api/v1/backoffice/audit?filters%5B0%5D%5Bfield%5D=actorId'
                . '&filters%5B0%5D%5Boperator%5D=eq&filters%5B0%5D%5Bvalue%5D=019f-abc',
            '/api/v1/backoffice/audit?filters%5B0%5D%5Bfield%5D=actorId'
                . '&filters%5B0%5D%5Boperator%5D=eq&filters%5B0%5D%5Bvalue%5D=REDACTED',
        ];

        // The difference from the Caddyfile, which enumerates 0..8 because its grammar has no wildcard.
        // A tenth filter axis would escape the edge filter and must not also escape this one.
        yield 'search value axis at an index beyond what the edge enumerates' => [
            '/api/v1/backoffice/audit?filters%5B12%5D%5Bvalue%5D=019f-abc',
            '/api/v1/backoffice/audit?filters%5B12%5D%5Bvalue%5D=REDACTED',
        ];

        yield 'search value axis in the repeated `in` form' => [
            '/api/v1/backoffice/audit?filters%5B0%5D%5Bvalue%5D%5B%5D=019f-abc'
                . '&filters%5B0%5D%5Bvalue%5D%5B%5D=019f-def',
            '/api/v1/backoffice/audit?filters%5B0%5D%5Bvalue%5D%5B%5D=REDACTED'
                . '&filters%5B0%5D%5Bvalue%5D%5B%5D=REDACTED',
        ];

        // The producer never double-encodes, so this is a crafted URI rather than one the app emits. It still
        // has to be redacted: what the rule protects is the log sink, and the sink records whatever the
        // caller sent. Over-redacting a log is the safe direction to be wrong in.
        yield 'search value axis, double-encoded' => [
            '/api/v1/backoffice/audit?filters%255B0%255D%255Bvalue%255D=019f-abc',
            '/api/v1/backoffice/audit?filters%255B0%255D%255Bvalue%255D=REDACTED',
        ];

        yield 'search value axis, encoded deeper still' => [
            '/api/v1/backoffice/audit?filters%25255B0%25255D%25255Bvalue%25255D=019f-abc',
            '/api/v1/backoffice/audit?filters%25255B0%25255D%25255Bvalue%25255D=REDACTED',
        ];

        // Five levels: the depth the loop DECODES to but, without a final check, never tests. The bound has
        // to be honoured where it is drawn rather than one decoding short of it, or the cap advertises a
        // reach it does not have.
        yield 'search value axis, encoded to the decode bound' => [
            '/api/v1/backoffice/audit?filters%252525255B0%252525255D%252525255Bvalue%252525255D=019f-abc',
            '/api/v1/backoffice/audit?filters%252525255B0%252525255D%252525255Bvalue%252525255D=REDACTED',
        ];

        // The array suffix is spelled `[]` by the PWA, `[0]` by `http_build_query`, axios with
        // `arrayFormat: "indices"` and jQuery. All three are the same axis to the server.
        yield 'search value axis in the explicitly indexed array form' => [
            '/api/v1/backoffice/audit?filters%5B0%5D%5Bvalue%5D%5B0%5D=019f-abc',
            '/api/v1/backoffice/audit?filters%5B0%5D%5Bvalue%5D%5B0%5D=REDACTED',
        ];

        yield 'search value axis with an empty index' => [
            '/api/v1/backoffice/audit?filters%5B%5D%5Bvalue%5D=019f-abc',
            '/api/v1/backoffice/audit?filters%5B%5D%5Bvalue%5D=REDACTED',
        ];
    }

    /**
     * The whole class of leak that a name-matching rule cannot see. When a session expires mid-investigation
     * the client redirects to `/login?next=<the audit URL>`, so the ids arrive under a name no denylist will
     * ever hold. Measured in the access log before this rule existed.
     */
    #[Test]
    #[DataProvider('provideItRedactsAnIdentifierCarriedInsideAValueCases')]
    public function itRedactsAnIdentifierCarriedInsideAValue(string $requestUri, string $expected): void
    {
        $this->assertSame($expected, RequestUriRedaction::redact($requestUri));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideItRedactsAnIdentifierCarriedInsideAValueCases(): iterable
    {
        yield 'the session-expiry redirect the PWA builds' => [
            '/login?next=%2Fbackoffice%2Faudit%3FactorId%3D019f-abc%26resourceId%3D019f-def'
                . '&reason=session-expired',
            '/login?next=%2Fbackoffice%2Faudit%3FactorId%3DREDACTED%26resourceId%3DREDACTED'
                . '&reason=session-expired',
        ];

        yield 'a nested search grammar' => [
            '/login?next=%2Fbackoffice%2Faudit%3Ffilters%5B0%5D%5Bvalue%5D%3D019f-abc',
            '/login?next=%2Fbackoffice%2Faudit%3Ffilters%5B0%5D%5Bvalue%5D%3DREDACTED',
        ];

        yield 'a nested secret' => [
            '/login?next=%2Faccept-invitation%3Ftoken%3D019f.s3cr3t',
            '/login?next=%2Faccept-invitation%3Ftoken%3DREDACTED',
        ];
    }

    #[Test]
    #[DataProvider('provideItLeavesADiagnosticRequestUriByteIdenticalCases')]
    public function itLeavesADiagnosticRequestUriByteIdentical(string $requestUri): void
    {
        $this->assertSame($requestUri, RequestUriRedaction::redact($requestUri));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideItLeavesADiagnosticRequestUriByteIdenticalCases(): iterable
    {
        yield 'no query at all' => ['/api/v1/backoffice/users/019f-abc'];
        yield 'empty query' => ['/api/v1/backoffice/audit?'];
        yield 'the shape of a filtered search' => [
            '/api/v1/backoffice/audit?filters%5B0%5D%5Bfield%5D=level'
                . '&filters%5B0%5D%5Boperator%5D=eq&limit=25',
        ];
        yield 'a resource type is not a person id' => ['/api/v1/backoffice/audit?resourceType=User'];
        // Nothing to leak, and rewriting it would corrupt a shape the operator reads the request by.
        yield 'a valueless parameter' => ['/api/v1/backoffice/audit?debug'];
        // A value that IS a URI but carries nothing sensitive keeps the caller's bytes: following a value
        // must not become a licence to re-encode every request that happens to hold a link.
        yield 'a nested URI with nothing to redact' => [
            '/login?next=%2Fbackoffice%2Fbanks%3Fsort%3Dname%26limit%3D25',
        ];
    }

    /**
     * The path is what an operator reads first, and a redactor that mangled it would make the log line
     * useless in exactly the case it is written for.
     */
    #[Test]
    public function itNeverTouchesThePath(): void
    {
        $redacted = RequestUriRedaction::redact('/api/v1/backoffice/users/019f-abc?token=aSecret');

        $this->assertStringStartsWith('/api/v1/backoffice/users/019f-abc?', $redacted);
    }
}
