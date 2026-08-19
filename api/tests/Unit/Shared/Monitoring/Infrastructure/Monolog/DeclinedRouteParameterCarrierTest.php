<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Monitoring\Infrastructure\Monolog;

use DateTimeImmutable;
use Erpify\Iam\Identity\Infrastructure\Controller\UserGetController;
use Erpify\Shared\Monitoring\Infrastructure\Monolog\PersonDataRedactionProcessor;
use Erpify\Shared\Monitoring\Infrastructure\Monolog\RequestUriRedactionProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `route_parameters` holds a person's id and is deliberately NOT a carrier. The whole argument for that lives
 * in one record, so it is pinned in one file.
 *
 * Symfony's `RouterListener` writes `route_parameters` beside `request_uri` in a single `Matched route` record
 * and a route parameter IS a path segment, so on `GET /api/v1/backoffice/users/<uuid>` the id is spelled
 * twice. The path is a residual this repository accepts and records in `PRODUCTION_SECURITY_CHECKLIST.md`,
 * held in common with Caddy's access log — whose filter reads `request>uri query` and is structurally
 * incapable of touching a path. Redacting one spelling while the other stands in the same record removes
 * nothing and costs an operator the route parameters.
 *
 * The declination is therefore only sound while the OTHER spelling survives, which is what makes it
 * falsifiable rather than a habit: the day `RequestUriRedactionProcessor` starts redacting paths, the second
 * case here reds. Turning that red into a `CARRIERS` entry is a manual obligation, not a mechanism — nothing
 * does it for you, and this sentence is the whole of the handover.
 *
 * **Bounded rather than absolute.** The two coincide for the deployed route table, whose every placeholder is
 * a uuid, and `route_parameters` carries the DECODED value while `request_uri` stays encoded — so a parameter
 * holding a percent-encodable byte would differ, and nothing here would notice.
 *
 * @internal
 */
#[CoversClass(PersonDataRedactionProcessor::class)]
final class DeclinedRouteParameterCarrierTest extends TestCase
{
    private const string SUBJECT_ID = '01a01aff-87d3-7902-a5f3-c986d20d7feb';

    #[Test]
    public function itLeavesTheRouteParametersOfAPersonAddressedRouteUntouched(): void
    {
        $record = $this->matchedRouteRecord();

        $processed = (new PersonDataRedactionProcessor())($record);

        $this->assertSame($record->context, $processed->context);
        $this->assertSame($record, $processed, 'an untouched record is not worth re-allocating');
    }

    /**
     * Both processors run, because the claim is about the record as the sink receives it and not about either
     * rule alone. The order between them is not asserted and does not need to be: they touch disjoint keys,
     * so the composition commutes.
     */
    #[Test]
    public function theDeclinedRouteParametersHoldNoIdTheRequestUriDoesNotAlreadyHold(): void
    {
        $processed = (new RequestUriRedactionProcessor())(
            (new PersonDataRedactionProcessor())($this->matchedRouteRecord()),
        );

        // Read off `request_uri` and not off the formatted record: the id stands in `route_parameters` too,
        // which nothing here redacts, so a sweep of the whole record is green however the URI comes out — the
        // assertion would then be unable to fail in the one direction it exists to watch.
        $requestUri = $processed->context['request_uri'] ?? null;
        $this->assertIsString($requestUri);

        $this->assertStringContainsString(
            self::SUBJECT_ID,
            $requestUri,
            'the id no longer survives in the request URI, so route_parameters is now its only spelling',
        );
    }

    private function matchedRouteRecord(): LogRecord
    {
        return new LogRecord(
            new DateTimeImmutable('2026-08-19T17:09:33+00:00'),
            'request',
            Level::Info,
            'Matched route "{route}".',
            [
                'route' => 'backoffice_user_get',
                'route_parameters' => [
                    '_route' => 'backoffice_user_get',
                    '_controller' => UserGetController::class,
                    '_format' => 'json',
                    'id' => self::SUBJECT_ID,
                ],
                'request_uri' => 'https://erpify.test/api/v1/backoffice/users/' . self::SUBJECT_ID,
                'method' => 'GET',
            ],
        );
    }
}
