<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\ConnectionInterface;

final class TenantBaselineInspector
{
    /** @return array{state: string, fingerprint: string, expected_fingerprint: string, baseline_total: int, baseline_recorded: int, errors: list<string>} */
    public function inspect(ConnectionInterface $connection): array
    {
        $baseline = $this->baselineMigrationNames();
        $tables = $this->tableNames($connection);

        if ($tables === [] || ($tables === ['migrations'] && $this->recordedMigrations($connection) === [])) {
            return [
                'state' => 'empty',
                'fingerprint' => '',
                'expected_fingerprint' => $this->expectedFingerprint(),
                'baseline_total' => count($baseline),
                'baseline_recorded' => 0,
                'errors' => [],
            ];
        }

        if (! in_array('migrations', $tables, true)) {
            return $this->incompatible('', count($baseline), 0, ['The migrations table is missing from a non-empty tenant schema.']);
        }

        $fingerprint = $this->fingerprint($connection);
        $expected = $this->expectedFingerprint();
        $recorded = $this->recordedMigrations($connection);
        $baselineRecorded = count(array_intersect($baseline, $recorded));
        $errors = [];

        if ($expected === '') {
            $errors[] = 'The consolidated tenant schema fingerprint is not configured.';
        } elseif (! hash_equals($expected, $fingerprint)) {
            $errors[] = "Schema fingerprint mismatch: expected {$expected}, got {$fingerprint}.";
        }

        if ($baselineRecorded === count($baseline)) {
            $state = 'adopted';
        } elseif ($baselineRecorded !== 0) {
            $state = 'incompatible';
            $errors[] = "Partial baseline adoption detected ({$baselineRecorded}/".count($baseline).').';
        } elseif ($this->hasAllLegacyMarkers($recorded)) {
            $state = 'legacy';
        } else {
            $state = 'incompatible';
            $errors[] = 'Neither a complete consolidated baseline nor the recognized historical migration chain was found.';
        }

        if ($errors !== []) {
            $state = 'incompatible';
        }

        return [
            'state' => $state,
            'fingerprint' => $fingerprint,
            'expected_fingerprint' => $expected,
            'baseline_total' => count($baseline),
            'baseline_recorded' => $baselineRecorded,
            'errors' => $errors,
        ];
    }

    /** @return array{state: string, fingerprint: string, expected_fingerprint: string, baseline_total: int, baseline_recorded: int, errors: list<string>} */
    public function adopt(ConnectionInterface $connection): array
    {
        return $connection->transaction(function () use ($connection): array {
            $lockKey = config('tenant-baseline.lock_key');
            if (! is_string($lockKey) || $lockKey === '') {
                throw new \RuntimeException('Tenant baseline lock key is not configured.');
            }

            $connection->select('SELECT pg_advisory_xact_lock(hashtext(?))', [$lockKey]);
            $connection->statement('LOCK TABLE migrations IN SHARE ROW EXCLUSIVE MODE');

            $inspection = $this->inspect($connection);
            if ($inspection['state'] === 'adopted') {
                return $inspection;
            }
            if ($inspection['state'] !== 'legacy') {
                throw new \RuntimeException(implode(' ', $inspection['errors']) ?: "Tenant baseline state [{$inspection['state']}] cannot be adopted.");
            }

            $baseline = $this->baselineMigrationNames();
            $recorded = $this->recordedMigrations($connection);
            $missing = array_values(array_diff($baseline, $recorded));
            $batch = ((int) $connection->table('migrations')->max('batch')) + 1;

            foreach ($missing as $migration) {
                $connection->table('migrations')->insert([
                    'migration' => $migration,
                    'batch' => $batch,
                ]);
            }

            $verified = $this->inspect($connection);
            if ($verified['state'] !== 'adopted') {
                throw new \RuntimeException('Tenant baseline adoption verification failed; the transaction was rolled back.');
            }

            return $verified;
        }, 1);
    }

    public function fingerprint(ConnectionInterface $connection): string
    {
        return hash('sha256', (string) json_encode(
            $this->semanticSnapshot($connection),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * Return the canonical schema representation used by the compatibility
     * guard. It contains metadata only and never reads tenant business rows.
     *
     * @return array{tables: list<string>, columns: list<array<string, mixed>>, constraints: list<array<string, mixed>>, indexes: list<array<string, mixed>>}
     */
    public function semanticSnapshot(ConnectionInterface $connection): array
    {
        return [
            'tables' => $this->tableNames($connection),
            'columns' => $this->columns($connection),
            'constraints' => $this->constraints($connection),
            'indexes' => $this->indexes($connection),
        ];
    }

    /** @return list<string> */
    public function baselineMigrationNames(): array
    {
        $path = config('tenant-baseline.migration_path');
        if (! is_string($path) || ! is_dir($path)) {
            throw new \RuntimeException('Tenant baseline migration path is invalid.');
        }

        $files = glob(rtrim($path, '/').'/0001_01_01_*.php') ?: [];
        $names = array_map(static fn (string $file): string => pathinfo($file, PATHINFO_FILENAME), $files);
        sort($names, SORT_STRING);

        if ($names === []) {
            throw new \RuntimeException('No consolidated tenant baseline migrations were found.');
        }

        return array_values($names);
    }

    /** @return list<string> */
    private function tableNames(ConnectionInterface $connection): array
    {
        return array_values(array_map(
            static fn (object $row): string => (string) $row->table_name,
            $connection->select(<<<'SQL'
                SELECT c.relname AS table_name
                FROM pg_class c
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = current_schema()
                  AND c.relkind IN ('r', 'p')
                ORDER BY c.relname
                SQL),
        ));
    }

    /** @return list<array<string, mixed>> */
    private function columns(ConnectionInterface $connection): array
    {
        $columns = array_map(function (object $row): array {
            return [
                'table' => (string) $row->table_name,
                'column' => (string) $row->column_name,
                'data_type' => (string) $row->data_type,
                'udt' => (string) $row->udt_name,
                'nullable' => (string) $row->is_nullable,
                'default' => $this->normalizeSql($row->column_default),
                'length' => $row->character_maximum_length === null ? null : (int) $row->character_maximum_length,
                'precision' => $row->numeric_precision === null ? null : (int) $row->numeric_precision,
                'scale' => $row->numeric_scale === null ? null : (int) $row->numeric_scale,
            ];
        }, $connection->select(<<<'SQL'
            SELECT table_name, column_name, data_type, udt_name, is_nullable,
                   column_default, character_maximum_length, numeric_precision, numeric_scale
            FROM information_schema.columns
            WHERE table_schema = current_schema()
            ORDER BY table_name, column_name
            SQL));

        return $this->sortCanonicalRows($columns);
    }

    /** @return list<array<string, mixed>> */
    private function constraints(ConnectionInterface $connection): array
    {
        $constraints = array_map(function (object $row): array {
            return [
                'table' => (string) $row->table_name,
                'type' => (string) $row->constraint_type,
                'columns' => (string) $row->columns,
                'referenced_table' => (string) $row->referenced_table,
                'referenced_columns' => (string) $row->referenced_columns,
                'update_action' => (string) $row->update_action,
                'delete_action' => (string) $row->delete_action,
                'deferrable' => (bool) $row->deferrable,
                'deferred' => (bool) $row->deferred,
                'check' => $this->normalizeCheck($row->check_expression),
            ];
        }, $connection->select(<<<'SQL'
            SELECT c.relname AS table_name,
                   con.contype AS constraint_type,
                   COALESCE((
                       SELECT string_agg(a.attname, ',' ORDER BY keys.ordinality)
                       FROM unnest(con.conkey) WITH ORDINALITY AS keys(attnum, ordinality)
                       JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = keys.attnum
                   ), '') AS columns,
                   COALESCE(ref.relname, '') AS referenced_table,
                   COALESCE((
                       SELECT string_agg(a.attname, ',' ORDER BY keys.ordinality)
                       FROM unnest(con.confkey) WITH ORDINALITY AS keys(attnum, ordinality)
                       JOIN pg_attribute a ON a.attrelid = con.confrelid AND a.attnum = keys.attnum
                   ), '') AS referenced_columns,
                   con.confupdtype AS update_action,
                   con.confdeltype AS delete_action,
                   con.condeferrable AS deferrable,
                   con.condeferred AS deferred,
                   CASE WHEN con.contype = 'c' THEN pg_get_expr(con.conbin, con.conrelid) ELSE '' END AS check_expression
            FROM pg_constraint con
            JOIN pg_class c ON c.oid = con.conrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            LEFT JOIN pg_class ref ON ref.oid = con.confrelid
            WHERE n.nspname = current_schema()
            ORDER BY c.relname, con.contype, columns, referenced_table, referenced_columns
            SQL));

        return $this->sortCanonicalRows($constraints);
    }

    /** @return list<array<string, mixed>> */
    private function indexes(ConnectionInterface $connection): array
    {
        $indexes = array_map(function (object $row): array {
            $definition = preg_replace('/^CREATE\s+(UNIQUE\s+)?INDEX\s+\S+\s+ON\s+/i', 'CREATE $1INDEX ON ', (string) $row->definition);

            return [
                'table' => (string) $row->table_name,
                'definition' => $this->normalizeSql($definition),
            ];
        }, $connection->select(<<<'SQL'
            SELECT tablename AS table_name, indexdef AS definition
            FROM pg_indexes
            WHERE schemaname = current_schema()
            ORDER BY tablename, indexdef
            SQL));

        return $this->sortCanonicalRows($indexes);
    }

    /**
     * Database object names are deliberately excluded from the semantic rows.
     * Sort after normalization so those names cannot influence the fingerprint
     * indirectly through PostgreSQL's result order.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sortCanonicalRows(array $rows): array
    {
        usort($rows, static fn (array $left, array $right): int => strcmp(
            (string) json_encode($left, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            (string) json_encode($right, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ));

        return $rows;
    }

    private function normalizeCheck(mixed $value): ?string
    {
        $normalized = $this->normalizeSql($value);
        if ($normalized === null) {
            return null;
        }

        $normalized = preg_replace('/::(?:character varying|text)(?:\[\])?/i', '', $normalized);
        $normalized = preg_replace("/\\('((?:[^']|'')*)'\\)/", "'$1'", (string) $normalized);
        $normalized = preg_replace('/\(([a-z_][a-z0-9_]*)\)/i', '$1', (string) $normalized);
        $normalized = preg_replace('/any\s*\(\(array\[(.*)\]\)\)/i', 'any (array[$1])', (string) $normalized);

        return $this->normalizeSql($normalized);
    }

    private function normalizeSql(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $value)));
    }

    /** @return list<string> */
    private function recordedMigrations(ConnectionInterface $connection): array
    {
        return array_values(array_map(
            static fn (mixed $migration): string => (string) $migration,
            $connection->table('migrations')->orderBy('migration')->pluck('migration')->all(),
        ));
    }

    /** @param list<string> $recorded */
    private function hasAllLegacyMarkers(array $recorded): bool
    {
        $markers = config('tenant-baseline.legacy_markers', []);
        if (! is_array($markers) || $markers === []) {
            return false;
        }

        return array_diff($markers, $recorded) === [];
    }

    private function expectedFingerprint(): string
    {
        $fingerprint = config('tenant-baseline.schema_fingerprint');

        return is_string($fingerprint) ? trim($fingerprint) : '';
    }

    /** @param list<string> $errors
     * @return array{state: string, fingerprint: string, expected_fingerprint: string, baseline_total: int, baseline_recorded: int, errors: list<string>}
     */
    private function incompatible(string $fingerprint, int $baselineTotal, int $baselineRecorded, array $errors): array
    {
        return [
            'state' => 'incompatible',
            'fingerprint' => $fingerprint,
            'expected_fingerprint' => $this->expectedFingerprint(),
            'baseline_total' => $baselineTotal,
            'baseline_recorded' => $baselineRecorded,
            'errors' => $errors,
        ];
    }
}
