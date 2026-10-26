<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Override;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Votes on `<resource>.<action>` authorization attributes by delegating to the {@see AuthorizationPolicy}.
 * The first custom voter in the app.
 *
 * It supports ONLY the permissions the installation publishes ({@see PublishedPermissions}), so it abstains on
 * Symfony's own vocabulary (`ROLE_*`, `IS_AUTHENTICATED_*`, `PUBLIC_ACCESS`) and on any other attribute, dotted
 * or not: those flow to whichever voter owns them, and this voter never contends over one. Supporting by shape
 * alone would claim every `<x>.<y>`, and the tier policy grants an unknown resource's tier verbs (and `ADMIN`
 * everything), so under the `affirmative` strategy its GRANT would overrule another voter's DENY on an
 * attribute that is not a permission at all — a `featureFlag.read`, say. Claiming only published permissions
 * keeps an unknown attribute fail-closed: every voter abstains and the decision manager denies. `ROLE_` is a
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

    public function __construct(
        private readonly AuthorizationPolicy $policy,
        private readonly PublishedPermissions $permissions,
    ) {
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter") $subject is mandated by the Voter signature and is
     * deliberately not read — support is decided by the attribute alone
     */
    #[Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $this->permissions->contains($attribute);
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
