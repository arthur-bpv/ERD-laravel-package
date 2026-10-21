<?php

namespace App\Support;

use App\Services\ErToRelationalTransformer;
use InvalidArgumentException;

/** Reads schema definitions as data; imported SQL is never executed. */
final class SqlDiagramImporter
{
    private array $tables = [];

    private array $constraints = [];

    private array $warnings = [];

    public static function detectDialect(string $sql): ?string
    {
        if (strlen($sql) > 2_000_000) {
            return null;
        }
        // Our SQL exports and common dump tools identify their database explicitly.
        foreach (['sqlite' => 'SQLite', 'mysql' => 'MySQL|MariaDB', 'pgsql' => 'PostgreSQL', 'oracle' => 'Oracle', 'sqlsrv' => 'SQL Server'] as $dialect => $label) {
            if (preg_match('/^\s*--\s*(?:Código SQL para:\s*|.*\b(?:dump|database|server)\b.*)(?:'.$label.')\b/imu', $sql)
                || preg_match('/^\s*--\s*(?:'.$label.')\s+(?:database\s+)?dump\b/im', $sql)) {
                return $dialect;
            }
        }
        try {
            $tokens = SqlDdlTokenizer::tokens($sql);
        } catch (InvalidArgumentException) {
            return null;
        }
        // Remove literals so a default value or inserted row cannot select a database.
        $syntax = strtoupper(implode(' ', array_filter($tokens, fn (string $token): bool => ! in_array($token[0] ?? '', ["'", '$'], true))));
        $signals = [
            'sqlite' => '/\b(?:PRAGMA|AUTOINCREMENT|WITHOUT ROWID)\b/',
            'mysql' => '/\b(?:AUTO_INCREMENT|ENGINE\s*=\s*\w+|UNSIGNED|CHARSET\s*=\s*\w+)\b|`[^`]+`/',
            'pgsql' => '/\b(?:BIGSERIAL|SMALLSERIAL|SERIAL|JSONB|BYTEA|TIMESTAMPTZ|SET SEARCH_PATH|PG_CATALOG)\b/',
            'oracle' => '/\b(?:VARCHAR2|NVARCHAR2|NUMBER|BINARY_FLOAT|BINARY_DOUBLE|TABLESPACE)\b/',
            'sqlsrv' => '/\b(?:IDENTITY|UNIQUEIDENTIFIER|DATETIME2|DATETIMEOFFSET|GO)\b|\[[^\]]+\]/',
        ];
        $matches = array_keys(array_filter($signals, fn (string $pattern): bool => (bool) preg_match($pattern, $syntax)));

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @return array{er:array,relational:array} */
    public function parse(string $sql, ?string $dialect = null): array
    {
        if (strlen($sql) > 2_000_000) {
            throw new InvalidArgumentException('O arquivo ultrapassa o limite de 2 MB.');
        }
        $dialect = $dialect ?: self::detectDialect($sql);
        if (! $dialect || ! DataTypeCatalog::has($dialect)) {
            throw new InvalidArgumentException('Não foi possível identificar o banco com segurança. Selecione o banco de dados para importar.');
        }
        $this->tables = $this->constraints = $this->warnings = [];
        $statements = SqlDdlTokenizer::split(SqlDdlTokenizer::tokens($sql), ';');
        foreach ($statements as $statement) {
            $head = strtoupper(implode(' ', array_slice($statement, 0, 4)));
            if (preg_match('/^CREATE (?:TEMPORARY |TEMP |UNLOGGED )?TABLE\b/', $head)) {
                $this->createTable($statement);
            } elseif (str_starts_with($head, 'ALTER TABLE ')) {
                $this->alterTable($statement);
            } elseif (preg_match('/^CREATE UNIQUE (?:INDEX|KEY)\b/', $head)) {
                $this->uniqueIndex($statement);
            } else {
                $routine = strtoupper($statement[0] ?? '') === 'CREATE'
                    && preg_match('/\b(?:FUNCTION|PROCEDURE|TRIGGER|EVENT)\b/', strtoupper(implode(' ', array_slice($statement, 0, 12))));
                $opaqueBody = array_filter($statement, fn (string $token): bool => str_starts_with($token, '$'));
                if (strtoupper($statement[0] ?? '') === 'DELIMITER' || ($routine && $opaqueBody === [])) {
                    throw new InvalidArgumentException('Remova as rotinas e os comandos DELIMITER do arquivo; importe apenas a estrutura das tabelas.');
                }
                $this->warnings[] = 'Comandos fora das definições de tabelas e chaves foram ignorados (dados, views, índices comuns e rotinas).';
            }
        }
        if ($this->tables === []) {
            throw new InvalidArgumentException('Nenhuma definição CREATE TABLE foi encontrada no SQL.');
        }
        if (count($this->tables) > 250 || count($this->constraints) > 1000) {
            throw new InvalidArgumentException('Limite: 250 tabelas e 1000 restrições.');
        }

        return $this->buildDiagrams($dialect);
    }

    private function createTable(array $tokens): void
    {
        $position = 1;
        if (in_array(strtoupper($tokens[$position] ?? ''), ['TEMP', 'TEMPORARY', 'UNLOGGED'], true)) {
            $position++;
        }
        $position++; // TABLE
        if (strtoupper($tokens[$position] ?? '') === 'IF') {
            $position += 3; // IF NOT EXISTS
        }
        $name = SqlDdlTokenizer::qualifiedName($tokens, $position);
        $key = strtolower($name);
        if (isset($this->tables[$key])) {
            throw new InvalidArgumentException("Tabela duplicada: {$name}.");
        }
        $definitions = SqlDdlTokenizer::split(SqlDdlTokenizer::group($tokens, $position), ',');
        $index = count($this->tables);
        $this->tables[$key] = [
            'id' => 'e'.($index + 1), 'name' => $name, 'kind' => 'strong',
            'x' => 60 + ($index % 3) * 460, 'y' => 60 + intdiv($index, 3) * 420,
            'columns' => [], 'primaryKey' => [],
        ];
        foreach ($definitions as $definition) {
            $head = strtoupper($definition[0]);
            if (in_array($head, ['CONSTRAINT', 'PRIMARY', 'UNIQUE', 'FOREIGN', 'CHECK', 'KEY', 'INDEX', 'FULLTEXT', 'SPATIAL'], true)) {
                $this->constraint($key, $definition);
            } else {
                $this->column($key, $definition);
            }
        }
        if ($this->tables[$key]['columns'] === []) {
            throw new InvalidArgumentException("A tabela {$name} não possui colunas.");
        }
    }

    private function column(string $table, array $tokens): void
    {
        $position = 0;
        $name = SqlDdlTokenizer::identifier($tokens, $position);
        foreach ($this->tables[$table]['columns'] as $column) {
            if (strcasecmp($column['name'], $name) === 0) {
                throw new InvalidArgumentException("Coluna duplicada: {$name}.");
            }
        }
        $type = strtolower(SqlDdlTokenizer::identifier($tokens, $position));
        $nativeType = $type;
        if ($type === 'double' && strtoupper($tokens[$position] ?? '') === 'PRECISION') {
            $position++;
        } elseif ($type === 'character' && strtoupper($tokens[$position] ?? '') === 'VARYING') {
            $type = 'varchar';
            $position++;
        }
        $args = ($tokens[$position] ?? '') === '(' ? SqlDdlTokenizer::group($tokens, $position) : [];
        $type = match ($type) {
            'int', 'int4', 'serial', 'smallserial' => 'integer', 'int8', 'bigserial' => 'bigint',
            'varchar2', 'character' => 'varchar', 'nvarchar2' => 'nvarchar',
            'number' => 'decimal', 'bool', 'bit' => 'boolean', 'real', 'binary_float' => 'float',
            'binary_double' => 'double', 'clob', 'nclob' => 'text', 'bytea' => 'blob',
            'raw' => 'varbinary', 'jsonb' => 'json', 'uniqueidentifier' => 'uuid',
            'datetime2' => 'datetime', 'datetimeoffset', 'timestamptz' => 'timestamp',
            default => $type,
        };
        $column = [
            'id' => $this->tables[$table]['id'].'.c'.(count($this->tables[$table]['columns']) + 1),
            'name' => $name, 'type' => $type, 'key' => '', 'nullable' => true,
            'references' => null, 'source' => $this->tables[$table]['id'],
        ];
        $known = $type === 'enum';
        foreach (DataTypeCatalog::DIALECTS as $config) {
            $known = $known || isset($config['types'][$type]);
        }
        if (! $known) {
            $column['type'] = 'custom';
            $column['customType'] = $nativeType.($args !== [] ? '('.implode('', $args).')' : '');
        } elseif (strtoupper($args[0] ?? '') === 'MAX') {
            $column['type'] = 'custom';
            $column['customType'] = $nativeType.'(MAX)';
        }
        if ($type === 'enum') {
            $column['enumValues'] = array_map(fn (array $value): string => str_replace("''", "'", substr($value[0], 1, -1)), SqlDdlTokenizer::split($args, ','));
        } elseif (in_array($type, DataTypeCatalog::LENGTH_TYPES, true) && isset($args[0]) && ctype_digit($args[0])) {
            $column['length'] = (int) $args[0];
        } elseif (in_array($type, DataTypeCatalog::DECIMAL_TYPES, true) && isset($args[0]) && ctype_digit($args[0])) {
            $column['precision'] = (int) $args[0];
            $column['scale'] = isset($args[2]) ? (int) $args[2] : 0;
        }
        $tail = array_slice($tokens, $position);
        $syntax = strtoupper(implode(' ', array_filter($tail, fn (string $token): bool => ! str_starts_with($token, "'"))));
        $primary = str_contains($syntax, 'PRIMARY KEY');
        $column['nullable'] = ! $primary && ! str_contains($syntax, 'NOT NULL');
        $column['key'] = $primary ? 'PK' : (preg_match('/\bUNIQUE\b/', $syntax) ? 'UQ' : '');
        if ($primary) {
            $this->constraints[] = ['table' => $table, 'kind' => 'PRIMARY', 'columns' => [$name]];
        }
        $referencePosition = array_search('REFERENCES', array_map('strtoupper', $tail), true);
        if ($referencePosition !== false) {
            $referencePosition++;
            $this->reference($table, [$name], $tail, $referencePosition);
        }
        if (preg_match('/\b(?:DEFAULT|CHECK|GENERATED|IDENTITY|AUTO_INCREMENT|AUTOINCREMENT|COLLATE)\b/', $syntax)) {
            $this->warnings[] = 'Defaults, checks, geração automática e collations não são representados no diagrama.';
        }
        $this->tables[$table]['columns'][] = $column;
    }

    private function constraint(string $table, array $tokens): void
    {
        $position = 0;
        if (strtoupper($tokens[$position]) === 'CONSTRAINT') {
            $position += 2;
        }
        $kind = strtoupper($tokens[$position++] ?? '');
        if (! in_array($kind, ['PRIMARY', 'UNIQUE', 'FOREIGN'], true)) {
            $this->warnings[] = 'Checks e índices comuns não são representados no diagrama.';

            return;
        }
        while (isset($tokens[$position]) && $tokens[$position] !== '(') {
            $position++;
        }
        $columns = $this->columnList($tokens, $position);
        if ($kind === 'FOREIGN') {
            if (strtoupper($tokens[$position++] ?? '') !== 'REFERENCES') {
                throw new InvalidArgumentException('Chave estrangeira sem REFERENCES.');
            }
            $this->reference($table, $columns, $tokens, $position);
        } else {
            if ($kind === 'UNIQUE' && count($columns) > 1) {
                throw new InvalidArgumentException('Chaves UNIQUE compostas ainda não são suportadas pelo editor.');
            }
            $this->constraints[] = compact('table', 'kind', 'columns');
        }
    }

    private function columnList(array $tokens, int &$position): array
    {
        $columns = array_map(function (array $part): string {
            $position = 0;
            $name = SqlDdlTokenizer::identifier($part, $position);
            if (count($part) !== 1 && ! (count($part) === 2 && in_array(strtoupper($part[1]), ['ASC', 'DESC'], true))) {
                throw new InvalidArgumentException('Expressões em chaves não são suportadas.');
            }

            return $name;
        }, SqlDdlTokenizer::split(SqlDdlTokenizer::group($tokens, $position), ','));
        if ($columns === [] || count(array_unique(array_map('strtolower', $columns))) !== count($columns)) {
            throw new InvalidArgumentException('Uma chave precisa conter colunas distintas.');
        }

        return $columns;
    }

    private function reference(string $table, array $columns, array $tokens, int $position): void
    {
        $target = SqlDdlTokenizer::qualifiedName($tokens, $position);
        $targetColumns = ($tokens[$position] ?? '') === '(' ? $this->columnList($tokens, $position) : [];
        $this->constraints[] = ['table' => $table, 'kind' => 'FOREIGN', 'columns' => $columns, 'target' => strtolower($target), 'targetColumns' => $targetColumns];
        if (isset($tokens[$position])) {
            $this->warnings[] = 'Ações ON DELETE/UPDATE e opções de restrições não são representadas no diagrama.';
        }
    }

    private function alterTable(array $tokens): void
    {
        $position = 2;
        if (strtoupper($tokens[$position] ?? '') === 'ONLY') {
            $position++;
        }
        $table = strtolower(SqlDdlTokenizer::qualifiedName($tokens, $position));
        foreach (SqlDdlTokenizer::split(array_slice($tokens, $position), ',') as $action) {
            $syntax = strtoupper(implode(' ', $action));
            if (preg_match('/^(?:OWNER TO |(?:ENABLE|DISABLE) KEYS$|ALTER COLUMN .+ SET DEFAULT )/', $syntax)) {
                $this->warnings[] = 'Proprietários, defaults e opções de índices não são representados no diagrama.';

                continue;
            }
            if (strtoupper($action[0] ?? '') !== 'ADD') {
                throw new InvalidArgumentException('ALTER TABLE suporta apenas ADD de chaves PRIMARY, UNIQUE e FOREIGN.');
            }
            $definition = array_slice($action, 1);
            if (! in_array(strtoupper($definition[0] ?? ''), ['CONSTRAINT', 'PRIMARY', 'UNIQUE', 'FOREIGN'], true)) {
                throw new InvalidArgumentException('ALTER TABLE suporta apenas ADD de chaves PRIMARY, UNIQUE e FOREIGN.');
            }
            $this->constraint($table, $definition);
        }
    }

    private function uniqueIndex(array $tokens): void
    {
        if (in_array('WHERE', array_map('strtoupper', $tokens), true)) {
            throw new InvalidArgumentException('Índices UNIQUE parciais ainda não são suportados pelo editor.');
        }
        $position = 3;
        SqlDdlTokenizer::qualifiedName($tokens, $position);
        if (strtoupper($tokens[$position++] ?? '') !== 'ON') {
            throw new InvalidArgumentException('CREATE UNIQUE INDEX inválido.');
        }
        $table = strtolower(SqlDdlTokenizer::qualifiedName($tokens, $position));
        $this->constraint($table, ['UNIQUE', ...array_slice($tokens, $position)]);
    }

    private function columnIndex(string $table, string $name): int
    {
        foreach ($this->tables[$table]['columns'] ?? [] as $index => $column) {
            if (strcasecmp($column['name'], $name) === 0) {
                return $index;
            }
        }

        throw new InvalidArgumentException("Coluna inexistente no SQL: {$table}.{$name}.");
    }

    private function buildDiagrams(string $dialect): array
    {
        foreach ($this->constraints as $constraint) {
            $table = $constraint['table'];
            if (! isset($this->tables[$table])) {
                throw new InvalidArgumentException("Tabela inexistente no SQL: {$table}.");
            }
            if ($constraint['kind'] === 'FOREIGN') {
                continue;
            }
            foreach ($constraint['columns'] as $name) {
                $index = $this->columnIndex($table, $name);
                $column = &$this->tables[$table]['columns'][$index];
                if ($constraint['kind'] === 'PRIMARY') {
                    $column['key'] = 'PK';
                    $column['nullable'] = false;
                    $this->tables[$table]['primaryKey'][] = $column['name'];
                } elseif ($column['key'] !== 'PK') {
                    $column['key'] = 'UQ';
                }
                unset($column);
            }
        }
        foreach ($this->tables as &$table) {
            $table['primaryKey'] = array_values(array_unique($table['primaryKey']));
        }
        unset($table);
        $relations = $foreignKeys = [];
        foreach ($this->constraints as $constraint) {
            if ($constraint['kind'] !== 'FOREIGN') {
                continue;
            }
            $source = $constraint['table'];
            $target = $constraint['target'];
            if (! isset($this->tables[$target])) {
                $qualified = str_contains($source, '.') ? substr($source, 0, strrpos($source, '.') + 1).$target : $target;
                if (! isset($this->tables[$qualified])) {
                    throw new InvalidArgumentException("Tabela referenciada ausente do SQL: {$target}.");
                }
                $target = $qualified;
            }
            $targets = $constraint['targetColumns'] ?: $this->tables[$target]['primaryKey'];
            if (count($targets) !== count($constraint['columns']) || $targets === []) {
                throw new InvalidArgumentException('A chave estrangeira precisa ter a mesma quantidade de colunas que a chave referenciada.');
            }
            foreach ($constraint['columns'] as $name) {
                $this->columnIndex($source, $name);
            }
            $targetIndexes = array_map(fn (string $name): int => $this->columnIndex($target, $name), $targets);
            $targetNames = array_map(fn (int $index): string => $this->tables[$target]['columns'][$index]['name'], $targetIndexes);
            $primaryNames = $this->tables[$target]['primaryKey'];
            sort($targetNames);
            sort($primaryNames);
            $singleUnique = count($targets) === 1 && str_contains($this->tables[$target]['columns'][$targetIndexes[0]]['key'], 'UQ');
            if ($targetNames !== $primaryNames && ! $singleUnique) {
                throw new InvalidArgumentException('A chave estrangeira precisa referenciar uma chave PRIMARY ou UNIQUE completa.');
            }
            $relationId = 'r'.(count($relations) + 1);
            $first = $this->columnIndex($source, $constraint['columns'][0]);
            $unique = count($constraint['columns']) === 1 && ($this->tables[$source]['columns'][$first]['key'] === 'UQ'
                || $this->tables[$source]['primaryKey'] === [$constraint['columns'][0]]);
            $nullable = false;
            foreach ($constraint['columns'] as $name) {
                $nullable = $nullable || $this->tables[$source]['columns'][$this->columnIndex($source, $name)]['nullable'];
            }
            $sourceCard = $unique ? 'cf-zero-one' : 'cf-zero-many';
            $targetCard = $nullable ? 'cf-zero-one' : 'cf-one-one';
            foreach ($constraint['columns'] as $index => $name) {
                $columnIndex = $this->columnIndex($source, $name);
                $targetIndex = $this->columnIndex($target, $targets[$index]);
                $parent = $this->tables[$target]['columns'][$targetIndex];
                $column = &$this->tables[$source]['columns'][$columnIndex];
                if ($column['references'] !== null) {
                    throw new InvalidArgumentException('Uma coluna com múltiplas referências não é suportada pelo editor.');
                }
                $column['key'] = $column['key'] ? $column['key'].'/FK' : 'FK';
                $column['references'] = [
                    'table' => $this->tables[$target]['id'], 'column' => $parent['name'], 'columnId' => $parent['id'],
                    'cardinality' => $unique ? '1:1' : 'N:1', 'sourceCard' => $sourceCard, 'targetCard' => $targetCard,
                    'relationshipName' => $relationId,
                ];
                $foreignKeys[] = [
                    'id' => 'fk_'.count($foreignKeys), 'fromTable' => $this->tables[$source]['id'],
                    'fromColumn' => $column['name'], 'fromColumnId' => $column['id'],
                    'toTable' => $this->tables[$target]['id'], 'toColumn' => $parent['name'], 'toColumnId' => $parent['id'],
                    'cardinality' => $unique ? '1:1' : 'N:1', 'sourceCard' => $sourceCard, 'targetCard' => $targetCard,
                    'relationshipName' => $relationId,
                ];
                unset($column);
            }
            $relations[] = [
                'id' => $relationId, 'name' => $relationId,
                'from' => $this->tables[$source]['id'], 'fromAttr' => $this->tables[$source]['columns'][$first]['id'],
                'to' => $this->tables[$target]['id'], 'toAttr' => $this->tables[$target]['columns'][$this->columnIndex($target, $targets[0])]['id'],
                'childCard' => $sourceCard, 'parentCard' => $targetCard, 'attributes' => [],
            ];
        }
        if (count($relations) > 500) {
            throw new InvalidArgumentException('Limite: 500 relacionamentos.');
        }
        $entities = array_map(function (array $table): array {
            $attributes = array_map(function (array $column): array {
                $column['key'] = explode('/', $column['key'])[0];
                unset($column['references'], $column['source']);

                return $column;
            }, $table['columns']);
            unset($table['columns'], $table['primaryKey']);

            return $table + ['attributes' => $attributes];
        }, array_values($this->tables));
        $er = ['dialect' => $dialect, 'entities' => $entities, 'relations' => $relations];
        // Apply the existing import limits and validate every generated identifier.
        $er = ErDiagramImporter::parse(json_encode($er, JSON_THROW_ON_ERROR));

        return ['er' => $er, 'relational' => [
            'dialect' => $dialect, 'tables' => array_values($this->tables), 'foreignKeys' => $foreignKeys,
            'warnings' => array_values(array_unique($this->warnings)),
            'sourceFingerprint' => (new ErToRelationalTransformer)->fingerprint($er),
        ]];
    }
}
