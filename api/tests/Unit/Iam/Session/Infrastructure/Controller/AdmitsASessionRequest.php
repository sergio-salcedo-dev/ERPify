<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Infrastructure\Controller;

use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Iam\Session\Infrastructure\Security\AdmittedSessionProvider;
use Erpify\Iam\Session\Infrastructure\Security\SessionAdmissionGate;
use Erpify\Tests\Unit\Iam\Session\Application\InMemorySessionRepository;
use Erpify\Tests\Unit\Iam\Session\Application\RecordingCurrentSessionReference;
use Erpify\Tests\Unit\Iam\Session\Domain\Entity\Mother\SessionMother;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;

/**
 * A request admitted as one session while CARRYING another — the arrangement both `/sessions` controllers
 * need, and the one production never produces, since the gate publishes the very row it loaded for the
 * correlated id.
 *
 * That disagreement is the whole point: it is the only input on which asking the pair (`->id`, `->userId()`)
 * and reaching through it (`->session->getId()`) part company, so no acceptance scenario and no query-budget
 * assertion can tell the two apart. Shared as a trait so each case stays under the PHPMD object-coupling
 * budget rather than re-importing the whole arrangement itself.
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait AdmitsASessionRequest
{
    /**
     * The session this request is admitted AS. It must differ from the id {@see SessionMother} stamps by
     * default, which is what {@see assertTheCarriedSessionDisagreesWithTheCorrelation()} keeps true.
     */
    private const string CORRELATED_ID = '0190c1d2-e3f4-7a5b-8c6d-1e2f3a4b5c6f';

    /** The person the admitted session belongs to. */
    private const string SUBJECT_ID = '0190c1d2-e3f4-7a5b-8c6d-1e2f3a4b5c6a';

    /**
     * The carried session is {@see SessionMother}'s default, so this reads that class's constant — the one
     * the arrangement actually uses. It is the pair the case's discriminating power rests on, and a guard
     * comparing anything else is only ever accidentally equivalent to the invariant it claims to hold.
     *
     * `getConstant()` answers `false` for a name that no longer exists, and `assertNotSame` over `false`
     * passes, so the read is asserted to have found something first: without that the guard fails OPEN on a
     * rename, which is the direction that costs.
     */
    private function assertTheCarriedSessionDisagreesWithTheCorrelation(): void
    {
        $carriedId = (new ReflectionClass(SessionMother::class))->getConstant('DEFAULT_ID');

        $this->assertIsString($carriedId, 'SessionMother::DEFAULT_ID is gone, so this guard reads nothing.');
        $this->assertNotSame(
            self::CORRELATED_ID,
            $carriedId,
            'The carried session must not be the correlated one, or both implementations agree and the case '
            . 'can no longer tell them apart.',
        );
    }

    /**
     * The other axis, and it is an authorization one: a listing or a revocation aimed at the correlation
     * instead of at the person is only distinguishable while the two differ. Without this the cases that
     * pin "against the admitted SUBJECT" would go green over a controller wired to the session id.
     */
    private function assertTheSubjectDisagreesWithTheCorrelation(): void
    {
        $constants = (new ReflectionClass(self::class))->getConstants();

        $this->assertArrayHasKey('SUBJECT_ID', $constants);
        $this->assertArrayHasKey('CORRELATED_ID', $constants);
        $this->assertNotSame(
            $constants['SUBJECT_ID'],
            $constants['CORRELATED_ID'],
            'The subject and the correlation must differ, or asking for the sessions of either answers the '
            . 'same and the case stops distinguishing them.',
        );
    }

    private function correlatedSessionId(): SessionId
    {
        return SessionId::fromString(self::CORRELATED_ID);
    }

    private function admittedSessionProvider(): AdmittedSessionProvider
    {
        // The request carries the admitted row, so the provider's fallback lookup is never reached; an empty
        // store makes reaching it a 401 rather than a silent pass on a second opinion.
        return new AdmittedSessionProvider(
            new RecordingCurrentSessionReference($this->correlatedSessionId()),
            new InMemorySessionRepository(),
        );
    }

    private function requestCarrying(Session $session, string $uri, string $method = Request::METHOD_GET): Request
    {
        $request = Request::create($uri, $method);
        $request->attributes->set(SessionAdmissionGate::ADMITTED_SESSION_ATTRIBUTE, $session);

        return $request;
    }
}
