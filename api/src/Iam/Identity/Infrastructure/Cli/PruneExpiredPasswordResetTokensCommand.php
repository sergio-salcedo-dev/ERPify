<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Cli;

use Erpify\Iam\Identity\Domain\Repository\PasswordResetTokenRepository;
use Erpify\Shared\Clock\Domain\Clock;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Retention sweep over `identity_password_reset_token`: expired rows are already dead to the verifier, so
 * this removes them before the table grows without bound and before a dead digest lingers longer than its
 * one-hour contract implies. Run it on a schedule (cron / a scheduled worker); one run is idempotent and a
 * missed run only defers the cleanup — live tokens are never touched.
 */
#[AsCommand(
    name: 'identity:password-reset-tokens:prune',
    description: 'Delete expired password-reset tokens (retention sweep)',
)]
final class PruneExpiredPasswordResetTokensCommand extends Command
{
    public function __construct(
        private readonly PasswordResetTokenRepository $resetTokens,
        private readonly Clock $clock,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $deleted = $this->resetTokens->deleteExpired($this->clock->now());

        $io->success(\sprintf('Pruned %d expired password-reset token(s).', $deleted));

        return Command::SUCCESS;
    }
}
