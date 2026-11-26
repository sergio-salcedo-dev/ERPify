<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Override;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RateLimiter\AbstractRequestRateLimiter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * The login throttle: one bucket per client address, and one per (resolved mailbox + client address).
 *
 * The framework's default limiter lower-cases the submitted username and nothing else, while an identity is
 * resolved through {@see \Erpify\Iam\Identity\Domain\Email}, which also strips edge whitespace and zero-width
 * characters and normalises to NFC. A spelling that resolves to the same identity but folds to a different string
 * would draw on a fresh budget per client, so the five-a-minute ceiling would bound spellings, not attempts.
 * The mailbox is therefore keyed through {@see RecoveryBudgetKey}, the rule every other per-address budget here
 * already uses, including its fallback: a submission that is not an address still spends a bucket, so the
 * throttle never reveals which spellings denote a mailbox.
 *
 * Keys are HMACs, as in the default, so neither the address nor the client IP reaches the cache pool in clear.
 * The bucket policy is the default's (fixed window, global ceiling five times the local one) and is declared in
 * `rate_limiter.yaml`; `login_throttling.limiter` hands the firewall this service, and with a custom limiter the
 * firewall ignores its own `max_attempts` and `interval`.
 */
final class CanonicalLoginRateLimiter extends AbstractRequestRateLimiter
{
    public function __construct(
        #[Autowire(service: 'limiter.login_global')]
        private readonly RateLimiterFactory $globalFactory,
        #[Autowire(service: 'limiter.login_local')]
        private readonly RateLimiterFactory $localFactory,
        #[SensitiveParameter]
        #[Autowire(param: 'kernel.secret')]
        private readonly string $secret,
    ) {
    }

    /**
     * Clears the per-mailbox bucket only: the per-address ceiling bounds how many mailboxes one client may try,
     * so it must survive the successful login of any one of them.
     */
    #[Override]
    public function reset(Request $request): void
    {
        $this->localLimiter($request)->reset();
    }

    /**
     * @return list<LimiterInterface>
     */
    #[Override]
    protected function getLimiters(Request $request): array
    {
        return [
            $this->globalFactory->create($this->hash($request->getClientIp() ?? '')),
            $this->localLimiter($request),
        ];
    }

    private function localLimiter(Request $request): LimiterInterface
    {
        $submitted = $request->attributes->get(SecurityRequestAttributes::LAST_USERNAME, '');
        $mailbox = RecoveryBudgetKey::forEmail(\is_string($submitted) ? $submitted : '');

        return $this->localFactory->create($this->hash($mailbox . '-' . ($request->getClientIp() ?? '')));
    }

    private function hash(#[SensitiveParameter] string $data): string
    {
        return \strtr(\substr(\base64_encode(\hash_hmac('sha256', $data, $this->secret, true)), 0, 8), '/+', '._');
    }
}
