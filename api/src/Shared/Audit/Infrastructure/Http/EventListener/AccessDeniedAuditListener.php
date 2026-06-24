<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Http\EventListener;

use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Throwable;

/**
 * Records a permission denial as a `security` audit entry by hooking the existing RFC 9457 exception
 * pipeline, rather than scattering audit calls through the handlers. On `kernel.exception` it looks for
 * a Security-Core {@see AccessDeniedException} anywhere in the throwable chain (a firewall may wrap it)
 * and, when an `/api` main request raised one, emits `ACCESS_DENIED` at {@see AuditLevel::SECURITY}
 * through the {@see AuditLogger} seam — a synchronous write-before-send, so the denial survives even if
 * the process dies after the response.
 *
 * Priority > `ExceptionResponder::PRIORITY` (16): {@see ExceptionEvent} extends `RequestEvent`, whose
 * `setResponse()` stops propagation, so a listener after the Problem Details responder never sees the
 * throwable. This one runs first, only reads, and never sets a response — the 403 body is left
 * untouched. The one exception is durability: a failed `security` write propagates by design rather
 * than letting a denial complete unrecorded, so it may surface as a 5xx.
 */
final readonly class AccessDeniedAuditListener
{
    public const int PRIORITY = 32;

    private const string API_PATH_PREFIX = '/api/';

    private const string ACTION = 'ACCESS_DENIED';

    public function __construct(private AuditLogger $auditLogger)
    {
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: self::PRIORITY)]
    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (!\str_starts_with($event->getRequest()->getPathInfo(), self::API_PATH_PREFIX)) {
            return;
        }

        if (!$this->isAccessDenied($event->getThrowable())) {
            return;
        }

        $this->auditLogger->log(self::ACTION, AuditLevel::SECURITY);
    }

    private function isAccessDenied(Throwable $throwable): bool
    {
        for ($current = $throwable; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof AccessDeniedException) {
                return true;
            }
        }

        return false;
    }
}
