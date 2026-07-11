<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Infrastructure\Security;

use Erpify\Iam\Session\Infrastructure\Security\SessionAdmissionGate;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * AC2 anti-bypass. The Session Admission Gate registers as a single GLOBAL `kernel.request` listener, so every
 * request — and therefore every authenticated `/api` route — crosses it: no route can opt out of a global
 * listener (NFR4: gate coverage is a security invariant, not a per-controller convention). This pins the
 * declaration structurally — exactly one `kernel.request` registration at the documented priority (7: after the
 * firewall's ContextListener at 8, before the controller). That the registration is actually wired and runs is
 * proven end-to-end by the store-down 503 and the revoked→401 behaviours.
 *
 * @internal
 */
#[CoversNothing]
final class SessionAdmissionGateRegistrationTest extends TestCase
{
    public function testItRegistersAsASingleGlobalRequestListenerAtItsDocumentedPriority(): void
    {
        $registrations = 0;
        $attributes = (new ReflectionClass(SessionAdmissionGate::class))->getAttributes(AsEventListener::class);

        foreach ($attributes as $attribute) {
            $listener = $attribute->newInstance();

            if (KernelEvents::REQUEST !== $listener->event) {
                continue;
            }

            ++$registrations;
            $this->assertSame(
                SessionAdmissionGate::PRIORITY,
                $listener->priority,
                'The gate must run at its documented priority (7): after the firewall restores the token (8), '
                . 'before the controller.',
            );
        }

        $this->assertSame(
            1,
            $registrations,
            'The Session Admission Gate must register on kernel.request exactly once — a single global listener '
            . 'every request crosses, so no authenticated /api route can bypass the gate (AC2).',
        );
    }
}
