<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Infrastructure\Controller;

use DateTimeImmutable;
use Erpify\Iam\Session\Application\RevokeOtherSessions;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Infrastructure\Controller\RevokeOtherSessionsController;
use Erpify\Tests\Unit\Iam\Session\Application\FixedClock;
use Erpify\Tests\Unit\Iam\Session\Application\InlineTransactionManager;
use Erpify\Tests\Unit\Iam\Session\Application\RecordingEventBus;
use Erpify\Tests\Unit\Iam\Session\Domain\Entity\Mother\SessionMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which session survives "sign out my other devices".
 *
 * The kept session is the one the request was ADMITTED with — the correlation's id, not whichever row the
 * request is carrying; {@see AdmitsASessionRequest} holds why only a unit test can separate the two. The
 * direction of the error is what makes it worth pinning: a controller excepting the carried row would, on a
 * request where the pair disagreed, revoke the caller's own device and leave another one live.
 *
 * @internal
 */
#[CoversClass(RevokeOtherSessionsController::class)]
final class RevokeOtherSessionsControllerTest extends TestCase
{
    use AdmitsASessionRequest;

    /** The instant the use case's own clock is given; nothing ambient is frozen here. */
    private const string USE_CASE_INSTANT = '2026-07-10T12:00:00+00:00';

    public function testItSparesTheCorrelatedSessionAndNeverTheOneTheRequestCarries(): void
    {
        $this->assertTheCarriedSessionDisagreesWithTheCorrelation();
        $this->assertTheSubjectDisagreesWithTheCorrelation();

        $store = $this->createMock(SessionRepository::class);
        $store->expects($this->once())
            ->method('revokeOthersForUser')
            ->with(self::SUBJECT_ID, $this->correlatedSessionId())
        ;

        $carried = SessionMother::active(userId: self::SUBJECT_ID);
        $request = $this->requestCarrying($carried, '/api/v1/sessions/revoke-others', Request::METHOD_POST);

        $response = ($this->controller($store))($request);

        $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getContent());
    }

    private function controller(SessionRepository $store): RevokeOtherSessionsController
    {
        $revokeOtherSessions = new RevokeOtherSessions(
            $store,
            new RecordingEventBus(),
            new InlineTransactionManager(),
            new FixedClock(new DateTimeImmutable(self::USE_CASE_INSTANT)),
        );

        return new RevokeOtherSessionsController($this->admittedSessionProvider(), $revokeOtherSessions);
    }
}
