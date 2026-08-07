<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260806180031 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Give audit_log.actor_erased and resource_erased a persistent DEFAULT FALSE, so an INSERT '
            . 'issued by an image that predates the column writes a row instead of failing.';
    }

    public function up(Schema $schema): void
    {
        // The writer supplies both values on every insert, so no in-service image relies on the default.
        // It is what the PREVIOUS image gets: redeploying an earlier image tag is the documented rollback
        // (docs/deployment-guide.md) and does not undo the migration with it, because down() never runs.
        // A writer whose INSERT does not name these columns then meets NOT NULL with no default and every
        // audit write fails — and on the `change` tier that failure is raised inside onFlush with no
        // catch, so the business write fails with it. FALSE is the only correct value: it is what the
        // current writer inserts, and the erasure passes are what flip it.
        $this->addSql('ALTER TABLE audit_log ALTER COLUMN actor_erased SET DEFAULT FALSE');
        $this->addSql('ALTER TABLE audit_log ALTER COLUMN resource_erased SET DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_log ALTER COLUMN actor_erased DROP DEFAULT');
        $this->addSql('ALTER TABLE audit_log ALTER COLUMN resource_erased DROP DEFAULT');
    }
}
