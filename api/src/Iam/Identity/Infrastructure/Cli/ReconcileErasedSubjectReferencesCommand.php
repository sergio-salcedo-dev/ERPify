<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Cli;

use Erpify\Iam\Identity\Application\ReconcileErasedSubjectReferences;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Verifies the resource axis of GDPR erasure: no identity may be gone from `identity_user` while the audit
 * trail still names it by its real id. Exits non-zero on divergence, so it serves an operator on demand and
 * a cron / monitoring check alike — the sibling of `audit:gdpr:reconcile-erasures`, which does the same for
 * crypto-shredding evidence.
 *
 * A finding means some erasure path anonymised the actor axis and not the resource axis, leaving the
 * subject's real id in the trail. The repair is `identity:gdpr:erase-subject <id>`, which anonymises both
 * axes. Its outcome depends on how the divergence arose, and both outcomes are correct:
 *
 *   - The identity was hard-deleted by a path that left the actor axis intact (a legacy identity-only
 *     delete). The repair's actor pass still matches those rows, so both axes take one pseudonym.
 *   - The actor axis was already erased on its own through `audit:gdpr:erase`. That pseudonym is
 *     irreversible by design and cannot be recovered, so the repair completes the resource axis under a
 *     *distinct* one; in a row where the subject was both actor and resource the two axes then read as two
 *     anonymous identities. This is accepted — neither pseudonym reverts to the person, which is the
 *     property that matters. Reusing the lost actor pseudonym is not possible, and teaching the actor-only
 *     command to refuse such a subject is a separate decision left open deliberately.
 */
#[AsCommand(
    name: 'identity:gdpr:reconcile-subject-references',
    description: 'Verify no erased identity is still named by its real id in the audit trail',
)]
final class ReconcileErasedSubjectReferencesCommand extends Command
{
    public function __construct(
        private readonly ReconcileErasedSubjectReferences $reconciler,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dangling = $this->reconciler->unreconciledSubjectIds();

        if ([] === $dangling) {
            $io->success('No erased identity is still named by its real id in the audit trail.');

            return Command::SUCCESS;
        }

        $io->error(\sprintf(
            '%d erased identity(ies) still named in the trail — the resource axis was not anonymised:',
            \count($dangling),
        ));
        $io->listing($dangling);
        $io->note([
            'Repair with `identity:gdpr:erase-subject <id>`, which anonymises both axes.',
            'If the actor axis was already erased on its own via `audit:gdpr:erase`, the resource axis is '
            . 'completed under a separate pseudonym — the original one is irreversible and cannot be reused. '
            . 'Neither reverts to the person, so this still completes the erasure.',
        ]);

        return Command::FAILURE;
    }
}
