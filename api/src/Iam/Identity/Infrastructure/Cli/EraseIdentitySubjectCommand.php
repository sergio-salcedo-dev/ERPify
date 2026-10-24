<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Cli;

use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Application\FulfilIdentityErasureResult;
use Erpify\Shared\Console\Infrastructure\ConfirmedErasureCommand;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * GDPR "right to erasure" for an identity subject, run as one chained operation through
 * {@see FulfilIdentityErasure}: it removes or anonymises every link
 * {@see FulfilIdentityErasureResult::ERASED_CATEGORIES} names — that map is the only enumeration of what the
 * chain erases, and every enumeration of the chain's links this command prints is rendered from it — atomically,
 * leaving `GDPR_SUBJECT_ERASED` and `GDPR_ERASURE_EXECUTED` security entries as the compliance record. Because
 * it shares that use case with the identity console, the CLI also enforces the ≥1-active-administrator guard
 * (erasing the last active admin fails).
 * The `system` actor it runs as carries no id, so the self-erasure refusal never applies. The additive
 * `audit:gdpr:erase` command remains for anonymising an actor's trail without erasing the identity.
 *
 * **Exit codes are this command's contract with an unattended caller**, which reads `$?` and never the screen:
 * `SUCCESS` means the subject is erased, that the no-op the operator asked for (`--dry-run`, or a
 * confirmation answered "no") happened, or that there was nothing left to erase — a valid id naming no live
 * subject, which is where a typo'd-but-well-formed id lands; `FAILURE` means the erasure was attempted and
 * did not complete;
 * `INVALID` means no erasure was attempted and the command line is what needs repairing — a malformed id, or
 * a confirmation this run could not put. `INVALID` is therefore the one code a caller must not retry on.
 *
 * Three spellings suppress the refusal's own message while still refusing: `--quiet`, `--silent`, and a
 * negative `SHELL_VERBOSITY` inherited from a parent process. An unattended run that means to erase passes
 * `--force`. What no exit code here can cover is a command line the console cannot **bind** — an unknown
 * option, a wrong arity, a mistyped name — which raises before `execute()` and exits `1`, so "never retry on
 * `INVALID`" is a floor on retries rather than a partition of them.
 */
#[AsCommand(
    name: 'identity:gdpr:erase-subject',
    description: "Irreversibly erase an identity subject's data and audit trail (GDPR right to erasure)",
)]
final class EraseIdentitySubjectCommand extends ConfirmedErasureCommand
{
    /**
     * Two of the consent labels carry a comma, so a comma here would make the rendered list read as more
     * categories than there are. Public so the pin on the prompt can assert the labels are not merely
     * present but separable.
     */
    public const string CATEGORY_SEPARATOR = '; ';

    public function __construct(
        private readonly FulfilIdentityErasure $eraser,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('user-id', InputArgument::REQUIRED, 'The user id (UUID) to erase')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report the target without mutating anything')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip the confirmation prompt')
            ->setHelp(\sprintf(
                "%s %s.\n\n%s",
                'The <info>%command.name%</info> command erases an identity subject atomically. It removes or '
                . 'anonymises',
                $this->categories(),
                <<<'HELP'
                The business-log rewrite covers <comment>event_store</comment> in the aggregate column and inside
                the stored JSON alike. The erasure self-audits as <comment>GDPR_SUBJECT_ERASED</comment> and
                <comment>GDPR_ERASURE_EXECUTED</comment> security entries, and is refused if the subject is the
                last active administrator.

                Exit codes: <comment>0</comment> erased, the no-op you asked for, or nothing left to
                erase; <comment>1</comment> the erasure was attempted and failed; <comment>2</comment> nothing
                was attempted and the command line needs fixing — a malformed id, or a confirmation this run
                could not put. Do not retry on <comment>2</comment>. A run that cannot be asked
                (<comment>--no-interaction</comment>, a closed or already-exhausted stdin,
                <comment>--quiet</comment>, <comment>--silent</comment>, or a negative
                <comment>SHELL_VERBOSITY</comment>) needs <comment>--force</comment> to erase.

                  <info>php %command.full_name% <user-id> --dry-run</info>
                  <info>php %command.full_name% <user-id></info>
                  <info>php %command.full_name% <user-id> --force</info>
                HELP,
            ))
        ;
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $userId = $input->getArgument('user-id');

        if (!\is_string($userId) || !Uuid::isValid($userId)) {
            $io->error('The user-id must be a valid UUID.');

            return Command::INVALID;
        }

        $io->writeln(\sprintf('Identity subject: %s', $userId));

        $preflight = $this->preflight($io, $input);

        if (null !== $preflight) {
            return $preflight;
        }

        return $this->eraseAndReport($io, $userId);
    }

    /**
     * Rendered from {@see FulfilIdentityErasureResult::ERASED_CATEGORIES} rather than written here: this
     * sentence is the whole of what the operator consents to, so it may not describe less than the chain
     * destroys, and a hand-written one did — it named eight links while the result carried nine.
     *
     * The categories are joined with a semicolon because two of the labels contain a comma of their own, so
     * a comma-joined list reads as more entries than there are; `FulfilIdentityErasureResultTest` refuses a
     * label carrying the separator, which is what keeps that true of a label added later.
     */
    #[Override]
    protected function confirmationQuestion(): string
    {
        return \sprintf(
            'Irreversibly erase this subject? This removes or anonymises %s.',
            $this->categories(),
        );
    }

    /**
     * The chain as the operator reads it, shared by the prompt, the help and the "nothing to erase" notice so
     * none of them can name a different set of links than the result reports.
     */
    private function categories(): string
    {
        return \implode(self::CATEGORY_SEPARATOR, FulfilIdentityErasureResult::ERASED_CATEGORIES);
    }

    /**
     * One figure per entry of {@see FulfilIdentityErasureResult::ERASED_CATEGORIES}, in the map's order — a
     * presentation order, not the order the chain runs in. The value is read by the map's key, so a property
     * the map gains is reported the moment it is labelled, and one the map loses stops being reported.
     *
     * A label with no matching property prints `n/a` rather than raising: this runs after the erasure committed,
     * so an exception here would exit as "attempted and failed" over an erasure that is done. The map and the
     * properties are held equal by `FulfilIdentityErasureResultTest`, which is where that drift goes red.
     */
    private function report(FulfilIdentityErasureResult $result): string
    {
        $values = \get_object_vars($result);
        $figures = [];

        foreach (FulfilIdentityErasureResult::ERASED_CATEGORIES as $property => $label) {
            $value = $values[$property] ?? null;
            $figures[] = \sprintf('%s: %s', $label, match ($value) {
                true => 'yes',
                false => 'no',
                null => 'n/a',
                default => \var_export($value, true),
            });
        }

        return \implode(self::CATEGORY_SEPARATOR, $figures);
    }

    private function eraseAndReport(SymfonyStyle $io, string $userId): int
    {
        try {
            $result = $this->eraser->execute($userId);
        } catch (Throwable $throwable) {
            $io->error(\sprintf('Erasure failed: %s', $throwable->getMessage()));

            return Command::FAILURE;
        }

        if (!$result->erasedAnything()) {
            $io->warning(\sprintf(
                'Nothing to erase — none of these was left for the subject: %s.',
                $this->categories(),
            ));

            return Command::SUCCESS;
        }

        $io->success(\sprintf('Erased subject %s (%s).', $userId, $this->report($result)));

        return Command::SUCCESS;
    }
}
