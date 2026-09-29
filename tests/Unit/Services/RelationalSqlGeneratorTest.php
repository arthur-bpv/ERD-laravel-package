<?php

namespace Tests\Unit\Services;

use App\Services\RelationalSqlGenerator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class RelationalSqlGeneratorTest extends TestCase
{
    public function test_it_exports_edited_names_types_keys_and_nullability(): void
    {
        $sql = (new RelationalSqlGenerator)->generate([
            'dialect' => 'pgsql',
            'tables' => [[
                'id' => 'users', 'name' => 'User Accounts', 'primaryKey' => ['user_id'],
                'columns' => [
                    ['name' => 'user_id', 'type' => 'uuid', 'key' => 'PK', 'nullable' => false],
                    ['name' => 'email', 'type' => 'varchar', 'length' => 120, 'key' => 'UQ', 'nullable' => false],
                    ['name' => 'balance', 'type' => 'decimal', 'precision' => 12, 'scale' => 4, 'key' => '', 'nullable' => true],
                ],
            ]],
            'foreignKeys' => [],
        ]);

        $this->assertStringContainsString('CREATE TABLE "User Accounts"', $sql);
        $this->assertStringContainsString('"user_id" UUID NOT NULL', $sql);
        $this->assertStringContainsString('"email" VARCHAR(120) NOT NULL', $sql);
        $this->assertStringContainsString('"balance" NUMERIC(12, 4) NULL', $sql);
        $this->assertStringContainsString('UNIQUE ("email")', $sql);
        $this->assertStringContainsString('PRIMARY KEY ("user_id")', $sql);
    }

    public function test_it_creates_tables_before_recursive_and_composite_foreign_keys(): void
    {
        $sql = (new RelationalSqlGenerator)->generate([
            'dialect' => 'mysql',
            'tables' => [
                ['id' => 'parent', 'name' => 'parents', 'primaryKey' => ['tenant', 'id'], 'columns' => [
                    ['name' => 'tenant', 'type' => 'integer', 'key' => 'PK', 'nullable' => false],
                    ['name' => 'id', 'type' => 'integer', 'key' => 'PK', 'nullable' => false],
                ]],
                ['id' => 'child', 'name' => 'children', 'primaryKey' => ['id'], 'columns' => [
                    ['name' => 'id', 'type' => 'integer', 'key' => 'PK', 'nullable' => false],
                    ['name' => 'tenant_id', 'type' => 'integer', 'key' => 'FK', 'nullable' => false],
                    ['name' => 'parent_id', 'type' => 'integer', 'key' => 'FK', 'nullable' => false],
                    ['name' => 'previous_id', 'type' => 'integer', 'key' => 'FK', 'nullable' => true],
                ]],
            ],
            'foreignKeys' => [
                ['fromTable' => 'child', 'fromColumn' => 'parent_id', 'toTable' => 'parent', 'toColumn' => 'id'],
                ['fromTable' => 'child', 'fromColumn' => 'tenant_id', 'toTable' => 'parent', 'toColumn' => 'tenant'],
                ['fromTable' => 'child', 'fromColumn' => 'previous_id', 'toTable' => 'child', 'toColumn' => 'id'],
            ],
        ]);

        $this->assertSame(2, substr_count($sql, 'ADD FOREIGN KEY'));
        $this->assertStringContainsString('FOREIGN KEY (`tenant_id`, `parent_id`) REFERENCES `parents` (`tenant`, `id`)', $sql);
        $this->assertStringContainsString('FOREIGN KEY (`previous_id`) REFERENCES `children` (`id`)', $sql);
        $this->assertLessThan(strpos($sql, 'ALTER TABLE'), strpos($sql, 'CREATE TABLE `children`'));
    }

    public function test_it_uses_native_types_and_quoted_identifiers_for_each_dialect(): void
    {
        $generator = new RelationalSqlGenerator;
        foreach ([
            'mysql' => ['a`b', '`a``b`', 'BOOLEAN'],
            'pgsql' => ['a"b', '"a""b"', 'BOOLEAN'],
            'oracle' => ['a"b', '"a""b"', 'BOOLEAN'],
            'sqlsrv' => ['a]b', '[a]]b]', 'BIT'],
        ] as $dialect => [$name, $quoted, $type]) {
            $sql = $generator->generate([
                'dialect' => $dialect,
                'tables' => [['id' => 't', 'name' => $name, 'primaryKey' => [], 'columns' => [
                    ['name' => 'active', 'type' => 'boolean', 'key' => '', 'nullable' => false],
                ]]],
            ]);
            $this->assertStringContainsString($quoted, $sql);
            $this->assertStringContainsString($type.' NOT NULL', $sql);
        }
    }

    public function test_it_parses_legacy_type_sizes_from_the_er_model(): void
    {
        $sql = (new RelationalSqlGenerator)->generate([
            'tables' => [['id' => 't', 'name' => 'orders', 'primaryKey' => [], 'columns' => [
                ['name' => 'count', 'type' => 'int'],
                ['name' => 'price', 'type' => 'decimal(10,2)'],
                ['name' => 'code', 'type' => 'varchar(40)'],
            ]]],
        ]);

        $this->assertStringContainsString('`count` INT NOT NULL', $sql);
        $this->assertStringContainsString('`price` DECIMAL(10, 2) NOT NULL', $sql);
        $this->assertStringContainsString('`code` VARCHAR(40) NOT NULL', $sql);
    }

    public function test_it_rejects_a_foreign_key_to_an_unindexed_column(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('precisa ser PK ou UQ');

        (new RelationalSqlGenerator)->generate([
            'tables' => [
                ['id' => 'a', 'name' => 'a', 'primaryKey' => [], 'columns' => [['name' => 'ref', 'type' => 'integer']]],
                ['id' => 'b', 'name' => 'b', 'primaryKey' => [], 'columns' => [['name' => 'ordinary', 'type' => 'integer']]],
            ],
            'foreignKeys' => [['fromTable' => 'a', 'fromColumn' => 'ref', 'toTable' => 'b', 'toColumn' => 'ordinary']],
        ]);
    }
}
