<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\Identity;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Identity\Domain\Email;
use Erpify\Backoffice\Identity\Domain\Entity\User;
use Erpify\Backoffice\Identity\Domain\Enum\Role;
use Erpify\Backoffice\Identity\Domain\Repository\UserRepository;
use Erpify\Backoffice\Identity\Infrastructure\Cli\CreateUserCommand;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Proves the full CLI wiring against REAL Postgres + the configured password hasher: the command hashes in
 * Infrastructure and persists a `User`, an unknown role never touches the DB, and a duplicate email fails.
 * Each test runs inside a transaction that truncates `identity_user` and always rolls back (shared dev DB).
 *
 * @internal
 */
#[CoversClass(CreateUserCommand::class)]
final class CreateUserCommandTest extends KernelTestCase
{
    private Connection $connection;

    private UserRepository $users;

    private Application $application;

    #[Override]
    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->connection = $entityManager->getConnection();

        $users = self::getContainer()->get(UserRepository::class);
        $this->assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;

        $this->application = new Application($kernel);
    }

    public function testCreatesAUserWithAHashedPasswordAndRole(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $tester = $this->runCommand([
                'email' => 'grace@erpify.test',
                'password' => 's3cret-pass',
                '--role' => ['AUDIT_READER'],
            ]);

            $this->assertSame(Command::SUCCESS, $tester->getStatusCode());

            $user = $this->users->findByEmail(Email::from('grace@erpify.test'));
            $this->assertInstanceOf(User::class, $user);
            $this->assertSame([Role::AUDIT_READER], $user->roles());
            $storedHash = $user->passwordHash()->toString();
            $this->assertNotSame('s3cret-pass', $storedHash, 'the plaintext must never be persisted');
            $this->assertNotSame('', $storedHash);
        });
    }

    public function testRejectsAnUnknownRoleWithoutPersisting(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $tester = $this->runCommand(['email' => 'heidi@erpify.test', 'password' => 'pw', '--role' => ['NOPE']]);

            $this->assertSame(Command::INVALID, $tester->getStatusCode());
            $this->assertNotInstanceOf(User::class, $this->users->findByEmail(Email::from('heidi@erpify.test')));
        });
    }

    public function testReportsFailureOnADuplicateEmail(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $first = $this->runCommand(['email' => 'ivan@erpify.test', 'password' => 'pw', '--role' => []]);
            $this->assertSame(Command::SUCCESS, $first->getStatusCode());

            $second = $this->runCommand(['email' => 'IVAN@erpify.test', 'password' => 'pw2', '--role' => []]);
            $this->assertSame(Command::FAILURE, $second->getStatusCode());
        });
    }

    public function testPromptsForThePasswordWhenTheArgumentIsOmitted(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $tester = new CommandTester($this->application->find('identity:user:create'));
            $tester->setInputs(['prompted-pass']);
            $tester->execute(['email' => 'judy@erpify.test', '--role' => []]);

            $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
            $this->assertInstanceOf(User::class, $this->users->findByEmail(Email::from('judy@erpify.test')));
        });
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runCommand(array $input): CommandTester
    {
        $tester = new CommandTester($this->application->find('identity:user:create'));
        $tester->execute($input);

        return $tester;
    }

    /**
     * @param callable(): void $testBody
     */
    private function inRolledBackTransaction(callable $testBody): void
    {
        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement('TRUNCATE identity_user RESTART IDENTITY CASCADE');
            $testBody();
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }
    }
}
