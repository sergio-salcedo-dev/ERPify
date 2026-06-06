<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260606113458 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add unique index on domain_event.event_id (dedupe pre-existing rows, keep the oldest per event_id)';
    }

    public function up(Schema $schema): void
    {
        // Ask-First gate: deduping is only safe when every event_id group shares one body. Divergent
        // bodies under one event_id are genuine audit data, not redelivery noise — collapsing them
        // would destroy history silently, so abort and surface the rows for manual investigation.
        $divergent = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ('
            . 'SELECT event_id FROM domain_event GROUP BY event_id HAVING COUNT(DISTINCT body::text) > 1'
            . ') AS divergent',
        );
        $this->abortIf(
            $divergent > 0,
            'domain_event has duplicate event_id rows with divergent bodies — manual investigation required before deduping',
        );

        // Dedupe before the unique index: bodies are identical per the guard above, so keeping one row
        // per event_id (smallest id) is lossless and the index creation cannot fail on double-appends.
        $this->addSql('DELETE FROM domain_event a USING domain_event b WHERE a.event_id = b.event_id AND a.id > b.id');
        $this->addSql('CREATE UNIQUE INDEX domain_event_event_id_uniq ON domain_event (event_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX domain_event_event_id_uniq');
    }
}
