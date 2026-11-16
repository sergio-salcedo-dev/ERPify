<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The validator of the Documents restore-proof records: one YAML file per proof under
 * `docs/runbooks/documents-restore-proofs/` (format and procedure: `docs/runbooks/documents-restore-proof.md`),
 * and the rule that ties them to the ingest switch.
 *
 * A record is valid when it carries every field of {@see FIELDS} with a non-empty value, `kind` is `restore` or
 * `drill`, `result` is `passed`, and the four booleans are true; a `drill` also carries `ledger_replay_verified: true`
 * (the restore drill replayed the erasure ledger). The switch rules: production configuration may set
 * `documents.ingest_enabled` true only when a valid record of kind `restore` names, as its `mechanism`, the backup
 * mechanism that same configuration declares, and `documents.read_enabled` true only when a valid `restore` AND a
 * valid `drill` do — a historical record for another mechanism does not count.
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

    private const string INGEST_SWITCH = 'documents.ingest_enabled';

    private const string READ_SWITCH = 'documents.read_enabled';

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

        if ('drill' === ($record['kind'] ?? null) && true !== ($record['ledger_replay_verified'] ?? null)) {
            $problems[] = 'a drill must carry "ledger_replay_verified" true';
        }

        return $problems;
    }

    /**
     * Production keeps ingest closed only with the boolean literal `false`. Anything else — `true`, `'true'`, `1`,
     * an `%env(…)%` a deploy resolves, an absent parameter — counts as opening it, because what it resolves to is
     * decided somewhere this gate cannot read.
     *
     * @param array<array-key, mixed> $config  the parsed `config/packages/documents.yaml`
     * @param list<string>            $records the YAML of every record on disk
     *
     * @return list<string> why production may not open ingest; empty when it may, or when it does not try to
     */
    public static function ingestViolations(array $config, array $records): array
    {
        return self::violations(self::INGEST_SWITCH, 'ingest', ['restore'], $config, $records);
    }

    /**
     * The same rule for the read route, with the stricter proof it owes: a valid `restore` record AND a valid
     * `drill` record (which carries `ledger_replay_verified`), each naming the declared backup mechanism.
     *
     * @param array<array-key, mixed> $config
     * @param list<string>            $records
     *
     * @return list<string> why production may not open reads; empty when it may, or when it does not try to
     */
    public static function readViolations(array $config, array $records): array
    {
        return self::violations(self::READ_SWITCH, 'reads', ['restore', 'drill'], $config, $records);
    }

    /**
     * The files under `$configRoot`, other than `$ownFile`, that name `$switch` other than as a `%…%` reference.
     * The gate reads one file; a declaration anywhere else — a `config/packages/prod/` override, a `parameters:`
     * block in services.yaml, a PHP or XML loader — would decide production's value out of its sight.
     *
     * @return list<string> the offending paths, relative to `$configRoot`
     */
    public static function declarationsOutside(
        string $configRoot,
        string $ownFile,
        string $switch = self::INGEST_SWITCH,
    ): array {
        $offending = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $configRoot,
            FilesystemIterator::SKIP_DOTS,
        ));

        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getRealPath() === \realpath($ownFile)) {
                continue;
            }

            $contents = (string) \file_get_contents($file->getPathname());
            $withoutReferences = \str_replace('%' . $switch . '%', '', $contents);

            if (\str_contains($withoutReferences, $switch)) {
                $offending[] = \substr($file->getPathname(), \strlen(\rtrim($configRoot, '/')) + 1);
            }
        }

        \sort($offending);

        return $offending;
    }

    /**
     * @param list<string>            $kinds   every kind of valid record that must exist for the mechanism
     * @param array<array-key, mixed> $config
     * @param list<string>            $records
     *
     * @return list<string>
     */
    private static function violations(string $switch, string $noun, array $kinds, array $config, array $records): array
    {
        $production = self::productionParameters($config);

        if (false === ($production[$switch] ?? null)) {
            return [];
        }

        $mechanism = $production['documents.backup_mechanism'] ?? null;

        if (!\is_string($mechanism) || '' === $mechanism || 'none' === $mechanism) {
            return [\sprintf('production opens %s but declares no backup mechanism', $noun)];
        }

        $missing = [];

        foreach ($kinds as $kind) {
            if (!self::hasValidRecord($records, $kind, $mechanism)) {
                $missing[] = \sprintf(
                    'production opens %s with no valid %s record for the mechanism "%s"',
                    $noun,
                    $kind,
                    $mechanism,
                );
            }
        }

        return $missing;
    }

    /**
     * @param list<string> $records
     */
    private static function hasValidRecord(array $records, string $kind, string $mechanism): bool
    {
        foreach ($records as $yaml) {
            $record = Yaml::parse($yaml);

            if (
                [] === self::problemsWith($yaml)
                && \is_array($record)
                && $kind === ($record['kind'] ?? null)
                && $mechanism === ($record['mechanism'] ?? null)
            ) {
                return true;
            }
        }

        return false;
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
