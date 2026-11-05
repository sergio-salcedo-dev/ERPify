<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Records why a keystore key was destroyed. Every tombstone that exists before this column did was written by a
 * subject erasure (nothing else destroyed a key), so they are backfilled as `erasure` before the check that
 * ties the two columns together is added: a tombstone always carries a reason and a live key never does.
 *
 * DBAL does not introspect CHECK constraints, so `doctrine:schema:validate` stays silent about this one and
 * this migration owns it; `DbalKeystoreFunctionalTest` proves it refuses a reasonless tombstone.
 */
final class Version20261105123104 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dek_keystore.destroy_reason (aborted-upload | erasure), backfill existing tombstones as erasure.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dek_keystore ADD destroy_reason VARCHAR(24) DEFAULT NULL');
        $this->addSql("UPDATE dek_keystore SET destroy_reason = 'erasure' WHERE destroyed_at IS NOT NULL");
        $this->addSql(
            'ALTER TABLE dek_keystore ADD CONSTRAINT dek_keystore_destroy_reason_check CHECK ('
            . '(destroyed_at IS NULL AND destroy_reason IS NULL) '
            . 'OR (destroyed_at IS NOT NULL AND destroy_reason IS NOT NULL '
            . "AND destroy_reason IN ('aborted-upload', 'erasure')))",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dek_keystore DROP CONSTRAINT IF EXISTS dek_keystore_destroy_reason_check');
        $this->addSql('ALTER TABLE dek_keystore DROP destroy_reason');
    }
}
