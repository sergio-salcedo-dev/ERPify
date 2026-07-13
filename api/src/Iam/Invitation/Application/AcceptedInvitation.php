<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Application;

/**
 * The primitive outcome of a successful {@see AcceptInvitation}: the now-active identity's id and canonical
 * email. The controller reads the email to establish the session (programmatic login) after the domain
 * transaction commits — the use case returns primitives so the HTTP adapter never handles the identity
 * aggregate across the context boundary.
 */
final readonly class AcceptedInvitation
{
    public function __construct(
        public string $userId,
        public string $email,
    ) {
    }
}
