<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Backoffice\Bank\Infrastructure\Controller\BankLogoDeleteController;
use Erpify\Backoffice\Bank\Infrastructure\Controller\BankLogoPutController;
use Erpify\Backoffice\Bank\Infrastructure\Security\BankPermission;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The order of a bank logo write is the contract, and nothing but convention keeps it
 * (`PRODUCTION_SECURITY_CHECKLIST.md` §7, image item twelve (c)): authentication is the firewall's, `bank.write` the
 * attribute's, and inside the action the bank is looked up (id syntax, then existence) BEFORE the budget is spent, and
 * the budget is spent BEFORE the body is read — a refusal on any earlier step stores nothing and costs no read, and a
 * route that read first would spend its budget after the cost.
 *
 * Read from the action's own source, call by call: it proves the calls appear in that order in the method, never that
 * every path takes them, nor that a helper reached through another class keeps the order.
 *
 * @internal
 */
#[CoversNothing]
final class BankLogoControllerOrderTest extends TestCase
{
    #[Test]
    public function aWriteIsGrantedBankWriteOnBothVerbs(): void
    {
        foreach ([BankLogoPutController::class, BankLogoDeleteController::class] as $controller) {
            $granted = (new ReflectionClass($controller))->getAttributes(IsGranted::class);
            $this->assertCount(1, $granted, $controller);
            $this->assertSame(BankPermission::WRITE, $granted[0]->newInstance()->attribute, $controller);
        }
    }

    #[Test]
    public function thePutLooksTheBankUpThenSpendsTheBudgetThenReadsTheBodyThenWrites(): void
    {
        $this->assertCallsInOrder(
            BankLogoPutController::class,
            [
                'bankFinder->find(',
                'imageRequestThrottle->ensureWithinWriteBudget(',
                'imageRequestBodyReader->read(',
                'bankLogoSetter->set(',
            ],
        );
    }

    #[Test]
    public function theDeleteLooksTheBankUpThenSpendsTheBudgetThenRemoves(): void
    {
        $this->assertCallsInOrder(
            BankLogoDeleteController::class,
            ['bankFinder->find(', 'imageRequestThrottle->ensureWithinWriteBudget(', 'bankLogoRemover->remove('],
        );
    }

    /**
     * The checker over a planted body: a read ahead of the budget, and a missing call, are both refused.
     */
    #[Test]
    public function aBodyReadAheadOfTheBudgetOrAMissingCallIsReported(): void
    {
        $calls = ['find(', 'spend(', 'read('];

        $this->assertSame([], $this->orderProblems('x', '$a->find(1); $b->spend(); $c->read($r);', $calls));
        $this->assertNotSame([], $this->orderProblems('x', '$a->find(1); $c->read($r); $b->spend();', $calls));
        $this->assertNotSame([], $this->orderProblems('x', '$a->find(1); $c->read($r);', $calls));
    }

    /**
     * @param class-string $controller
     * @param list<string> $calls
     */
    private function assertCallsInOrder(string $controller, array $calls): void
    {
        $method = new ReflectionMethod($controller, '__invoke');
        $lines = \file((string) $method->getFileName());
        $this->assertIsArray($lines);
        $start = $method->getStartLine() - 1;
        $body = \implode('', \array_slice($lines, $start, $method->getEndLine() - $start));

        $this->assertSame([], $this->orderProblems($controller, $body, $calls));
    }

    /**
     * @param list<string> $calls
     *
     * @return list<string>
     */
    private function orderProblems(string $subject, string $body, array $calls): array
    {
        $problems = [];
        $previous = -1;

        foreach ($calls as $call) {
            $at = \strpos($body, $call);

            if (false === $at) {
                $problems[] = \sprintf('%s never calls %s', $subject, $call);

                continue;
            }

            if ($at < $previous) {
                $problems[] = \sprintf('%s calls %s before an earlier step of the order', $subject, $call);
            }

            $previous = \max($previous, $at);
        }

        return $problems;
    }
}
