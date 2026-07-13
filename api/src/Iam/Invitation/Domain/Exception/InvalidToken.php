<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\InvalidInput;

/**
 * The single, opaque outcome of presenting a token that cannot be accepted — in ANY of the five cases:
 * already-used, revoked, expired, already-accepted, or non-existent. All five raise this one exception with
 * the same `type` and `title`, so the response is byte-identical and the reason is never revealed. The
 * invited email is never surfaced.
 *
 * A 400 {@see InvalidInput} (mirrors {@see \Erpify\Shared\Uuid\Domain\InvalidUuidException}): a dead token is
 * an invalid request target, not a credentials failure (401 would conflate with login) and not a missing
 * resource (404 would leak existence). Overrides `type()` to the specific `invalid-token` while the
 * {@see InvalidInput} marker keeps the uniform 400 status — the marker-plus-`type()` pattern the
 * `AccountSuspended` family uses. No new marker interface is introduced.
 */
final class InvalidToken extends DomainException implements InvalidInput
{
    public function __construct()
    {
        parent::__construct(
            type: 'invalid-token',
            title: 'This link is no longer valid.',
        );
    }
}
