<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Identity\Infrastructure\Security;

use Override;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Votes on `<resource>.<action>` authorization attributes by delegating to the {@see AuthorizationPolicy}.
 * The first custom voter in the app.
 *
 * It supports ONLY permission-shaped attributes, so it abstains on Symfony's own vocabulary (`ROLE_*`,
 * `IS_AUTHENTICATED_*`, `PUBLIC_ACCESS`): those keep flowing to the native RoleVoter / AuthenticatedVoter,
 * which is why the existing `#[IsGranted('ROLE_AUDIT_READER')]` audit routes are untouched. `ROLE_` is a
 * framework detail that stops at this edge — the token's role names are stripped back to the bare domain
 * tokens the neutral policy speaks. The `$subject` is accepted but never read: authorization here is
 * resource-and-action, not row-level (reading it would make this attribute-based access control — a
 * different model behind a different decision record).
 *
 * @extends Voter<string, mixed>
 */
final class PermissionVoter extends Voter
{
    private const string ROLE_PREFIX = 'ROLE_';

    public function __construct(private readonly AuthorizationPolicy $policy)
    {
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter") $subject is mandated by the Voter signature and is
     * deliberately not read — support is decided by the attribute's shape alone
     */
    #[Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return Permission::isWellFormed($attribute);
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter") $subject and $vote are mandated by the Voter
     * signature; $subject is deliberately not read (see class docblock) and $vote is unused here
     */
    #[Override]
    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        TokenInterface $token,
        ?Vote $vote = null,
    ): bool {
        return $this->policy->permits(
            Permission::fromString($attribute),
            $this->bareRoleTokens($token->getRoleNames()),
        );
    }

    /**
     * A name without the `ROLE_` prefix is dropped rather than passed through: only Symfony-emitted roles
     * (which {@see SecurityUser::getRoles()} always prefixes) may authorize, so a bare token such as
     * `ADMIN` reaching the voter can never be honoured as the ADMIN tier.
     *
     * @param array<string> $roleNames
     *
     * @return list<string>
     */
    private function bareRoleTokens(array $roleNames): array
    {
        $bareTokens = [];

        foreach ($roleNames as $roleName) {
            if (\str_starts_with($roleName, self::ROLE_PREFIX)) {
                $bareTokens[] = \substr($roleName, \strlen(self::ROLE_PREFIX));
            }
        }

        return $bareTokens;
    }
}
