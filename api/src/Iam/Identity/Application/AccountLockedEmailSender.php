<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

/**
 * Tells the owner of an identity that it has been temporarily locked after repeated failed sign-ins.
 *
 * The notice is **detective and nothing more**, and that is a security property rather than a limitation of
 * this port. Its delivery channel is the email address — the same namespace an attacker consumes to trip the
 * lockout in the first place (ADR docs/adr/administrative-recovery-channel.md D1, whose corollary is that no
 * surface but the one delivering a recovery identifier may disclose it). A notice carrying a token, a
 * selector, an unlock link or any other exercisable material would therefore be handing a recovery lever to
 * the compromised namespace. It informs; it grants nothing, and so adds no edge to the recovery graph.
 */
interface AccountLockedEmailSender
{
    public function send(string $recipientEmail): void;
}
