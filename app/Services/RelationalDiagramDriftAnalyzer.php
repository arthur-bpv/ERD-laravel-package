<?php

namespace App\Services;

use App\Support\DataTypeCatalog;

/**
 * Checks whether a relational copy still matches its source ER model.
 *
 * The ER fingerprint detects semantic changes since generation. Comparing
 * tables and foreign keys explains what regeneration would change. Timestamps
 * also advance after manual edits, so they cannot detect source drift.
 *
 * Table positions are excluded because dragging does not change the logical
 * model. Names, columns, types, keys, nullability, and sizes are compared.
 */
class RelationalDiagramDriftAnalyzer
{
    /** User facing labels for table kinds in the change report. */
    private const KIND_LABELS = [
        'strong' => 'entidade',
        'weak' => 'entidade fraca',
        'associative' => 'relação associativa',
        'complex' => 'relação complexa',
        'recursive' => 'autorrelacionamento',
        'multivalued' => 'atributo multivalorado',
    ];

    public function __construct(private ErToRelationalTransformer $transformer) {}

    /**
     * Return only the drift flag without constructing the change list.
     *
     * Used by views that only need the warning state on each render.
     *
     * @param  array  $source  ER diagram data
     * @param  array  $relational  Relational diagram data
     */
    public function isOutdated(array $source, array $relational): bool
    {
        $fingerprint = $relational['sourceFingerprint'] ?? null;

        if (is_string($fingerprint) && $fingerprint !== '') {
            return $this->sourceHasChanged($source, $fingerprint);
        }

        return $this->report($source, $relational)['outdated'];
    }

    /**
     * Drift state ready for the view.
     *
     * @param  array  $source  ER diagram data
     * @param  array  $relational  Relational diagram data
     * @return array{outdated: bool, changes: array<int, string>}
     */
    public function report(array $source, array $relational): array
    {
        $changes = $this->changes($source, $relational);
        $fingerprint = $relational['sourceFingerprint'] ?? null;

        // Without a fingerprint, differences prove source drift only if the
        // relational copy has no manual edits. Otherwise their source is unclear.
        $outdated = is_string($fingerprint) && $fingerprint !== ''
            ? $this->sourceHasChanged($source, $fingerprint)
            : empty($relational['customized']) && $changes !== [];

        if ($outdated && $changes === []) {
            $changes[] = 'O modelo ER mudou em relacionamentos ou cardinalidades desde a última geração.';
        }

        return [
            'outdated' => $outdated,
            'changes' => $changes,
        ];
    }

    private function sourceHasChanged(array $source, string $fingerprint): bool
    {
        // Older fingerprints included coordinates. Accept either format so
        // those copies do not appear outdated after an upgrade.
        return ! hash_equals($fingerprint, $this->transformer->fingerprint($source))
            && ! hash_equals($fingerprint, $this->transformer->legacyFingerprint($source));
    }

    /**
     * Describe each change that regeneration would make.
     *
     * @return array<int, string>
     */
    public function changes(array $source, array $relational): array
    {
        $generated = $this->transformer->transform($source);
        $dialect = DataTypeCatalog::has($relational['dialect'] ?? '')
            ? $relational['dialect']
            : 'mysql';

        return [
            ...$this->tableChanges($generated['tables'] ?? [], $relational['tables'] ?? [], $dialect),
            ...$this->foreignKeyChanges($generated['foreignKeys'] ?? [], $relational['foreignKeys'] ?? []),
        ];
    }

    /**
     * @param  array<int, array>  $generated
     * @param  array<int, array>  $stored
     * @return array<int, string>
     */
    private function tableChanges(array $generated, array $stored, string $dialect): array
    {
        $generated = $this->tablesById($generated);
        $stored = $this->tablesById($stored);
        $changes = [];

        foreach ($generated as $id => $table) {
            $changes = isset($stored[$id])
                ? [...$changes, ...$this->tableDifferences($stored[$id], $table, $dialect)]
                : [...$changes, sprintf(
                    'Tabela nova no ER: "%s" (%s, %d colunas).',
                    $table['name'],
                    $this->kindLabel($table['kind'] ?? null),
                    count($table['columns'] ?? []),
                )];
        }

        foreach ($stored as $id => $table) {
            if (! isset($generated[$id])) {
                $changes[] = sprintf('A tabela "%s" não existe no ER e seria removida.', $table['name']);
            }
        }

        return $changes;
    }

    /**
     * @return array<int, string>
     */
    private function tableDifferences(array $stored, array $generated, string $dialect): array
    {
        $name = (string) $generated['name'];
        $changes = [];

        if (($stored['name'] ?? null) !== $name) {
            $changes[] = sprintf('A tabela "%s" foi renomeada para "%s".', $stored['name'] ?? '', $name);
        }

        if (($stored['kind'] ?? null) !== ($generated['kind'] ?? null)) {
            $changes[] = sprintf(
                'A tabela "%s" passou de %s para %s.',
                $name,
                $this->kindLabel($stored['kind'] ?? null),
                $this->kindLabel($generated['kind'] ?? null),
            );
        }

        if (($stored['primaryKey'] ?? []) !== ($generated['primaryKey'] ?? [])) {
            $changes[] = sprintf(
                'A chave primária de "%s" mudou de %s para %s.',
                $name,
                $this->columnList($stored['primaryKey'] ?? []),
                $this->columnList($generated['primaryKey'] ?? []),
            );
        }

        return [
            ...$changes,
            ...$this->columnChanges($stored, $generated, $dialect),
        ];
    }

    /**
     * Match columns by displayed name. A rename should appear as one removal
     * and one addition in the change report.
     *
     * @return array<int, string>
     */
    private function columnChanges(array $stored, array $generated, string $dialect): array
    {
        $name = (string) $generated['name'];
        $storedColumns = $this->columnsByName($stored, $dialect);
        $generatedColumns = $this->columnsByName($generated, $dialect);
        $changes = [];

        foreach ($generatedColumns as $columnName => $column) {
            $changes = isset($storedColumns[$columnName])
                ? [...$changes, ...$this->columnDifferences($storedColumns[$columnName], $column, $name)]
                : [...$changes, sprintf('Coluna "%s" foi adicionada em "%s".', $columnName, $name)];
        }

        foreach ($storedColumns as $columnName => $column) {
            if (! isset($generatedColumns[$columnName])) {
                $changes[] = sprintf(
                    'A coluna "%s" não existe no ER e seria removida de "%s".',
                    $columnName,
                    $name,
                );
            }
        }

        return $changes;
    }

    /**
     * @return array<int, string>
     */
    private function columnDifferences(array $stored, array $generated, string $table): array
    {
        $column = (string) $generated['name'];
        $changes = [];

        if (($stored['type'] ?? '') !== ($generated['type'] ?? '')) {
            $changes[] = sprintf(
                'A coluna "%s" de "%s" passou de %s para %s.',
                $column,
                $table,
                $stored['type'] ?? '',
                $generated['type'] ?? '',
            );
        }

        if (($stored['key'] ?? '') !== ($generated['key'] ?? '')) {
            $changes[] = sprintf(
                'A coluna "%s" de "%s" passou a ser %s no ER.',
                $column,
                $table,
                $this->keyLabel($generated['key'] ?? ''),
            );
        }

        if (($stored['nullable'] ?? false) !== ($generated['nullable'] ?? false)) {
            $changes[] = sprintf(
                ($generated['nullable'] ?? false)
                    ? 'A coluna "%s" de "%s" passou a aceitar NULL.'
                    : 'A coluna "%s" de "%s" deixou de aceitar NULL.',
                $column,
                $table,
            );
        }

        $changes = [...$changes, ...$this->columnSizeChanges($stored, $generated, $column, $table)];

        $from = $this->referenceLabel($stored);
        $to = $this->referenceLabel($generated);

        if ($from !== $to) {
            $changes[] = sprintf(
                $to === '' ? 'A coluna "%s" de "%s" deixou de ser chave estrangeira.' : 'A coluna "%s" de "%s" passou a referenciar %s.',
                $column,
                $table,
                $to,
            );
        }

        return $changes;
    }

    /**
     * Report length and precision only for types that use them.
     *
     * @return array<int, string>
     */
    private function columnSizeChanges(array $stored, array $generated, string $column, string $table): array
    {
        $changes = [];

        if (DataTypeCatalog::kind($generated['type'] ?? '') === 'length'
            && ($stored['length'] ?? null) !== ($generated['length'] ?? null)) {
            $changes[] = sprintf(
                'A coluna "%s" de "%s" mudou de tamanho %s para %s.',
                $column,
                $table,
                $this->sizeLabel($stored['length'] ?? null),
                $this->sizeLabel($generated['length'] ?? null),
            );
        }

        if (DataTypeCatalog::kind($generated['type'] ?? '') === 'decimal') {
            $before = [(int) ($stored['precision'] ?? 0), (int) ($stored['scale'] ?? 0)];
            $after = [(int) ($generated['precision'] ?? 0), (int) ($generated['scale'] ?? 0)];

            if ($before !== $after) {
                $changes[] = sprintf(
                    'A coluna "%s" de "%s" mudou de precisão %s para %s.',
                    $column,
                    $table,
                    $this->decimalLabel($before),
                    $this->decimalLabel($after),
                );
            }
        }

        return $changes;
    }

    /**
     * @param  array<int, array>  $generated
     * @param  array<int, array>  $stored
     * @return array<int, string>
     */
    private function foreignKeyChanges(array $generated, array $stored): array
    {
        $generated = $this->foreignKeysByRoute($generated);
        $stored = $this->foreignKeysByRoute($stored);
        $changes = [];

        foreach ($generated as $route => $foreignKey) {
            if (! isset($stored[$route])) {
                $changes[] = sprintf(
                    'Chave estrangeira nova no ER: %s (%s).',
                    $route,
                    $foreignKey['cardinality'] ?? 'N:1',
                );

                continue;
            }

            $from = $stored[$route]['cardinality'] ?? 'N:1';
            $to = $foreignKey['cardinality'] ?? 'N:1';

            if ($from !== $to) {
                $changes[] = sprintf('A chave estrangeira %s passou de %s para %s.', $route, $from, $to);
            }
        }

        foreach ($stored as $route => $foreignKey) {
            if (! isset($generated[$route])) {
                $changes[] = sprintf('A chave estrangeira %s não existe no ER e seria removida.', $route);
            }
        }

        return $changes;
    }

    /**
     * @param  array<int, array>  $tables
     * @return array<string, array>
     */
    private function tablesById(array $tables): array
    {
        $indexed = [];

        foreach ($tables as $table) {
            $indexed[(string) ($table['id'] ?? '')] = $table;
        }

        return $indexed;
    }

    /**
     * @return array<string, array>
     */
    private function columnsByName(array $table, string $dialect): array
    {
        $columns = [];

        foreach ($table['columns'] ?? [] as $column) {
            // Normalize both sides with the same dialect before comparison.
            $column = DataTypeCatalog::normalize($dialect, $column);
            $columns[(string) ($column['name'] ?? '')] = $column;
        }

        return $columns;
    }

    /**
     * @param  array<int, array>  $foreignKeys
     * @return array<string, array>
     */
    private function foreignKeysByRoute(array $foreignKeys): array
    {
        $routes = [];

        foreach ($foreignKeys as $foreignKey) {
            $routes[$this->route($foreignKey)] = $foreignKey;
        }

        return $routes;
    }

    private function route(array $foreignKey): string
    {
        return sprintf(
            '%s.%s → %s.%s',
            $foreignKey['fromTable'] ?? '',
            $foreignKey['fromColumn'] ?? '',
            $foreignKey['toTable'] ?? '',
            $foreignKey['toColumn'] ?? '',
        );
    }

    private function referenceLabel(array $column): string
    {
        $references = $column['references'] ?? null;

        if (! is_array($references)) {
            return '';
        }

        return ($references['table'] ?? '').'.'.($references['column'] ?? '');
    }

    private function kindLabel(?string $kind): string
    {
        return self::KIND_LABELS[$kind] ?? 'tabela';
    }

    private function keyLabel(string $key): string
    {
        return $key === '' ? 'sem chave' : $key;
    }

    private function sizeLabel(mixed $length): string
    {
        return is_numeric($length) ? (string) $length : 'sem tamanho';
    }

    /**
     * @param  array{0:int, 1:int}  $size
     */
    private function decimalLabel(array $size): string
    {
        return $size[0].','.$size[1];
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function columnList(array $columns): string
    {
        return $columns === [] ? 'sem chave primária' : implode(' + ', $columns);
    }
}
