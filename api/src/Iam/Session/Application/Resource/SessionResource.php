<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Application\Resource;

/**
 * Wire contract of one row in the "my sessions" view (`GET /sessions`): a currently-active session of the
 * signed-in user. `current` distinguishes the device in hand so the UI can label it and withhold the generic
 * "close" action from it. `device` is the server-normalised label — never the raw `User-Agent`. `createdAt` is
 * a pre-formatted ATOM string; no `ip` is exposed (operational data stays out of the wire contract).
 */
final readonly class SessionResource
{
    public function __construct(
        public string $id,
        public string $device,
        public string $createdAt,
        public bool $current,
    ) {
    }
}
