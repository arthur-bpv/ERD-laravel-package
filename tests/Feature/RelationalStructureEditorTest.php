<?php

namespace Tests\Feature;

use App\Livewire\RelationalBoard;
use App\Models\Diagram;
use App\Services\RelationalSqlGenerator;
use App\Support\SqlDiagramImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RelationalStructureEditorTest extends TestCase
{
    use RefreshDatabase;

    private function diagram(): Diagram
    {
        return Diagram::create([
            'name' => 'Importado', 'type' => Diagram::TYPE_RELATIONAL,
            'data' => (new SqlDiagramImporter)->parse('CREATE TABLE parents (tenant INT, id INT, PRIMARY KEY(tenant,id)); CREATE TABLE children (id INT PRIMARY KEY, tenant INT, parent_id INT);', 'sqlite')['relational'],
        ]);
    }

    public function test_standalone_tables_and_columns_can_be_added_renamed_removed_and_reopened(): void
    {
        $diagram = $this->diagram();
        $component = Livewire::test(RelationalBoard::class, ['diagram' => $diagram])
            ->assertSee('Editar estrutura')->call('openStructureEditor')->assertSet('showStructureEditor', true)
            ->call('addTable', 'books');
        $table = collect($component->get('tables'))->firstWhere('name', 'books');
        $component->call('renameTable', $table['id'], 'library')
            ->call('addColumn', $table['id'], 'isbn', 'varchar', 'UQ');
        $column = collect($component->get('tables'))->firstWhere('id', $table['id'])['columns'][1];
        $component->call('renameColumn', $table['id'], $column['id'], 'code')->call('save');
        $table = collect($diagram->fresh()->data['tables'])->firstWhere('id', $table['id']);
        $this->assertSame('library', $table['name']);
        $this->assertSame([['code']], $table['uniqueKeys']);
        $this->assertSame($column['id'], $table['columns'][1]['id']);
        Livewire::test(RelationalBoard::class, ['diagram' => $diagram->fresh()])
            ->assertSee('library')->assertSee('code')
            ->call('deleteColumn', $table['id'], $column['id'])
            ->call('deleteSelection', array_column($diagram->fresh()->data['tables'], 'id'))
            ->assertSet('tables', [])->assertSee('Nenhuma tabela no modelo')
            ->call('addTable', 'restart')->assertSee('restart');
        $this->assertCount(1, $diagram->fresh()->data['tables']);
        $this->assertDatabaseCount('diagrams', 1);
    }

    public function test_composite_relationships_keep_references_consistent_when_renamed_and_deleted(): void
    {
        $diagram = $this->diagram();
        [$parent, $child] = $diagram->data['tables'];
        $component = Livewire::test(RelationalBoard::class, ['diagram' => $diagram])
            ->call('addRelationship', 'parent', $child['id'], array_column(array_slice($child['columns'], 1), 'id'), $parent['id'], array_column($parent['columns'], 'id'));
        $keys = $component->get('foreignKeys');
        $this->assertCount(2, $keys);
        $component->call('renameRelationship', $keys[0]['id'], 'belongs_to')
            ->call('renameColumn', $parent['id'], $parent['columns'][1]['id'], 'parent_key')
            ->call('renameColumn', $child['id'], $child['columns'][2]['id'], 'parent_ref')
            ->call('renameTable', $parent['id'], 'accounts');
        $data = $diagram->fresh()->data;
        $this->assertSame(['belongs_to', 'belongs_to'], array_column($data['foreignKeys'], 'relationshipName'));
        $this->assertSame('parent_key', $data['foreignKeys'][1]['toColumn']);
        $this->assertSame('parent_ref', $data['foreignKeys'][1]['fromColumn']);
        $this->assertSame('parent_key', $data['tables'][1]['columns'][2]['references']['column']);
        $this->assertSame('belongs_to', $data['tables'][1]['columns'][2]['references']['relationshipName']);
        $sql = (new RelationalSqlGenerator)->generate($data);
        $this->assertStringContainsString('FOREIGN KEY ("tenant", "parent_ref") REFERENCES "accounts" ("tenant", "parent_key")', $sql);
        $this->assertCount(2, (new SqlDiagramImporter)->parse($sql)['relational']['foreignKeys']);
        $component->call('deleteRelationship', $keys[1]['id'])->assertSet('foreignKeys', []);
        foreach (array_slice($diagram->fresh()->data['tables'][1]['columns'], 1) as $column) {
            $this->assertSame('', $column['key']);
            $this->assertNull($column['references']);
        }
    }

    public function test_invalid_names_and_invalid_foreign_keys_do_not_change_saved_data(): void
    {
        $diagram = $this->diagram();
        [$parent, $child] = $diagram->data['tables'];
        $before = $diagram->data;
        $component = Livewire::test(RelationalBoard::class, ['diagram' => $diagram]);
        foreach ([
            ['addTable', 'PARENTS'], ['renameTable', $child['id'], '  '],
            ['addColumn', $child['id'], 'ID'], ['renameColumn', $child['id'], $child['columns'][1]['id'], 'id'],
            ['addRelationship', 'invalid', $child['id'], [$child['columns'][1]['id']], $parent['id'], [$parent['columns'][0]['id']]],
            ['addRelationship', 'invalid', $child['id'], ['missing'], $parent['id'], ['missing']],
        ] as $call) {
            $component->call(...$call)->assertDispatched('relational-edit-rejected');
            $this->assertSame($before, $diagram->fresh()->data);
        }
    }

    public function test_recursive_relationships_and_removal_of_a_referenced_table(): void
    {
        $diagram = $this->diagram();
        $child = $diagram->data['tables'][1];
        $component = Livewire::test(RelationalBoard::class, ['diagram' => $diagram])
            ->call('addRelationship', 'previous', $child['id'], [$child['columns'][2]['id']], $child['id'], [$child['columns'][0]['id']]);
        $key = $component->get('foreignKeys')[0];
        $component->call('renameRelationship', $key['id'], 'predecessor');
        $this->assertStringContainsString('predecessor', $component->instance()->buildEdges()[0]['label']);
        $component->call('deleteSelection', [$child['id']])->assertSet('foreignKeys', []);
    }

    public static function structuralActions(): array
    {
        return array_map(fn ($call) => [$call], [
            ['openStructureEditor'], ['addTable', 'blocked'], ['renameTable', 'e1', 'blocked'],
            ['addColumn', 'e1', 'blocked'], ['renameColumn', 'e1', 'a1', 'blocked'],
            ['deleteColumn', 'e1', 'a1'], ['deleteSelection', ['e1']],
            ['addRelationship', 'blocked', 'e1', ['a1'], 'e2', ['a2']],
            ['renameRelationship', 'fk', 'blocked'], ['deleteRelationship', 'fk'],
        ]);
    }

    #[DataProvider('structuralActions')]
    public function test_linked_relational_boards_reject_every_structural_action(array $call): void
    {
        $diagram = $this->diagram();
        $source = Diagram::create(['name' => 'ER', 'type' => Diagram::TYPE_ENTITY_RELATIONSHIP, 'data' => []]);
        $diagram->update(['source_diagram_id' => $source->id]);
        $before = $diagram->data;
        Livewire::test(RelationalBoard::class, ['diagram' => $diagram])
            ->assertDontSee('Editar estrutura')
            ->assertDontSee('Adicionar tabela')
            ->call(...$call)->assertForbidden();
        $this->assertSame($before, $diagram->fresh()->data);
        $this->assertSame([], $source->fresh()->data);
    }
}
