<?php

namespace App\Support;

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

    /** @return array{relational:array} */
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
            } elseif (preg_match('/^CREATE UNIQUE (?:(?:CLUSTERED|NONCLUSTERED) )?(?:INDEX|KEY)\b/', $head)) {
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

        return $this->buildRelationalDiagram($dialect);
    }

    private function validateName(string $name): void
    {
        if (trim($name) === '' || mb_strlen($name) > 80) {
            throw new InvalidArgumentException('Tabelas e colunas precisam ter nome de até 80 caracteres.');
        }
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
        $this->validateName($name);
        $key = strtolower($name);
        if (isset($this->tables[$key])) {
            throw new InvalidArgumentException("Tabela duplicada: {$name}.");
        }
        $definitions = SqlDdlTokenizer::split(SqlDdlTokenizer::group($tokens, $position), ',');
        $index = count($this->tables);
        $this->tables[$key] = [
            'id' => 'e'.($index + 1), 'name' => $name, 'kind' => 'strong',
            'x' => 60 + ($index % 3) * 460, 'y' => 60 + intdiv($index, 3) * 420,
            'columns' => [], 'primaryKey' => [], 'uniqueKeys' => [],
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
        $this->validateName($name);
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
        $primary = preg_match('/\bPRIMARY\s+KEY\b/', $syntax) === 1;
        // UNIQUE pode aparecer inline como UNIQUE, UNIQUE KEY, UNIQUE INDEX
        // ou com nome: CONSTRAINT uq_nome UNIQUE. O nome opcional não altera
        // a semântica; basta reconhecer a palavra-chave fora dos literais.
        $unique = ! $primary && preg_match('/\bUNIQUE\b/', $syntax) === 1;
        $column['nullable'] = ! $primary && ! preg_match('/\bNOT\s+NULL\b/', $syntax);
        $column['key'] = $primary ? 'PK' : ($unique ? 'UQ' : '');
        if ($primary) {
            $this->constraints[] = ['table' => $table, 'kind' => 'PRIMARY', 'columns' => [$name]];
        } elseif ($unique) {
            // Registra também a restrição inline para que todas as formas de
            // UNIQUE sigam o mesmo caminho de validação do restante do importador.
            $this->constraints[] = ['table' => $table, 'kind' => 'UNIQUE', 'columns' => [$name]];
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
        $position = 2;
        if (in_array(strtoupper($tokens[$position] ?? ''), ['CLUSTERED', 'NONCLUSTERED'], true)) {
            $position++;
        }
        $position++; // INDEX or KEY
        if (strtoupper($tokens[$position] ?? '') === 'CONCURRENTLY') {
            $position++;
        }
        if (strtoupper($tokens[$position] ?? '') === 'IF') {
            $position += 3; // IF NOT EXISTS
        }
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

    private function isUnique(string $table, array $columns): bool
    {
        $normalize = static function (array $names): array {
            $names = array_map('strtolower', $names);
            sort($names);

            return $names;
        };
        foreach ([$this->tables[$table]['primaryKey'], ...$this->tables[$table]['uniqueKeys']] as $key) {
            if ($key !== [] && $normalize($key) === $normalize($columns)) {
                return true;
            }
        }

        return false;
    }

    private function buildRelationalDiagram(string $dialect): array
    {
        foreach ($this->constraints as $constraint) {
            $table = $constraint['table'];
            if (! isset($this->tables[$table])) {
                throw new InvalidArgumentException("Tabela inexistente no SQL: {$table}.");
            }
            if ($constraint['kind'] === 'FOREIGN') {
                continue;
            }
            if ($constraint['kind'] === 'UNIQUE') {
                $names = array_map(fn (string $name): string => $this->tables[$table]['columns'][$this->columnIndex($table, $name)]['name'], $constraint['columns']);
                if (! in_array($names, $this->tables[$table]['uniqueKeys'], true)) {
                    $this->tables[$table]['uniqueKeys'][] = $names;
                }
            }
            foreach ($constraint['columns'] as $name) {
                $index = $this->columnIndex($table, $name);
                $column = &$this->tables[$table]['columns'][$index];
                if ($constraint['kind'] === 'PRIMARY') {
                    $column['key'] = 'PK';
                    $column['nullable'] = false;
                    $this->tables[$table]['primaryKey'][] = $column['name'];
                } elseif (count($constraint['columns']) === 1 && $column['key'] !== 'PK') {
                    $column['key'] = 'UQ';
                }
                unset($column);
            }
        }
        foreach ($this->tables as &$table) {
            $table['primaryKey'] = array_values(array_unique($table['primaryKey']));
        }
        unset($table);
        $foreignKeys = [];
        $relationshipCount = 0;
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
            foreach ($targets as $name) {
                $this->columnIndex($target, $name);
            }
            if (! $this->isUnique($target, $targets)) {
                throw new InvalidArgumentException('A chave estrangeira precisa referenciar uma chave PRIMARY ou UNIQUE completa.');
            }
            $relationId = 'r'.(++$relationshipCount);
            $unique = $this->isUnique($source, $constraint['columns']);
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
        }
        if ($relationshipCount > 500) {
            throw new InvalidArgumentException('Limite: 500 relacionamentos.');
        }

        return ['relational' => [
            'dialect' => $dialect, 'tables' => array_values($this->tables), 'foreignKeys' => $foreignKeys,
            'warnings' => array_values(array_unique($this->warnings)),
        ]];
    }
}
