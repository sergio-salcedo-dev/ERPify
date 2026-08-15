<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Backing table for the `cache.scheduler_checkpoint` pool, which holds the Symfony Scheduler checkpoints.
 *
 * The checkpoint decides when a period next comes due, so it has to be a property of the system rather than
 * of whichever container is currently running the worker. On the `app` filesystem pool it was the latter:
 * nothing mounts a volume over `var/cache` in production, so a deploy recreated the container, the checkpoint
 * was reseeded to the deploy instant, and every daily tick was re-anchored a full day out — never coming due
 * at all while releases shipped daily or faster.
 *
 * The table is declared here for the reason the messenger transport carries `auto_setup=0`: schema belongs
 * to the migration pipeline the entrypoint runs, not to whichever process first writes. `DoctrineDbalAdapter`
 * keeps its own lazy `createTable()` on a write into a missing table and exposes no switch for it, so this
 * migration does not disable that path — it makes sure nothing ever reaches it.
 */
final class Version20260815183729 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cache_items, the shared store behind the scheduler checkpoint pool';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE IF NOT EXISTS cache_items ('
            . 'item_id VARCHAR(255) NOT NULL, '
            . 'item_data BYTEA NOT NULL, '
            . 'item_lifetime INT DEFAULT NULL, '
            . 'item_time INT NOT NULL, '
            . 'PRIMARY KEY (item_id))',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS cache_items');
    }
}
