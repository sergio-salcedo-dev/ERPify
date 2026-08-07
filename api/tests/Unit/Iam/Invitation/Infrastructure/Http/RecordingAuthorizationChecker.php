<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Infrastructure\Http;

use Override;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Hand-written {@see AuthorizationCheckerInterface} that answers one fixed verdict and records what it was
 * asked. A stub would answer the verdict alone, and two of the three properties under test are about the
 * question rather than the answer: that the permission is only consulted when the payload carries `ADMIN`,
 * and that the subject stays null — the voter must decide on roles alone, never on the row or the payload
 * it is asked about.
 *
 * @internal
 */
final class RecordingAuthorizationChecker implements AuthorizationCheckerInterface
{
    /** @var list<mixed> */
    public array $askedAttributes = [];

    /** @var list<mixed> */
    public array $askedSubjects = [];

    public function __construct(private readonly bool $granted)
    {
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter") $accessDecision is mandated by the interface and only
     *                                                  ever written to by the real access-decision manager
     */
    #[Override]
    public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
    {
        $this->askedAttributes[] = $attribute;
        $this->askedSubjects[] = $subject;

        return $this->granted;
    }
}
