<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Documents scan: the lease, epoch and counter columns every scan transition is a compare-and-set over, the partial
 * index the sweep's candidate list reads, the system actor (`document_custody.actor_id` NULL) and the operator's
 * re-scan reason (`document_custody.reason`, set for that one event and NULL for every other).
 *
 * `lease_epoch` has no upper bound and never resets. DBAL does not introspect CHECK constraints, so this migration
 * owns the one: counters never go negative.
 *
 * `down()` restores NOT NULL on the actor and fails while a system-actor row exists; nothing is live, and a row
 * that names nobody cannot be given a person.
 */
final class Version20261112213126 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the scan lease, epoch and counters to document and make the custody actor nullable (Documents scan).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document ADD scan_attempts INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE document ADD scan_outage_returns INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE document ADD lease_epoch BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE document ADD first_claim_recorded BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE document ADD scan_streaming_started_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE document ADD claimed_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE document ADD lease_expires_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_document_scan_due ON document (next_scan_at) WHERE state = \'received\' AND promoted_at IS NOT NULL');
        $this->addSql('ALTER TABLE document_custody ALTER actor_id DROP NOT NULL');
        $this->addSql('ALTER TABLE document_custody ADD reason VARCHAR(64) DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE document ADD CONSTRAINT document_scan_counters_check CHECK ('
            . 'scan_attempts >= 0 AND scan_outage_returns >= 0 AND lease_epoch >= 0)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document DROP CONSTRAINT document_scan_counters_check');
        $this->addSql('DROP INDEX idx_document_scan_due');
        $this->addSql('ALTER TABLE document DROP scan_attempts');
        $this->addSql('ALTER TABLE document DROP scan_outage_returns');
        $this->addSql('ALTER TABLE document DROP lease_epoch');
        $this->addSql('ALTER TABLE document DROP first_claim_recorded');
        $this->addSql('ALTER TABLE document DROP scan_streaming_started_at');
        $this->addSql('ALTER TABLE document DROP claimed_at');
        $this->addSql('ALTER TABLE document DROP lease_expires_at');
        $this->addSql('ALTER TABLE document_custody DROP reason');
        $this->addSql('ALTER TABLE document_custody ALTER actor_id SET NOT NULL');
    }
}
