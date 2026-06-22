<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Cli;

use DateTimeImmutable;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Event\Application\DeadLetterEntry;
use Erpify\Shared\Event\Application\DeadLetterReader;
use Erpify\Shared\Event\Application\DeadLetterSummary;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Project view of the Messenger failure transport (dead-letter queue): aggregates what is stuck by
 * message type and age, where the framework's `messenger:failed:show` only dumps raw envelopes.
 * `--json` exposes the same data as a scrapeable metric.
 */
#[AsCommand(
    name: 'messenger:failed:status',
    description: 'Summarise the dead-letter queue by message type and age (human table or --json)',
)]
final class FailedMessagesStatusCommand extends Command
{
    private const int ERROR_MESSAGE_PREVIEW = 100;

    public function __construct(
        private readonly DeadLetterReader $reader,
        private readonly Clock $clock,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON for scraping')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Cap how many messages are inspected')
            ->setHelp(<<<'HELP'
                The <info>%command.name%</info> command summarises the Messenger failure transport: the
                total count, a breakdown by message type, and the age of the oldest stuck message.

                  <info>php %command.full_name%</info>
                  <info>php %command.full_name% --json</info>
                  <info>make sf c='%command.name%'</info>

                To re-send a stuck domain-event whose handler dedups (e.g. the notification email), clear
                its claim first, then retry — see <info>event:dedup:clear</info>.
                HELP)
        ;
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = $this->limit($input);

        if (false === $limit) {
            (new SymfonyStyle($input, $output))->error('--limit must be a positive integer.');

            return Command::INVALID;
        }

        $entries = $this->reader->entries($limit);
        $summary = DeadLetterSummary::fromEntries($this->reader->count(), $entries);

        if (true === $input->getOption('json')) {
            $output->writeln($this->toJson($summary, $entries));

            return Command::SUCCESS;
        }

        $this->renderTable(new SymfonyStyle($input, $output), $summary, $entries);

        return Command::SUCCESS;
    }

    /**
     * @return int|false|null null = no limit, false = invalid input
     */
    private function limit(InputInterface $input): false|int|null
    {
        $raw = $input->getOption('limit');

        if (null === $raw) {
            return null;
        }

        if (!\is_string($raw) || 1 !== \preg_match('/^[1-9]\d*$/', $raw)) {
            return false;
        }

        return (int) $raw;
    }

    /**
     * @param list<DeadLetterEntry> $entries
     */
    private function renderTable(SymfonyStyle $io, DeadLetterSummary $summary, array $entries): void
    {
        if ($summary->isEmpty()) {
            $io->success('The dead-letter queue is empty.');

            return;
        }

        $io->warning(\sprintf('%d message(s) in the dead-letter queue.', $summary->total));

        $io->section('By message type');
        $io->table(
            ['Message type', 'Count'],
            \array_map(
                static fn (string $type, int $count): array => [$type, $count],
                \array_keys($summary->countByType),
                $summary->countByType,
            ),
        );

        if ($summary->oldestFailedAt instanceof DateTimeImmutable) {
            $io->writeln(\sprintf(
                ' Oldest failure: <comment>%s</comment> (%s ago)',
                $summary->oldestFailedAt->format(DateTimeImmutable::ATOM),
                $this->humanAge($summary->oldestFailedAt),
            ));
            $io->newLine();
        }

        $io->section('Messages');
        $io->table(
            ['Event ID', 'Message type', 'Failed at', 'Error'],
            \array_map(fn (DeadLetterEntry $entry): array => [
                $entry->eventId ?? '—',
                $entry->messageType,
                $entry->failedAt?->format(DateTimeImmutable::ATOM) ?? '—',
                $this->errorPreview($entry),
            ], $entries),
        );
    }

    /**
     * @param list<DeadLetterEntry> $entries
     */
    private function toJson(DeadLetterSummary $summary, array $entries): string
    {
        return \json_encode([
            'total' => $summary->total,
            // Cast keeps the breakdown a JSON object even when empty (PHP renders [] for an empty array).
            'countByType' => (object) $summary->countByType,
            'oldestFailedAt' => $summary->oldestFailedAt?->format(DateTimeImmutable::ATOM),
            'oldestAgeSeconds' => $summary->oldestFailedAt instanceof DateTimeImmutable
                ? $this->clock->now()->getTimestamp() - $summary->oldestFailedAt->getTimestamp()
                : null,
            'messages' => \array_map(static fn (DeadLetterEntry $entry): array => [
                'messageType' => $entry->messageType,
                'eventId' => $entry->eventId,
                'failedAt' => $entry->failedAt?->format(DateTimeImmutable::ATOM),
                'errorClass' => $entry->errorClass,
                'errorMessage' => $entry->errorMessage,
            ], $entries),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function errorPreview(DeadLetterEntry $entry): string
    {
        if (null === $entry->errorClass) {
            return '—';
        }

        $message = $entry->errorMessage ?? '';

        if (\mb_strlen($message) > self::ERROR_MESSAGE_PREVIEW) {
            $message = \mb_substr($message, 0, self::ERROR_MESSAGE_PREVIEW) . '…';
        }

        return '' === $message ? $entry->errorClass : $entry->errorClass . ': ' . $message;
    }

    private function humanAge(DateTimeImmutable $failedAt): string
    {
        $seconds = \max(0, $this->clock->now()->getTimestamp() - $failedAt->getTimestamp());
        $hours = \intdiv($seconds, 3600);
        $minutes = \intdiv($seconds % 3600, 60);

        return \sprintf('%dh %dm', $hours, $minutes);
    }
}
