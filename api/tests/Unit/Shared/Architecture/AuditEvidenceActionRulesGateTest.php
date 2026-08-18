<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Tests\Support\AuditEvidenceActions;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Falsifiability of the audit-evidence rules, against synthetic input rather than the tree.
 *
 * Split from {@see AuditEvidenceActionRegistryGateTest} the way the person-resource gate is split: that class
 * asserts the real registry agrees with the real `src`, and would go green on rules that could never fail.
 * These cases drive every direction of {@see AuditEvidenceActions::disagreements()} and every refusal in its
 * registry parser, so a rule that stopped detecting anything is visible without a dirty line ever existing in
 * `api/.audit-evidence-actions`.
 *
 * @internal
 */
#[CoversNothing]
final class AuditEvidenceActionRulesGateTest extends TestCase
{
    #[Test]
    public function theRuleGoesRedOnAnUnclassifiedAction(): void
    {
        $disagreements = AuditEvidenceActions::disagreements(
            ['GDPR_ACCOUNT_ERASED' => ['Fixture::ACTION'], 'GDPR_SUBJECT_ERASED' => ['Fixture::SUBJECT']],
            ['GDPR_SUBJECT_ERASED' => AuditEvidenceActions::EVIDENCE],
            ['GDPR_SUBJECT_ERASED'],
        );

        $this->assertSame(['GDPR_ACCOUNT_ERASED'], $disagreements['unclassified']);
    }

    #[Test]
    public function theRuleGoesRedOnAStaleClassification(): void
    {
        $disagreements = AuditEvidenceActions::disagreements(
            [],
            ['DELETED_ACTION' => AuditEvidenceActions::ORDINARY],
            [],
        );

        $this->assertSame(['DELETED_ACTION'], $disagreements['stale']);
    }

    #[Test]
    public function theRuleGoesRedWhenTheRegistryAndTheClosedSetDisagree(): void
    {
        $disagreements = AuditEvidenceActions::disagreements(
            ['CLASSIFIED_ONLY' => ['Fixture::A'], 'EXEMPT_ONLY' => ['Fixture::B']],
            [
                'CLASSIFIED_ONLY' => AuditEvidenceActions::EVIDENCE,
                'EXEMPT_ONLY' => AuditEvidenceActions::ORDINARY,
            ],
            ['EXEMPT_ONLY'],
        );

        $this->assertSame(['CLASSIFIED_ONLY'], $disagreements['evidenceButNotExempt']);
        $this->assertSame(['EXEMPT_ONLY'], $disagreements['exemptButOrdinary']);
    }

    #[Test]
    public function theRuleStaysGreenOnAnAgreeingTree(): void
    {
        // Otherwise "everything fails" would masquerade as "the rule is enforced".
        $this->assertSame(
            ['unclassified' => [], 'stale' => [], 'exemptButOrdinary' => [], 'evidenceButNotExempt' => []],
            AuditEvidenceActions::disagreements(
                ['ORDINARY_ONE' => ['Fixture::A'], 'EVIDENCE_ONE' => ['Fixture::B']],
                [
                    'ORDINARY_ONE' => AuditEvidenceActions::ORDINARY,
                    'EVIDENCE_ONE' => AuditEvidenceActions::EVIDENCE,
                ],
                ['EVIDENCE_ONE'],
            ),
        );
    }

    /**
     * The verdict half is the one that fails toward deletion: every comparison downstream tests equality
     * with `evidence`, so any unrecognised spelling would read as ordinary and the row would be pruned with
     * the whole gate green. This is the assertion that the registry's "no third spelling" claim is true.
     */
    #[Test]
    public function theRegistryRefusesAnUnrecognisedVerdict(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Unrecognised classification for "GDPR_ACCOUNT_ERASED"');

        $this->readingRegistry("GDPR_ACCOUNT_ERASED => Evidence\n")->classification();
    }

    #[Test]
    public function theRegistryRefusesADuplicateToken(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Duplicate registry line for "GDPR_SUBJECT_ERASED"');

        $this->readingRegistry(
            "GDPR_SUBJECT_ERASED => evidence\nGDPR_SUBJECT_ERASED => ordinary\n",
        )->classification();
    }

    #[Test]
    public function theRegistryAcceptsBothSanctionedVerdicts(): void
    {
        // Otherwise "every line throws" would masquerade as validation.
        $this->assertSame(
            ['A_TOKEN' => AuditEvidenceActions::EVIDENCE, 'B_TOKEN' => AuditEvidenceActions::ORDINARY],
            $this->readingRegistry("A_TOKEN => evidence\nB_TOKEN => ordinary\n")->classification(),
        );
    }

    /**
     * An {@see AuditEvidenceActions} whose registry is the given text. Only the registry is read here, so
     * the fixture root needs no `src` — the derivation is exercised against the real tree in the sibling.
     */
    private function readingRegistry(string $registry): AuditEvidenceActions
    {
        $root = \sys_get_temp_dir() . '/audit-evidence-gate-' . \hash('xxh128', $registry);

        if (!\is_dir($root) && !\mkdir($root, 0o777, true) && !\is_dir($root)) {
            $this->fail(\sprintf('Could not create the fixture root %s.', $root));
        }

        \file_put_contents($root . '/.audit-evidence-actions', $registry);

        return new AuditEvidenceActions($root);
    }
}
