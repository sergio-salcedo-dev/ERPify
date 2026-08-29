<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Infrastructure\Controller;

use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Infrastructure\Controller\MySessionsController;
use Erpify\Iam\Session\Infrastructure\Http\SessionResourceMapper;
use Erpify\Tests\Support\ResourceResponderBuilder;
use Erpify\Tests\Unit\Iam\Session\Application\InMemorySessionRepository;
use Erpify\Tests\Unit\Iam\Session\Domain\Entity\Mother\SessionMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the listing is a listing OF, and which of its rows is the device in hand.
 *
 * Both answers come out of the admitted pair, and {@see AdmitsASessionRequest} holds the reason a unit test
 * is the only instrument that can hold them: it builds the disagreement production keeps impossible.
 *
 * **Only the two claims of the title are asserted.** The row's other fields are the mapper's contract and
 * the DTO's, pinned by `SessionResourceMapperTest` and `ResourceDtoContractTest`, and `session.feature`
 * pins the wire shape end to end. Asserting the whole decoded payload here was measured to buy nothing and
 * cost the fragility of five classes this file credits no coverage for — reordering the constructor
 * parameters of `SessionResource`, invisible to every JSON consumer, would red it.
 *
 * @internal
 */
#[CoversClass(MySessionsController::class)]
final class MySessionsControllerTest extends TestCase
{
    use AdmitsASessionRequest;

    public function testItFlagsTheCorrelatedDeviceAsCurrentAndNeverTheOneTheRequestCarries(): void
    {
        $this->assertTheCarriedSessionDisagreesWithTheCorrelation();

        $carried = SessionMother::active(userId: self::SUBJECT_ID);
        $correlated = SessionMother::active(id: self::CORRELATED_ID, userId: self::SUBJECT_ID);

        $response = ($this->controller(new InMemorySessionRepository($correlated, $carried)))(
            $this->requestCarrying($carried, '/api/v1/sessions'),
        );

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        $this->assertTrue(
            $this->isFlaggedCurrent($response, self::CORRELATED_ID),
            'The correlation decides which device is this one. Flagging the row the request happens to '
            . 'carry would label the wrong device and withhold its close action from it.',
        );
        $this->assertFalse(
            $this->isFlaggedCurrent($response, SessionMother::DEFAULT_ID),
            'Only the admitted session is this device. A second row flagged current is a UI that offers to '
            . 'close neither.',
        );
    }

    /**
     * The subject of the listing is an authorization statement: anything but the admitted session's own
     * user hands one account the devices of another.
     */
    public function testItListsTheSessionsOfTheAdmittedSubject(): void
    {
        $this->assertTheSubjectDisagreesWithTheCorrelation();

        $sessions = $this->createMock(SessionRepository::class);
        $sessions->expects($this->once())
            ->method('findByUserId')
            ->with(self::SUBJECT_ID)
            ->willReturn([])
        ;

        $carried = SessionMother::active(userId: self::SUBJECT_ID);

        ($this->controller($sessions))($this->requestCarrying($carried, '/api/v1/sessions'));
    }

    /**
     * Reads the flag off the emitted body rather than off a double, because what the controller decides is
     * WHICH id the mapper is told is current — and the flag is the only place that decision surfaces.
     */
    private function isFlaggedCurrent(Response $response, string $id): bool
    {
        $payload = \json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('data', $payload);
        $this->assertIsArray($payload['data']);

        foreach ($payload['data'] as $row) {
            $this->assertIsArray($row);

            if (($row['id'] ?? null) === $id) {
                return true === ($row['current'] ?? null);
            }
        }

        $this->fail(\sprintf('The listing carries no row for %s, so its flag says nothing.', $id));
    }

    private function controller(SessionRepository $sessions): MySessionsController
    {
        return new MySessionsController(
            $this->admittedSessionProvider(),
            $sessions,
            new SessionResourceMapper(),
            ResourceResponderBuilder::wired(),
        );
    }
}
