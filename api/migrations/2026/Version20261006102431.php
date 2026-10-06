<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates `identity_erasure_resweep`: one row per subject erased within the last hour, read by the erasure
 * re-sweep and deleted by the tick that closes the subject's window. `subject_id` holds the erased person's
 * real id by design — that is what the re-sweep searches for — and is classified with its eraser in
 * `api/.person-reference-policy`. Unique, because one erasure schedules one re-sweep per subject.
 *
 * `down()` drops the table. Its rows are transient — at most an hour of pending re-sweeps — so what a rollback
 * loses is the re-sweep of erasures run within that hour, not any record.
 */
final class Version20261006102431 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create identity_erasure_resweep, the pending re-sweeps of recent GDPR identity erasures';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE identity_erasure_resweep (subject_id UUID NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_identity_erasure_resweep_subject_id ON identity_erasure_resweep (subject_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE identity_erasure_resweep');
    }
}
