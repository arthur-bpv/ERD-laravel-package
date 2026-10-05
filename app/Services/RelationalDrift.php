<?php

namespace App\Services;

use App\Support\DataTypeCatalog;

/**
 * Diz se a cópia Relacional ainda corresponde ao ER que a originou.
 *
 * A verificação é feita comparando o modelo Relacional guardado com o que o ER
 * atual gera — e não comparando timestamps, porque a cópia Relacional é salva
 * também quando o usuário arrasta ou edita uma tabela. Assim, o aviso só
 * aparece quando existe uma diferença de verdade entre as duas etapas.
 *
 * As posições das tabelas (`x`/`y`) entram de fora da comparação: arrastar uma
 * tabela no quadro Relacional é uma escolha do usuário, não um sinal de que o
 * ER mudou. O resto — nome da tabela, colunas, tipos, chaves, nulabilidade,
 * tamanho, chaves primárias e chaves estrangeiras — é conteúdo do modelo.
 */
class RelationalDrift
{
    /** Rótulos de `kind`: a mudança de tipo de uma tabela também é uma mudança. */
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
     * Só o booleano, sem montar a lista de mudanças.
     *
     * Para quem só precisa saber se o aviso aparece (a aba do quadro ER, o card
     * do dashboard) e não quer pagar pelo transform completo a cada render.
     *
     * @param  array  $source  `data` do diagrama ER
     * @param  array  $relational  `data` do diagrama Relacional
     */
    public function isOutdated(array $source, array $relational): bool
    {
        $fingerprint = $relational['sourceFingerprint'] ?? null;

        // Impressão digital igual significa que o ER não mudou desde a geração.
        // O que ainda difere no quadro Relacional é edição manual do usuário —
        // e isso nunca é motivo para acusar o ER de desatualizado. Decidir
        // isso aqui evita o transform, que é a parte cara de `changes`.
        if (is_string($fingerprint) && $fingerprint !== ''
            && hash_equals($fingerprint, $this->transformer->fingerprint($source))) {
            return false;
        }

        return $this->report($source, $relational)['outdated'];
    }

    /**
     * Estado da verificação, pronto para a view.
     *
     * @param  array  $source  `data` do diagrama ER
     * @param  array  $relational  `data` do diagrama Relacional
     * @return array{outdated: bool, changes: array<int, string>}
     */
    public function report(array $source, array $relational): array
    {
        $changes = $this->changes($source, $relational);
        $fingerprint = $relational['sourceFingerprint'] ?? null;

        // Sem impressão digital (modelos gerados antes dela existir) só é
        // possível afirmar que o modelo está velho quando ele nunca recebeu
        // edição manual: sem `customized`, toda diferença para o ER atual
        // só pode ter vindo do próprio ER. Com edições manuais, a diferença
        // é delas, e acusar o ER seria mentira.
        $outdated = is_string($fingerprint) && $fingerprint !== ''
            ? ! hash_equals($fingerprint, $this->transformer->fingerprint($source)) && $changes !== []
            : empty($relational['customized']) && $changes !== [];

        return [
            'outdated' => $outdated,
            'changes' => $changes,
        ];
    }

    /**
     * O que uma regeneração mudaria, em uma linha por mudança.
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
     * Colunas são casadas pelo NOME: é o que o usuário lê no quadro, e um
     * atributo renomeado no ER precisa aparecer como "removida + adicionada"
     * em vez de sumir sem explicação.
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
     * Tamanho e precisão: só interessam quando o tipo realmente os usa.
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
            // Normalizar os dois lados com o mesmo dialeto é o que permite
            // comparar um `length` gravado pela edição manual com um `length`
            // que o transformador nem escreveu.
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
