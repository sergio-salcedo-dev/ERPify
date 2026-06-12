<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260612221100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add handled_domain_event claim table so async handlers with external side effects '
            . '(email) stay idempotent across Messenger redeliveries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE IF NOT EXISTS handled_domain_event ('
            . 'event_id VARCHAR(36) NOT NULL, '
            . 'handler VARCHAR(190) NOT NULL, '
            . 'claimed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
            . 'PRIMARY KEY (event_id, handler))',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS handled_domain_event');
    }
}
