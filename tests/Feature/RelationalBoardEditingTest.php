<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Makermind\Erd4dev\Livewire\RelationalBoard;
use Makermind\Erd4dev\Models\Diagram;
use Makermind\Erd4dev\Services\RelationalSqlGenerator;
use Makermind\Erd4dev\Support\SqlDiagramImporter;
use Tests\TestCase;

class RelationalBoardEditingTest extends TestCase
{
    use RefreshDatabase;

    private function diagram(): Diagram
    {
        return Diagram::create(['name' => 'Imported', 'type' => Diagram::TYPE_RELATIONAL, 'data' => (new SqlDiagramImporter)->parse('CREATE TABLE authors (id INT PRIMARY KEY); CREATE TABLE books (id INT PRIMARY KEY, author_id INT, CONSTRAINT fk_author FOREIGN KEY(author_id) REFERENCES authors(id));', 'sqlite')['relational'],
        ]);
    }

    public function test_inline_keys_update_cardinality_without_allowing_broken_target_keys(): void
    {
        $diagram = $this->diagram();
        $authors = $diagram->data['tables'][0];
        $books = $diagram->data['tables'][1];
        $board = Livewire::test(RelationalBoard::class, ['diagram' => $diagram])
            ->assertSee('Relationships')->assertSee('New table')
            ->call('setColumnKey', $books['id'], $books['columns'][1]['id'], 'UQ')
            ->call('toggleColumnNullable', $books['id'], $books['columns'][1]['id']);
        $fk = $diagram->fresh()->data['foreignKeys'][0];
        $this->assertSame('1:1', $fk['cardinality']);
        $this->assertSame('cf-one-one', $fk['targetCard']);
        $this->assertSame('UQ/FK', $diagram->fresh()->data['tables'][1]['columns'][1]['key']);
        $board->call('setColumnKey', $authors['id'], $authors['columns'][0]['id'], '')
            ->assertDispatched('relational-edit-rejected');
        $this->assertSame(['id'], $diagram->fresh()->data['tables'][0]['primaryKey']);
        $board->call('setColumnKey', $books['id'], $books['columns'][1]['id'], '');
        $this->assertSame('N:1', $diagram->fresh()->data['foreignKeys'][0]['cardinality']);
    }

    public function test_table_creator_and_column_actions_preserve_stable_ids(): void
    {
        $diagram = $this->diagram();
        $board = Livewire::test(RelationalBoard::class, ['diagram' => $diagram])
            ->call('openTableCreator')->set('newTableName', 'catalog')
            ->call('createBoardTable')->assertSet('showTableCreator', false);
        $table = collect($board->get('tables'))->firstWhere('name', 'catalog');
        $board->call('renameTable', $table['id'], 'catalog_items')
            ->call('addColumn', $table['id'], 'code');
        $table = collect($board->get('tables'))->firstWhere('id', $table['id']);
        $board->call('renameColumn', $table['id'], $table['columns'][1]['id'], 'reference')
            ->call('setColumnKey', $table['id'], $table['columns'][1]['id'], 'UQ');
        $table = collect($diagram->fresh()->data['tables'])->firstWhere('id', $table['id']);
        $this->assertSame('catalog_items', $table['name']);
        $this->assertSame([['reference']], $table['uniqueKeys']);
    }

    public function test_er_derived_boards_reject_structural_inline_actions(): void
    {
        $source = Diagram::create(['name' => 'ER', 'type' => Diagram::TYPE_ENTITY_RELATIONSHIP, 'data' => []]);
        $diagram = $this->diagram();
        $diagram->update(['source_diagram_id' => $source->id]);
        Livewire::test(RelationalBoard::class, ['diagram' => $diagram])->assertDontSee('Relationships')
            ->call('openTableCreator')->assertForbidden();
        Livewire::test(RelationalBoard::class, ['diagram' => $diagram])
            ->call('setColumnKey', 'e1', 'e1.c1', '')->assertForbidden();
    }

    public function test_enum_values_containing_commas_are_not_split_by_inline_edits(): void
    {
        $diagram = Diagram::create(['name' => 'Enum', 'type' => Diagram::TYPE_RELATIONAL, 'data' => (new SqlDiagramImporter)->parse("CREATE TABLE states (id INT PRIMARY KEY, label ENUM('a,b','c',' padded ','','0'));", 'mysql')['relational'],
        ]);
        $column = $diagram->data['tables'][0]['columns'][1];
        Livewire::test(RelationalBoard::class, ['diagram' => $diagram])
            ->call('updateColumnEnumValues', 'e1', $column['id'], ['a,b', 'c', ' padded ', '', '0', 'd']);
        $this->assertSame(['a,b', 'c', ' padded ', '', '0', 'd'], $diagram->fresh()->data['tables'][0]['columns'][1]['enumValues']);
        $sql = (new RelationalSqlGenerator)->generate($diagram->fresh()->data);
        $roundTrip = (new SqlDiagramImporter)->parse($sql, 'mysql')['relational'];
        $this->assertSame(['a,b', 'c', ' padded ', '', '0', 'd'], $roundTrip['tables'][0]['columns'][1]['enumValues']);
    }

    public function test_sql_names_and_redundant_foreign_keys_do_not_change_the_schema(): void
    {
        $data = (new SqlDiagramImporter)->parse('CREATE TABLE authors (id INT8 PRIMARY KEY); CREATE TABLE books (id INT PRIMARY KEY, author_id INT8, code INT2 CONSTRAINT "UNIQUE" CHECK(code > 0), CONSTRAINT fk_author FOREIGN KEY(author_id) REFERENCES authors(id)); ALTER TABLE books ADD CONSTRAINT fk_author FOREIGN KEY(author_id) REFERENCES authors(id);', 'pgsql')['relational'];
        $this->assertCount(1, $data['foreignKeys']);
        $this->assertSame('fk_author', $data['foreignKeys'][0]['relationshipName']);
        $this->assertSame('', $data['tables'][1]['columns'][2]['key']);
        $this->assertSame('smallint', $data['tables'][1]['columns'][2]['type']);
        $this->assertSame('bigint', $data['tables'][0]['columns'][0]['type']);
    }

    public function test_public_examples_do_not_include_features_missing_from_the_er_editor(): void
    {
        foreach (glob(public_path('examples/*.json')) as $file) {
            $data = json_decode(file_get_contents($file), true);
            $this->assertSame($data, json_decode(file_get_contents(base_path('packages/laravel-erd/resources/examples/'.basename($file))), true));
            foreach ($data['entities'] as $entity) {
                $this->assertArrayNotHasKey('kind', $entity);
                foreach ($entity['attributes'] as $attribute) {
                    $this->assertArrayNotHasKey('components', $attribute);
                    $this->assertArrayNotHasKey('multivalued', $attribute);
                    $this->assertNotSame('composite', $attribute['type']);
                }
            }
            foreach ($data['relations'] as $relation) {
                $this->assertEmpty($relation['participants'] ?? []);
            }
        }
    }
}
