<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The validator of the Documents restore-proof records: one YAML file per proof under
 * `docs/runbooks/documents-restore-proofs/` (format and procedure: `docs/runbooks/documents-restore-proof.md`),
 * and the rule that ties them to the ingest switch.
 *
 * A record is valid when it carries every field of {@see FIELDS} with a non-empty value, `kind` is `restore` or
 * `drill`, `result` is `passed`, and the four booleans are true. The switch rule: production configuration may set
 * `documents.ingest_enabled` true only when a valid record of kind `restore` names, as its `mechanism`, the backup
 * mechanism that same configuration declares — a historical record for another mechanism does not count.
 *
 * @SuppressWarnings("PHPMD.CyclomaticComplexity") one branch per rule a record must satisfy
 * @SuppressWarnings("PHPMD.NPathComplexity") the same
 */
final class DocumentsRestoreProofs
{
    /** @var list<string> */
    public const array FIELDS = [
        'kind',
        'proved_by',
        'proved_at',
        'mechanism',
        'backup_ref',
        'originals_restored',
        'metadata_restored',
        'digest_set_verified',
        'decryption_verified',
        'result',
    ];

    private const array KINDS = ['restore', 'drill'];

    private const array PROOFS = [
        'originals_restored',
        'metadata_restored',
        'digest_set_verified',
        'decryption_verified',
    ];

    private function __construct()
    {
    }

    /**
     * @return list<string> what is wrong with the record; empty when it is valid
     */
    public static function problemsWith(string $yaml): array
    {
        try {
            $record = Yaml::parse($yaml);
        } catch (ParseException) {
            return ['the record is not valid YAML'];
        }

        if (!\is_array($record)) {
            return ['the record is not a mapping'];
        }

        $problems = [];

        foreach (self::FIELDS as $field) {
            $value = $record[$field] ?? null;

            if (null === $value || '' === $value) {
                $problems[] = \sprintf('the field "%s" is missing', $field);
            }
        }

        if (isset($record['kind']) && !\in_array($record['kind'], self::KINDS, true)) {
            $problems[] = 'the kind is neither restore nor drill';
        }

        if (isset($record['result']) && 'passed' !== $record['result']) {
            $problems[] = 'the result is not passed';
        }

        foreach (self::PROOFS as $proof) {
            if (\array_key_exists($proof, $record) && true !== $record[$proof]) {
                $problems[] = \sprintf('"%s" is not true', $proof);
            }
        }

        return $problems;
    }

    /**
     * @param array<array-key, mixed> $config  the parsed `config/packages/documents.yaml`
     * @param list<string>            $records the YAML of every record on disk
     *
     * @return list<string> why production may not open ingest; empty when it may, or when it does not try to
     */
    public static function ingestViolations(array $config, array $records): array
    {
        $production = self::productionParameters($config);

        if (true !== ($production['documents.ingest_enabled'] ?? false)) {
            return [];
        }

        $mechanism = $production['documents.backup_mechanism'] ?? null;

        if (!\is_string($mechanism) || '' === $mechanism || 'none' === $mechanism) {
            return ['production opens ingest but declares no backup mechanism'];
        }

        foreach ($records as $yaml) {
            $record = Yaml::parse($yaml);

            if (
                [] === self::problemsWith($yaml)
                && \is_array($record)
                && 'restore' === ($record['kind'] ?? null)
                && $mechanism === ($record['mechanism'] ?? null)
            ) {
                return [];
            }
        }

        return [\sprintf('production opens ingest with no valid restore proof for the mechanism "%s"', $mechanism)];
    }

    /**
     * The parameters production resolves: the base block overridden by `when@prod`.
     *
     * @param array<array-key, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    private static function productionParameters(array $config): array
    {
        $base = \is_array($config['parameters'] ?? null) ? $config['parameters'] : [];
        $when = $config['when@prod'] ?? [];
        $prod = \is_array($when) ? ($when['parameters'] ?? []) : [];

        return \is_array($prod) ? [...$base, ...$prod] : $base;
    }
}
