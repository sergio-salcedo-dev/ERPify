<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\Context;

use Behat\Gherkin\Node\PyStringNode;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Http\Infrastructure\CorrelationIdListener;
use Erpify\Tests\Behat\Context\SqlQueryContext;
use Erpify\Tests\Behat\State\HttpResponseContainer;
use Erpify\Tests\Behat\Support\Transport\HttpResponse;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The three ways the correlation token could resolve to nothing, and why silence there is expensive.
 *
 * Twenty-five scenarios isolate their `audit_log` rows by asking `SqlQueryContext` to substitute
 * `<correlationId>` with the correlation id of the last response. Every one of those failure branches
 * is written as an assertion, and **no feature can reach any of them** — a scenario using the token
 * always makes its request first, which is the correct way to write one. So nothing exercises them, and
 * a refactor to `?? $text` or to a silent return would send the literal `<correlationId>` to Postgres,
 * match zero rows, and leave all twenty-five scenarios green while measuring nothing.
 *
 * That is the same defect this token replaced — an oracle that cannot fail on the axis it names — so it
 * is pinned rather than trusted. The happy path is pinned beside them for the reason the three need:
 * a guard that refuses everything is not a working guard.
 *
 * @internal
 */
#[CoversNothing]
final class SqlQueryContextTest extends TestCase
{
    private const string TOKEN_QUERY = "SELECT 1 FROM audit_log WHERE correlation_id = '<correlationId>'";

    private const string MINTED_ID = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    #[Test]
    public function aTokenWithNoHttpCallFails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIsOrContains('No HTTP call was made');

        $this->contextFor(null)->theSQLResultAsJSONShouldBe(new PyStringNode([self::TOKEN_QUERY], 0));
    }

    #[Test]
    public function aTokenOverAStreamedResponseFails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIsOrContains('needs a Symfony Response');

        $this->contextFor(new HttpResponse('a streamed body, not a response object'))
            ->theSQLResultAsJSONShouldBe(new PyStringNode([self::TOKEN_QUERY], 0))
        ;
    }

    #[Test]
    public function aTokenOverAResponseWithoutTheHeaderFails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIsOrContains('carries no X-Correlation-Id header');

        $this->contextFor(new HttpResponse(new Response()))
            ->theSQLResultAsJSONShouldBe(new PyStringNode([self::TOKEN_QUERY], 0))
        ;
    }

    /**
     * The guard admits a resolvable token: the step gets past substitution and fails further down, on
     * the result it has no way to have. Asserting the LATER message is what distinguishes "resolved"
     * from "refused" — the three tests above would all pass against a guard that refused unconditionally.
     */
    #[Test]
    public function aResolvableTokenIsSubstitutedRatherThanRefused(): void
    {
        $response = new Response();
        $response->headers->set(CorrelationIdListener::HEADER_NAME, self::MINTED_ID);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIsOrContains('No sqlResult available to test');

        $this->contextFor(new HttpResponse($response))
            ->theSQLResultAsJSONShouldBe(new PyStringNode([self::TOKEN_QUERY], 0))
        ;
    }

    /**
     * Absent the token nothing is read, so a scenario that never made a request is untouched by any of
     * this — the step fails on its own missing result, not on a response it was never going to need.
     */
    #[Test]
    public function textWithoutTheTokenNeedsNoResponse(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIsOrContains('No sqlResult available to test');

        $this->contextFor(null)->theSQLResultAsJSONShouldBe(new PyStringNode(['[]'], 0));
    }

    private function contextFor(?HttpResponse $httpResponse): SqlQueryContext
    {
        $container = new HttpResponseContainer();

        if ($httpResponse instanceof HttpResponse) {
            $container->store($httpResponse);
        }

        // A stub rather than a mock: every path here fails before a connection is asked for, so there is
        // nothing to expect, and PHPUnit turns an expectation-less mock into a notice that reds the run.
        return new SqlQueryContext($this->createStub(EntityManagerInterface::class), $container);
    }
}
