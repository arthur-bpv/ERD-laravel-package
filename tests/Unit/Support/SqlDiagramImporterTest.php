<?php

namespace Tests\Unit\Support;

use App\Services\RelationalSqlGenerator;
use App\Support\SqlDiagramImporter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SqlDiagramImporterTest extends TestCase
{
    public static function dialects(): array
    {
        return [
            ['sqlite', 'PRAGMA foreign_keys=OFF; CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT);'],
            ['mysql', 'CREATE TABLE `users` (`id` BIGINT AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(120)) ENGINE=InnoDB;'],
            ['pgsql', 'CREATE TABLE users (id SERIAL PRIMARY KEY, settings JSONB);'],
            ['oracle', 'CREATE TABLE users (id NUMBER(19) PRIMARY KEY, name VARCHAR2(120));'],
            ['sqlsrv', "CREATE TABLE [dbo].[users] ([id] INT IDENTITY(1,1) PRIMARY KEY, [name] NVARCHAR(120))\nGO\n"],
        ];
    }

    #[DataProvider('dialects')]
    public function test_it_detects_supported_databases_and_imports_native_types(string $dialect, string $sql): void
    {
        $this->assertSame($dialect, SqlDiagramImporter::detectDialect($sql));
        $data = (new SqlDiagramImporter)->parse($sql);
        $this->assertArrayNotHasKey('er', $data);
        $this->assertArrayNotHasKey('sourceFingerprint', $data['relational']);
        $this->assertSame($dialect, $data['relational']['dialect']);
        $this->assertCount(1, $data['relational']['tables']);
        $this->assertSame('PK', $data['relational']['tables'][0]['columns'][0]['key']);
        $this->assertFalse($data['relational']['tables'][0]['columns'][0]['nullable']);
        $this->assertNotEmpty((new RelationalSqlGenerator)->generate($data['relational']));
    }

    public function test_it_preserves_composite_keys_alter_constraints_and_quoted_names(): void
    {
        $data = (new SqlDiagramImporter)->parse(<<<'SQL'
            -- Código SQL para: PostgreSQL
            CREATE TABLE "parents" ("tenant" INT, "id" INT, "label" VARCHAR(80), PRIMARY KEY (tenant, id));
            CREATE TABLE children (id INT PRIMARY KEY, tenant_id INT NOT NULL, parent_id INT, note TEXT DEFAULT 'a,b; -- text');
            ALTER TABLE children ADD CONSTRAINT children_parent FOREIGN KEY (tenant_id, parent_id) REFERENCES parents (tenant, id);
            CREATE UNIQUE INDEX parent_label ON parents (label);
            SQL);

        $tables = $data['relational']['tables'];
        $this->assertSame(['tenant', 'id'], $tables[0]['primaryKey']);
        $this->assertSame(80, $tables[0]['columns'][2]['length']);
        $this->assertSame('UQ', $tables[0]['columns'][2]['key']);
        $this->assertCount(2, $data['relational']['foreignKeys']);
        $this->assertSame('cf-zero-one', $data['relational']['foreignKeys'][0]['targetCard']);
        $this->assertStringContainsString('FOREIGN KEY ("tenant_id", "parent_id") REFERENCES "parents" ("tenant", "id")', (new RelationalSqlGenerator)->generate($data['relational']));
    }

    public function test_it_resolves_forward_inline_and_recursive_references(): void
    {
        $data = (new SqlDiagramImporter)->parse(<<<'SQL'
            CREATE TABLE children (id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL REFERENCES parents, previous_id INTEGER REFERENCES children(id));
            CREATE TABLE parents (id INTEGER PRIMARY KEY, email TEXT UNIQUE);
            SQL, 'sqlite');

        $this->assertCount(2, $data['relational']['foreignKeys']);
        $this->assertSame('e2', $data['relational']['foreignKeys'][0]['toTable']);
        $this->assertSame('cf-one-one', $data['relational']['foreignKeys'][0]['targetCard']);
        $this->assertSame('e1', $data['relational']['foreignKeys'][1]['toTable']);
    }

    public function test_generic_sql_needs_an_explicit_database_and_literals_do_not_select_one(): void
    {
        $sql = "CREATE TABLE things (id INTEGER PRIMARY KEY, note TEXT DEFAULT 'AUTO_INCREMENT VARCHAR2 PRAGMA');";
        $this->assertNull(SqlDiagramImporter::detectDialect($sql));
        $this->assertSame('sqlite', (new SqlDiagramImporter)->parse($sql, 'sqlite')['relational']['dialect']);
        $this->expectException(InvalidArgumentException::class);
        (new SqlDiagramImporter)->parse($sql);
    }

    public function test_strings_comments_and_dollar_quotes_do_not_create_fake_tables(): void
    {
        $data = (new SqlDiagramImporter)->parse(<<<'SQL'
            /* CREATE TABLE fake (id INT); */
            CREATE TABLE "a""b" ("c,d" TEXT DEFAULT 'x;
            GO
            y', id INTEGER PRIMARY KEY);
            CREATE FUNCTION example() RETURNS void AS $$ BEGIN CREATE TABLE fake2 (id INT); END; $$ LANGUAGE plpgsql;
            INSERT INTO "a""b" VALUES ('CREATE TABLE fake3 (id INT);', 1);
            SQL, 'pgsql');

        $this->assertCount(1, $data['relational']['tables']);
        $this->assertSame('a"b', $data['relational']['tables'][0]['name']);
        $this->assertSame('c,d', $data['relational']['tables'][0]['columns'][0]['name']);
        $this->assertNotEmpty($data['relational']['warnings']);
    }

    public static function compositeUniqueSyntaxes(): array
    {
        return [
            ['UNIQUE (trip_id, slug)', ''],
            ['CONSTRAINT roles_trip_slug UNIQUE (trip_id, slug)', ''],
            ['UNIQUE KEY roles_trip_slug (trip_id, slug)', ''],
            ['UNIQUE INDEX roles_trip_slug (trip_id, slug)', ''],
            ['', 'ALTER TABLE roles ADD CONSTRAINT roles_trip_slug UNIQUE (trip_id, slug);'],
            ['', 'CREATE UNIQUE INDEX IF NOT EXISTS roles_trip_slug ON roles (trip_id ASC, slug DESC);'],
            ['', 'CREATE UNIQUE INDEX CONCURRENTLY roles_trip_slug ON roles USING btree (trip_id, slug);'],
            ['', 'CREATE UNIQUE NONCLUSTERED INDEX roles_trip_slug ON roles (trip_id, slug);'],
        ];
    }

    #[DataProvider('compositeUniqueSyntaxes')]
    public function test_it_preserves_composite_uniqueness_and_references_across_syntaxes(string $constraint, string $extra): void
    {
        $sql = 'CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, trip_id INTEGER NOT NULL, slug VARCHAR(255) NOT NULL'
            .($constraint ? ', '.$constraint : '').');'.$extra
            .' CREATE TABLE assignments (trip_id INT, slug VARCHAR(255), UNIQUE(trip_id, slug), FOREIGN KEY(trip_id, slug) REFERENCES roles(trip_id, slug));';
        $data = (new SqlDiagramImporter)->parse($sql, 'sqlite');
        $table = $data['relational']['tables'][0];
        $this->assertSame([['trip_id', 'slug']], $table['uniqueKeys']);
        $this->assertSame('', $table['columns'][1]['key']);
        $this->assertSame('', $table['columns'][2]['key']);
        $this->assertSame('cf-zero-one', $data['relational']['foreignKeys'][0]['sourceCard']);
        $export = (new RelationalSqlGenerator)->generate($data['relational']);
        $this->assertStringContainsString('UNIQUE ("trip_id", "slug")', $export);
        $this->assertStringNotContainsString('UNIQUE ("trip_id")', $export);
        $this->assertStringContainsString('FOREIGN KEY ("trip_id", "slug") REFERENCES "roles" ("trip_id", "slug")', $export);
        $again = (new SqlDiagramImporter)->parse($export);
        $this->assertSame($table['uniqueKeys'], $again['relational']['tables'][0]['uniqueKeys']);
    }

    public static function invalidSchemas(): array
    {
        return [
            ['CREATE TABLE t (id INT,', 'Parênteses'],
            ['CREATE TABLE t (id INT, id INT);', 'duplicada'],
            ['CREATE TABLE t (id INT); CREATE TABLE t (id INT);', 'duplicada'],
            ['CREATE TABLE t (id INT REFERENCES missing(id));', 'ausente'],
            ['CREATE TABLE t (id INT, FOREIGN KEY (missing) REFERENCES t(id));', 'inexistente'],
            ['CREATE TABLE t (id INT); ALTER TABLE t DROP COLUMN id;', 'ALTER TABLE'],
            ['CREATE TABLE t (id INT, FOREIGN KEY(id) REFERENCES t(id, other));', 'quantidade'],
            ["CREATE TABLE t (id TEXT DEFAULT 'broken);", 'fechado'],
            ['DROP TABLE diagrams;', 'CREATE TABLE'],
            ['CREATE TABLE t (id INT); DELIMITER ;; CREATE PROCEDURE p() BEGIN SELECT 1; CREATE TABLE fake (id INT); END;', 'rotinas'],
            ['CREATE TABLE t (id INT); CREATE TRIGGER p AFTER INSERT ON t BEGIN SELECT 1; CREATE TABLE fake (id INT); END;', 'rotinas'],
            ['CREATE TABLE t (id INT); CREATE UNIQUE INDEX partial ON t(id) WHERE id > 0;', 'parciais'],
            ['CREATE TABLE t (id INT REFERENCES t(id));', 'PRIMARY ou UNIQUE'],
            ['CREATE TABLE t (a INT, b INT, UNIQUE(a,b)); CREATE TABLE c (a INT REFERENCES t(a));', 'PRIMARY ou UNIQUE'],
        ];
    }

    #[DataProvider('invalidSchemas')]
    public function test_it_rejects_invalid_or_unrepresentable_schemas(string $sql, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        (new SqlDiagramImporter)->parse($sql, 'sqlite');
    }

    public function test_the_server_enforces_the_file_size_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('2 MB');
        (new SqlDiagramImporter)->parse(str_repeat(' ', 2_000_001), 'sqlite');
    }

    public function test_exported_sql_identifies_each_database_even_without_vendor_specific_types(): void
    {
        foreach (['sqlite', 'mysql', 'pgsql', 'oracle', 'sqlsrv'] as $dialect) {
            $sql = (new RelationalSqlGenerator)->generate([
                'dialect' => $dialect,
                'tables' => [['id' => 't', 'name' => 'things', 'primaryKey' => [], 'columns' => [['name' => 'id', 'type' => 'integer']]]],
            ]);
            $this->assertSame($dialect, SqlDiagramImporter::detectDialect($sql));
        }
    }
}
