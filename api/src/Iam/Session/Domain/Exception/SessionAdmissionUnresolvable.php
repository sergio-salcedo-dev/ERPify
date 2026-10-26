<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * An identity was admitted by the firewall and no session can be minted for it, because its admission-time
 * organization does not resolve: the identity holds no membership.
 *
 * Marker-less by design → HTTP 500, distinct from {@see SessionStoreUnavailable} (503). That one is an outage
 * a retry may clear; this is a permanent gap in the data that every retry meets identically, and answering it
 * as `service-unavailable` would invite the retry, tell the operator to look at the store, and page for an
 * outage that is not happening. A 4xx would be wrong the other way: the credential was correct and the client
 * can change nothing. So it is a server fault with a type of its own, visible in Sentry because it is not a
 * `ClientError`.
 *
 * It carries no context: the identity it was raised for is a person, and the request's correlation id is what
 * an operator follows to it.
 */
final class SessionAdmissionUnresolvable extends DomainException
{
    public const string TYPE = 'session-admission-unresolvable';

    public static function noOrganization(): self
    {
        return new self(
            type: self::TYPE,
            title: 'The signed-in identity cannot be admitted to a session.',
        );
    }
}
