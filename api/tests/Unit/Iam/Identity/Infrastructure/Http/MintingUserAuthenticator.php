<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Http;

use Closure;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Tests\Unit\Iam\Session\Application\RecordingCurrentSessionReference;
use Override;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\BadgeInterface;

/**
 * The authenticator manager `Security::login()` hands the user to, reduced to what the session-minting
 * listener does after a successful login: persist a session row and stash its id as the request's
 * correlation. A named class rather than an anonymous one because PDepend cannot parse `new readonly class`.
 *
 * @internal
 */
final readonly class MintingUserAuthenticator implements UserAuthenticatorInterface
{
    /**
     * @param Closure(): SessionId $mint
     */
    public function __construct(
        private Closure $mint,
        private RecordingCurrentSessionReference $correlation,
    ) {
    }

    /**
     * @param list<BadgeInterface> $badges
     * @param array<string, mixed> $attributes
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter") every parameter is mandated by the interface
     */
    #[Override]
    public function authenticateUser(
        UserInterface $user,
        AuthenticatorInterface $authenticator,
        Request $request,
        array $badges = [],
        array $attributes = [],
    ): ?Response {
        $this->correlation->set(($this->mint)());

        return null;
    }
}
