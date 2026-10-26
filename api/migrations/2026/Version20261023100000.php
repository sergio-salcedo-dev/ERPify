<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Indexes the GDPR erasure's value match on `event_store` (ADR `event-store-and-projections.md` D12):
 * `event_store_uuids(jsonb)` collects every UUID-shaped window of a document's serialised text, and one GIN
 * index per JSON column lets `event_store_uuids(col) @> ARRAY[<id>]` stand in for a sequential `ILIKE` scan.
 *
 * Every UUID-shaped window lies inside one maximal run of `[0-9a-fA-F-]`, and within that run it is the last 8
 * characters of a dash-separated group, then three groups of exactly 4, then the first 12 of the next group.
 * So the function extracts the maximal runs once, splits each on `-` and reads every such group sequence —
 * overlapping windows included, which an extractor resuming after each match (`regexp_matches(…, 'g')` over
 * the UUID pattern) loses. One pass over the text, linear in its length. The character class spells both cases
 * instead of using the `'i'` flag, so the result does not depend on the collation an `IMMUTABLE` function must
 * not depend on; the array is de-duplicated and sorted.
 *
 * Idempotent on purpose (`CREATE OR REPLACE`, `IF NOT EXISTS`): `doctrine:schema:drop --full-database`, which
 * `make db.reset` runs, drops tables and sequences but not functions, so a non-idempotent `CREATE FUNCTION`
 * would fail the next migrate; and the out-of-band `CONCURRENTLY` recipe in D12 creates the function and both
 * indexes before this migration runs, which then does nothing.
 *
 * DBAL cannot express an expression index and does not introspect one (its index query joins `pg_attribute`
 * on `attnum`, which is 0 for an expression column), so `EventStoreSchemaListener` stays silent about these
 * and this migration owns them; `EventStoreUuidIndexSchemaFunctionalTest` proves they exist.
 *
 * A plain `CREATE INDEX` inside the migration's transaction blocks writes to `event_store` while it builds.
 * That is instantaneous on the tables this ships to.
 *
 * `down()` drops the two indexes, then the function they depend on.
 */
final class Version20261023100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index the UUIDs inside event_store payload/metadata for the GDPR erasure predicate';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION event_store_uuids(doc jsonb) RETURNS uuid[]
            LANGUAGE plpgsql IMMUTABLE STRICT PARALLEL SAFE
            AS $function$
            DECLARE
              run text;
              g text[];
              found uuid[] := '{}';
            BEGIN
              FOR run IN SELECT m[1] FROM regexp_matches(doc::text, '([0-9a-fA-F-]{36,})', 'g') AS m LOOP
                g := string_to_array(run, '-');
                FOR k IN 1 .. cardinality(g) - 4 LOOP
                  IF length(g[k]) >= 8 AND length(g[k + 1]) = 4 AND length(g[k + 2]) = 4 AND length(g[k + 3]) = 4
                     AND length(g[k + 4]) >= 12 THEN
                    found := found || CAST(right(g[k], 8) || '-' || g[k + 1] || '-' || g[k + 2] || '-' || g[k + 3]
                                           || '-' || left(g[k + 4], 12) AS uuid);
                  END IF;
                END LOOP;
              END LOOP;
              RETURN ARRAY(SELECT DISTINCT u FROM unnest(found) AS u ORDER BY u);
            END
            $function$
            SQL);
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS event_store_payload_uuids_idx ON event_store USING GIN (event_store_uuids(payload))',
        );
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS event_store_metadata_uuids_idx ON event_store USING GIN (event_store_uuids(metadata))',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS event_store_metadata_uuids_idx');
        $this->addSql('DROP INDEX IF EXISTS event_store_payload_uuids_idx');
        $this->addSql('DROP FUNCTION IF EXISTS event_store_uuids(jsonb)');
    }
}
