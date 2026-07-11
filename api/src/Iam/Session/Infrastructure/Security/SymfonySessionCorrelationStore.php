<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Infrastructure\Security;

use Erpify\Iam\Session\Application\CurrentSessionReference;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The sole adapter of {@see CurrentSessionReference}: it reads and writes the `iamSessionId` attribute in the
 * Symfony session bag. Concentrating that literal here is what makes the backend-substitutability seam cheap —
 * the whole domain/application coupling to the native session lives in this one class, so a shared-store
 * backend swap touches nothing else.
 *
 * A missing or malformed correlation resolves to `null` rather than raising: the bag is server-written, but a
 * corrupted value must read as "no live session" (→ the gate's 401 re-login) rather than a 400 malformed-input.
 */
#[AsAlias(CurrentSessionReference::class)]
final readonly class SymfonySessionCorrelationStore implements CurrentSessionReference
{
    private const string BAG_KEY = 'iamSessionId';

    public function __construct(private RequestStack $requestStack)
    {
    }

    #[Override]
    public function get(): ?SessionId
    {
        $request = $this->requestStack->getCurrentRequest();

        if (!$request instanceof \Symfony\Component\HttpFoundation\Request || !$request->hasSession()) {
            return null;
        }

        $raw = $request->getSession()->get(self::BAG_KEY);

        if (!\is_string($raw) || !Uuid::isValid($raw)) {
            return null;
        }

        return SessionId::fromString($raw);
    }

    #[Override]
    public function set(SessionId $sessionId): void
    {
        $this->requestStack->getSession()->set(self::BAG_KEY, $sessionId->toString());
    }
}
