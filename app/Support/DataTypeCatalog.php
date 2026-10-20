<?php

namespace App\Support;

class DataTypeCatalog
{
    /** Types with length or precision and scale. */
    public const LENGTH_TYPES = ['char', 'varchar', 'nchar', 'nvarchar', 'binary', 'varbinary'];

    public const DECIMAL_TYPES = ['decimal', 'numeric'];

    /**
     * Canonical type to native name. Missing types use FALLBACKS.
     * limits and precision define the dialect's maximum supported values.
     */
    public const DIALECTS = [
        'mysql' => [
            'label' => 'MySQL',
            'precision' => 65,
            'limits' => ['char' => 255, 'varchar' => 65535, 'binary' => 255, 'varbinary' => 65535],
            'types' => [
                'tinyint' => 'TINYINT', 'smallint' => 'SMALLINT', 'integer' => 'INT', 'bigint' => 'BIGINT',
                'decimal' => 'DECIMAL', 'numeric' => 'NUMERIC', 'float' => 'FLOAT', 'double' => 'DOUBLE',
                'boolean' => 'BOOLEAN',
                'char' => 'CHAR', 'varchar' => 'VARCHAR',
                'text' => 'TEXT', 'mediumtext' => 'MEDIUMTEXT', 'longtext' => 'LONGTEXT',
                'date' => 'DATE', 'time' => 'TIME', 'datetime' => 'DATETIME', 'timestamp' => 'TIMESTAMP',
                'binary' => 'BINARY', 'varbinary' => 'VARBINARY', 'blob' => 'BLOB',
                'uuid' => 'CHAR(36)', 'json' => 'JSON',
            ],
        ],
        'pgsql' => [
            'label' => 'PostgreSQL',
            'precision' => 1000,
            'limits' => ['char' => 10485760, 'varchar' => 10485760],
            'types' => [
                'smallint' => 'SMALLINT', 'integer' => 'INTEGER', 'bigint' => 'BIGINT',
                'decimal' => 'NUMERIC', 'float' => 'REAL', 'double' => 'DOUBLE PRECISION', 'money' => 'MONEY',
                'boolean' => 'BOOLEAN',
                'char' => 'CHAR', 'varchar' => 'VARCHAR', 'text' => 'TEXT',
                'date' => 'DATE', 'time' => 'TIME', 'datetime' => 'TIMESTAMP', 'timestamp' => 'TIMESTAMPTZ',
                'blob' => 'BYTEA', 'uuid' => 'UUID', 'json' => 'JSONB', 'xml' => 'XML',
            ],
        ],
        'oracle' => [
            'label' => 'Oracle',
            'precision' => 38,
            'limits' => ['char' => 2000, 'varchar' => 4000, 'nchar' => 1000, 'nvarchar' => 2000, 'varbinary' => 2000],
            'types' => [
                'smallint' => 'SMALLINT', 'integer' => 'INTEGER', 'bigint' => 'NUMBER(19)',
                'decimal' => 'NUMBER', 'float' => 'BINARY_FLOAT', 'double' => 'BINARY_DOUBLE',
                'boolean' => 'BOOLEAN', // 23ai+
                'char' => 'CHAR', 'varchar' => 'VARCHAR2', 'nchar' => 'NCHAR', 'nvarchar' => 'NVARCHAR2',
                'text' => 'CLOB',
                'date' => 'DATE', 'datetime' => 'TIMESTAMP', 'timestamp' => 'TIMESTAMP WITH TIME ZONE',
                'varbinary' => 'RAW', 'blob' => 'BLOB', 'uuid' => 'RAW(16)',
                'json' => 'JSON', // 21c+
                'xml' => 'XMLTYPE',
            ],
        ],
        'sqlsrv' => [
            'label' => 'SQL Server',
            'precision' => 38,
            'limits' => ['char' => 8000, 'varchar' => 8000, 'nchar' => 4000, 'nvarchar' => 4000, 'binary' => 8000, 'varbinary' => 8000],
            'types' => [
                'tinyint' => 'TINYINT', 'smallint' => 'SMALLINT', 'integer' => 'INT', 'bigint' => 'BIGINT',
                'decimal' => 'DECIMAL', 'numeric' => 'NUMERIC', 'float' => 'REAL', 'double' => 'FLOAT', 'money' => 'MONEY',
                'boolean' => 'BIT',
                'char' => 'CHAR', 'varchar' => 'VARCHAR', 'nchar' => 'NCHAR', 'nvarchar' => 'NVARCHAR',
                'text' => 'NVARCHAR(MAX)',
                'date' => 'DATE', 'time' => 'TIME', 'datetime' => 'DATETIME2', 'timestamp' => 'DATETIMEOFFSET',
                'binary' => 'BINARY', 'varbinary' => 'VARBINARY', 'blob' => 'VARBINARY(MAX)',
                'uuid' => 'UNIQUEIDENTIFIER', 'json' => 'NVARCHAR(MAX)', 'xml' => 'XML',
            ],
        ],

        'sqlite' => [
            'label' => 'SQLite',
            'precision' => 38,
            'limits' => ['char' => 255, 'varchar' => 65535],
            'types' => [
                // Inteiros (afinidade INTEGER)
                'integer' => 'INTEGER', 'bigint' => 'BIGINT',
                // Decimais (REAL e NUMERIC)
                'decimal' => 'DECIMAL', 'numeric' => 'NUMERIC', 'float' => 'REAL', 'double' => 'DOUBLE',
                'boolean' => 'BOOLEAN',
                // Texto (afinidade TEXT)
                'char' => 'CHAR', 'varchar' => 'VARCHAR', 'text' => 'TEXT',
                // Date and time (SQLite stores these as TEXT, REAL, or INTEGER)
                'date' => 'DATE', 'datetime' => 'DATETIME', 'timestamp' => 'TIMESTAMP',
                // Binary and other types
                'blob' => 'BLOB', 'json' => 'JSON',
            ],
        ],
    ];

    /** Try these types in order when the dialect lacks the requested type. */
    private const FALLBACKS = [
        'tinyint' => ['smallint', 'integer'],
        'smallint' => ['integer'],
        'bigint' => ['integer', 'decimal'],
        'numeric' => ['decimal'],
        'money' => ['decimal'],
        'float' => ['double', 'decimal'],
        'double' => ['float', 'decimal'],
        'boolean' => ['tinyint', 'smallint', 'integer'],
        'nchar' => ['char'],
        'nvarchar' => ['varchar'],
        'mediumtext' => ['text'],
        'longtext' => ['text'],
        'time' => ['datetime', 'varchar'],
        'datetime' => ['timestamp'],
        'timestamp' => ['datetime'],
        'binary' => ['varbinary', 'blob'],
        'varbinary' => ['blob'],
        'blob' => ['text'],
        'uuid' => ['char'],
        'json' => ['text'],
        'xml' => ['text'],
    ];

    public static function has(string $dialect): bool
    {
        return isset(self::DIALECTS[$dialect]);
    }

    /** Canonical types available for this dialect. */
    public static function canonicalFor(string $dialect): array
    {
        return array_keys(self::typesFor($dialect));
    }

    /** 'length', 'decimal', or null. */
    public static function kind(string $type): ?string
    {
        return match (true) {
            in_array($type, self::LENGTH_TYPES, true) => 'length',
            in_array($type, self::DECIMAL_TYPES, true) => 'decimal',
            default => null,
        };
    }

    /** Resolve a type to itself or the closest available equivalent. */
    public static function resolve(string $dialect, string $type): string
    {
        $available = self::canonicalFor($dialect);

        foreach (array_merge([$type], self::FALLBACKS[$type] ?? []) as $candidate) {
            if (in_array($candidate, $available, true)) {
                return $candidate;
            }
        }

        return 'varchar';
    }

    /** Fit column length, precision, and scale to the dialect's type limits. */
    public static function normalize(string $dialect, array $column): array
    {
        $type = $column['type'] ?? 'varchar';
        $kind = self::kind($type);
        $config = self::DIALECTS[$dialect];

        if ($kind === 'length') {
            $default = in_array($type, ['char', 'nchar', 'binary'], true) ? 1 : 255;
            $max = $config['limits'][$type] ?? 255;
            $column['length'] = max(1, min($max, (int) ($column['length'] ?? $default)));
        } else {
            $column['length'] = null;
        }

        if ($kind === 'decimal') {
            $precision = max(1, min($config['precision'], (int) ($column['precision'] ?? 10)));
            $column['precision'] = $precision;
            $column['scale'] = max(0, min($precision, 30, (int) ($column['scale'] ?? 2)));
        } else {
            $column['precision'] = null;
            $column['scale'] = null;
        }
        if ($type === 'custom') {
            // Custom types are free text for extensions and unknown types.
            $column['customType'] = trim((string) ($column['customType'] ?? ''));
        } else {
            $column['customType'] = null;
        }

        $column['enumValues'] = $type === 'enum'
            ? array_values(array_filter(array_map(
                static fn (mixed $value): string => trim((string) $value),
                is_array($column['enumValues'] ?? null) ? $column['enumValues'] : [],
            ), static fn (string $value): bool => $value !== ''))
            : [];

        return $column;
    }

    public static function forJs(): array
    {
        $kinds = [];
        foreach (self::LENGTH_TYPES as $t) {
            $kinds[$t] = 'length';
        }
        foreach (self::DECIMAL_TYPES as $t) {
            $kinds[$t] = 'decimal';
        }

        return collect(self::DIALECTS)->map(fn ($d, $key) => [
            'label' => $d['label'],
            'types' => self::typesFor($key),
            'limits' => $d['limits'],
            'precision' => $d['precision'],
            'kinds' => $kinds,
        ])->all();
    }

    private static function typesFor(string $dialect): array
    {
        return (self::DIALECTS[$dialect]['types'] ?? []) + [
            'enum' => 'Enum',
            'custom' => 'Personalizado',
        ];
    }
}
