<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Cli;

use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;
use Erpify\Iam\Identity\Infrastructure\Cli\PruneExpiredPasswordResetTokensCommand;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Tests\Unit\Iam\Identity\Application\FixedClock;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryPasswordResetTokenRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
#[CoversClass(PruneExpiredPasswordResetTokensCommand::class)]
final class PruneExpiredPasswordResetTokensCommandTest extends TestCase
{
    private const string NOW = '2026-07-14T13:00:00+00:00';

    private const string EXPIRED_ID = '0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5e31';

    private const string LIVE_ID = '0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5e32';

    public function testPrunesOnlyExpiredTokensAndReportsTheCount(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $tokens = new InMemoryPasswordResetTokenRepository(
            $this->token(self::EXPIRED_ID, $now->modify('-1 minute')),
            $this->token(self::LIVE_ID, $now->modify('+1 hour')),
        );
        $tester = new CommandTester(
            new PruneExpiredPasswordResetTokensCommand($tokens, new FixedClock($now)),
        );

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        // The reported count IS the port's promise, and with two rows seeded a count of one plus the live
        // row surviving says which one went. Asserting that the expired row has stopped being readable would
        // instead pin read-after-delete inside one unit of work, which `deleteExpired()` declares undefined
        // — the adapter's `find()` consults an identity map its bulk DELETE never evicts.
        $this->assertStringContainsString('Pruned 1 expired password-reset token(s).', $tester->getDisplay());
        $this->assertInstanceOf(PasswordResetToken::class, $tokens->findById(self::LIVE_ID));
    }

    private function token(string $id, DateTimeImmutable $expiresAt): PasswordResetToken
    {
        return PasswordResetToken::issue(
            $id,
            '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b',
            SingleUseToken::mint($expiresAt)->token,
        );
    }
}
