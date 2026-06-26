<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260626110713 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add audit_log read-side indexes: (occurred_on, id) timeline order and '
            . '(actor_type, occurred_on) actor-kind filtering for the investigation read model.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS audit_log_timeline_idx ON audit_log (occurred_on, id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS audit_log_actor_type_idx ON audit_log (actor_type, occurred_on)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS audit_log_timeline_idx');
        $this->addSql('DROP INDEX IF EXISTS audit_log_actor_type_idx');
    }
}
