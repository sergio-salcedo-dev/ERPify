<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Persistence\Doctrine;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Repository\BankSearchRepository;
use Erpify\Shared\Domain\Search\Page;
use Erpify\Shared\Domain\Search\SearchCriteria;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * PR3 transition valve (AR8/K13) + reachability shim.
 *
 * Lets `pagination_mode=legacy` route the read to the legacy page-based strategy
 * ({@see LegacyBankSearchRepository}) instead of the keyset {@see DoctrineSearchEngine}, so the
 * legacy stack stays operable for fallback during the transition. It DECORATES the
 * {@see BankSearchRepository} alias; both branches return the SAME {@see Page} contract — the valve
 * never reshapes the envelope (W9), it only swaps the query strategy.
 *
 * This valve IS the {@see BankSearchRepository} alias (it composes the two concrete repositories);
 * it is registered in ALL environments ON PURPOSE. Psalm's `findUnusedCode` cannot detect
 * `#[When]`-gated services, so env-gating the valve would force a new baseline entry — which PR3
 * forbids (AC#7). Production safety is instead a single, centralized, FAIL-CLOSED runtime guard:
 * the legacy branch is reachable only in the `dev`/`staging` transition envs AND on the explicit
 * opt-in param; every other case (prod, test, missing/invalid value) resolves to the cursor path.
 * The legacy code is therefore present-but-inert in prod, not absent-by-construction — a deliberate,
 * documented concession to the analyzer's behavior.
 *
 * Delete this valve (and {@see LegacyBankSearchRepository} + the whole legacy pagination stack)
 * wholesale in PR4 (AR16) — tracked as an explicit PR4 removal task.
 */
#[AsDecorator(decorates: BankSearchRepository::class)]
final readonly class PaginationModeBankSearchValve implements BankSearchRepository
{
    public const string PARAM = 'pagination_mode';

    public const string LEGACY_MODE = 'legacy';

    /** The only environments where the legacy branch may run; everything else is fail-closed to cursor. */
    private const array LEGACY_ENABLED_ENVS = ['dev', 'staging'];

    public function __construct(
        #[AutowireDecorated]
        private BankSearchRepository $cursorPath,
        private LegacyBankSearchRepository $legacyPath,
        private RequestStack $requestStack,
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    /**
     * @return Page<Bank>
     */
    #[Override]
    public function search(SearchCriteria $criteria): Page
    {
        if ($this->legacyRequested()) {
            return $this->legacyPath->search($criteria);
        }

        return $this->cursorPath->search($criteria);
    }

    /**
     * Single centralized guard. Fail-closed: legacy runs ONLY in a transition env AND on an explicit
     * `pagination_mode=legacy`; a missing request, a missing/other param value, or any non-transition
     * env (prod, test) all resolve to the cursor path. The legacy path is never the default.
     */
    private function legacyRequested(): bool
    {
        if (!\in_array($this->environment, self::LEGACY_ENABLED_ENVS, true)) {
            return false;
        }

        $request = $this->requestStack->getCurrentRequest();

        if (!$request instanceof Request) {
            return false;
        }

        return self::LEGACY_MODE === $request->query->get(self::PARAM);
    }
}
