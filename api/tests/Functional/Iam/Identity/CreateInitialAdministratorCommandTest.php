<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Doctrine\DBAL\Connection;
use Erpify\Iam\Identity\Infrastructure\Cli\CreateInitialAdministratorCommand;
use Erpify\Shared\Validation\Infrastructure\PasswordPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The bootstrap account is the most privileged one an installation will ever hold, and until the shared policy
 * arrived this command accepted any non-empty string — a one-character administrator password was reachable.
 *
 * Driven through the real console command rather than the policy object it delegates to: the point of the test
 * is that the command *asks*, and asserting the policy in isolation would stay green if the call were deleted.
 * A refused password is refused before anything is built, hashed or persisted, so the exit code and the absence
 * of a write are the same assertion. The boundary case genuinely writes, which is why every test sweeps its own
 * row: this suite does not reload fixtures between tests, so a leftover administrator would follow the shared
 * test database into whatever runs next.
 *
 * @internal
 */
#[CoversClass(CreateInitialAdministratorCommand::class)]
final class CreateInitialAdministratorCommandTest extends KernelTestCase
{
    private const string EMAIL = 'bootstrap@erpify.test';

    /**
     * The accepting case really does write, and this suite does not reload fixtures between tests — a leftover
     * administrator would follow the shared test database into whatever runs next.
     */
    protected function tearDown(): void
    {
        self::bootKernel();
        $this->connection()->executeStatement(
            'DELETE FROM identity_user WHERE email = :email',
            ['email' => self::EMAIL],
        );
        parent::tearDown();
    }

    #[DataProvider('provideItRefusesAPasswordTheHttpSurfacesWouldRefuseCases')]
    public function testItRefusesAPasswordTheHttpSurfacesWouldRefuse(string $password, string $expected): void
    {
        $commandTester = $this->commandTester();

        $exitCode = $commandTester->execute(['email' => self::EMAIL, 'password' => $password]);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString($expected, $commandTester->getDisplay());
        $this->assertSame(0, $this->identityCount(), 'a refused password must build no identity');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideItRefusesAPasswordTheHttpSurfacesWouldRefuseCases(): iterable
    {
        yield 'a single character' => ['x', 'at least 8'];
        yield 'one below the floor' => [\str_repeat('a', PasswordPolicy::MIN_LENGTH - 1), 'at least 8'];
        yield 'one above the ceiling' => [\str_repeat('a', PasswordPolicy::MAX_LENGTH + 1), 'not exceed 128'];
        yield 'whitespace only' => ['        ', 'non-whitespace'];
        // Unicode whitespace survives an ASCII trim, which is why the rule is written with `mb_trim`.
        yield 'non-breaking spaces only' => [\str_repeat("\u{00A0}", 8), 'non-whitespace'];
        yield 'astral characters below the floor' => [\str_repeat("\u{1F600}", 5), 'at least 8'];
    }

    /**
     * The floor is a floor, not a ban: the boundary value passes. Without this the refusals above would still
     * hold if the command rejected everything.
     */
    public function testItAcceptsThePolicyBoundaryItself(): void
    {
        $commandTester = $this->commandTester();

        $exitCode = $commandTester->execute([
            'email' => self::EMAIL,
            'password' => \str_repeat('a', PasswordPolicy::MIN_LENGTH),
        ]);

        $this->assertNotSame(Command::INVALID, $exitCode);
    }

    private function commandTester(): CommandTester
    {
        $application = new Application(self::bootKernel());

        return new CommandTester($application->find('organization:administrator:create'));
    }

    private function identityCount(): int
    {
        $count = $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM identity_user WHERE email = :email',
            ['email' => self::EMAIL],
        );
        \assert(\is_numeric($count));

        return (int) $count;
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
