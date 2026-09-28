<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Shared\Audit\Domain\AuditedEntity;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Audit\Domain\AuditWriteOperation;
use Erpify\Tests\Support\AuditActionOperationAgreement;
use LogicException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Falsifies {@see AuditActionOperationAgreement} against synthetic aggregates, so
 * {@see AuditActionOperationAgreementGateTest} can trust it over the real tree without re-deriving it.
 *
 * Every red case below sits beside the conforming one, because a rule that finds nothing and a rule that
 * cannot find anything report the same green.
 *
 * @internal
 */
#[CoversNothing]
final class AuditActionOperationAgreementRulesGateTest extends TestCase
{
    #[Test]
    public function aConformingAggregateHasNoViolation(): void
    {
        $this->assertSame([], $this->violationsOf([
            'CREATED' => 'BANK_ACCOUNT_CREATED',
            'UPDATED' => 'BANK_ACCOUNT_UPDATED',
            'DELETED' => 'BANK_ACCOUNT_DELETED',
        ]));
    }

    /**
     * @param array<string, string> $actions
     */
    #[Test]
    #[DataProvider('provideADivergentActionIsReportedNamingItsClassAndOperationCases')]
    public function aDivergentActionIsReportedNamingItsClassAndOperation(array $actions, string $operation): void
    {
        $violations = $this->violationsOf($actions);

        $this->assertCount(1, $violations, \implode("\n", $violations));
        $this->assertStringContainsString('Fixture::auditAction(' . $operation . ')', $violations[0]);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function provideADivergentActionIsReportedNamingItsClassAndOperationCases(): iterable
    {
        yield 'a synonym instead of the operation' => [
            ['CREATED' => 'BANK_CREATED', 'UPDATED' => 'BANK_MODIFIED', 'DELETED' => 'BANK_DELETED'],
            'UPDATED',
        ];
        yield "another operation's name" => [
            ['CREATED' => 'BANK_CREATED', 'UPDATED' => 'BANK_UPDATED', 'DELETED' => 'BANK_UPDATED'],
            'DELETED',
        ];
        yield 'the suffix without its separator' => [
            ['CREATED' => 'BANKCREATED', 'UPDATED' => 'BANK_UPDATED', 'DELETED' => 'BANK_DELETED'],
            'CREATED',
        ];
        yield 'the bare case name, with no root' => [
            ['CREATED' => '_CREATED', 'UPDATED' => 'BANK_UPDATED', 'DELETED' => 'BANK_DELETED'],
            'CREATED',
        ];
        yield 'lower case' => [
            ['CREATED' => 'BANK_CREATED', 'UPDATED' => 'BANK_UPDATED', 'DELETED' => 'bank_deleted'],
            'DELETED',
        ];
    }

    #[Test]
    public function rootsThatDisagreeAcrossOperationsAreReported(): void
    {
        $violations = $this->violationsOf([
            'CREATED' => 'BANK_CREATED',
            'UPDATED' => 'ACCOUNT_UPDATED',
            'DELETED' => 'BANK_DELETED',
        ]);

        $this->assertCount(1, $violations, \implode("\n", $violations));
        $this->assertStringContainsString('Fixture::auditAction()', $violations[0]);
        $this->assertStringContainsString('UPDATED => ACCOUNT', $violations[0]);
    }

    /**
     * An `auditAction()` that throws for an operation cannot name the write it is handed; the capture
     * listener would fail the flush, so the rule reports it rather than letting the gate error out.
     */
    #[Test]
    public function anOperationThatThrowsIsReported(): void
    {
        $violations = $this->violationsOf(['CREATED' => 'BANK_CREATED', 'UPDATED' => 'BANK_UPDATED']);

        $this->assertCount(1, $violations, \implode("\n", $violations));
        $this->assertStringContainsString('Fixture::auditAction(DELETED) throws', $violations[0]);
    }

    /**
     * @param array<string, string> $actions keyed by {@see AuditWriteOperation} case name
     *
     * @return list<string>
     */
    private function violationsOf(array $actions): array
    {
        $entity = new class ($actions) implements AuditedEntity {
            /**
             * @param array<string, string> $actions
             */
            public function __construct(private readonly array $actions)
            {
            }

            public function auditResource(): AuditResource
            {
                return AuditResource::of('Fixture', '0190abcd-1234-7abc-8def-001122334455');
            }

            public function auditAction(AuditWriteOperation $operation): string
            {
                return $this->actions[$operation->name]
                    ?? throw new LogicException('No action for ' . $operation->name);
            }
        };

        return AuditActionOperationAgreement::violations($entity, 'Fixture');
    }
}
