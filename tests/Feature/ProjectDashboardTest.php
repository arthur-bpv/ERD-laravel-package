<?php

namespace Tests\Feature;

use App\Livewire\ProjectDashboard;
use App\Livewire\SchemaBoard;
use App\Models\Diagram;
use App\Services\ErToRelationalTransformer;
use App\Support\ErDiagramImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_is_the_application_entry_point(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSeeLivewire(ProjectDashboard::class)
            ->assertSee('Do conceito ao banco');
    }

    public function test_project_starts_with_an_er_diagram(): void
    {
        Livewire::test(ProjectDashboard::class)
            ->set('projectName', 'Biblioteca')
            ->call('createProject')
            ->assertRedirect();

        $diagram = Diagram::sole();

        $this->assertSame(Diagram::TYPE_ENTITY_RELATIONSHIP, $diagram->type);
        $this->assertNull($diagram->source_diagram_id);
    }

    public function test_import_name_does_not_block_the_regular_project_form(): void
    {
        Livewire::test(ProjectDashboard::class)
            ->set('importProjectName', '')
            ->set('projectName', 'Biblioteca')
            ->call('createProject')
            ->assertRedirect();

        $this->assertSame('Biblioteca', Diagram::sole()->name);
    }

    public function test_dashboard_imports_json_as_a_new_project(): void
    {
        $json = file_get_contents(public_path('examples/er-conversion-cases.json'));

        Livewire::test(ProjectDashboard::class)
            ->set('importProjectName', 'Cenários de conversão')
            ->set('importJson', $json)
            ->call('importProject')
            ->assertRedirect();

        $diagram = Diagram::sole();

        $this->assertSame('Cenários de conversão', $diagram->name);
        $this->assertSame(Diagram::TYPE_ENTITY_RELATIONSHIP, $diagram->type);
        $this->assertNull($diagram->source_diagram_id);
        $this->assertCount(41, $diagram->data['relations']);
    }

    public function test_dashboard_rejects_an_invalid_import_without_creating_a_project(): void
    {
        Livewire::test(ProjectDashboard::class)
            ->set('importProjectName', 'Inválido')
            ->set('importJson', '{"entities":[],"relations":[{"id":"r1"}]}')
            ->call('importProject')
            ->assertSet('importError', 'relations[0].name precisa ter nome de até 80 caracteres.');

        $this->assertSame(0, Diagram::count());
    }

    public function test_import_controls_exist_only_on_the_dashboard(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Importar projeto');

        $this->get('/schema')
            ->assertOk()
            ->assertDontSee('Importar diagrama ER');
    }

    public function test_analysis_example_always_creates_a_new_project(): void
    {
        Livewire::test(ProjectDashboard::class)->call('createAnalysisProject')->assertRedirect();
        Livewire::test(ProjectDashboard::class)->call('createAnalysisProject')->assertRedirect();

        $this->assertSame(2, Diagram::query()
            ->where('name', 'Análise de alternativas ER → relacional')
            ->count());
        $this->assertCount(41, Diagram::latest('id')->first()->data['relations']);
        $this->assertCount(41, ErDiagramImport::parse(
            file_get_contents(public_path('examples/er-conversion-cases.json')),
        )['relations']);
    }

    public function test_new_project_er_board_starts_empty(): void
    {
        $diagram = Diagram::create([
            'name' => 'Quadro branco',
            'type' => Diagram::TYPE_ENTITY_RELATIONSHIP,
            'data' => [],
        ]);

        $component = Livewire::test(SchemaBoard::class, ['diagram' => $diagram]);

        $this->assertSame([], $component->get('entities'));
        $this->assertSame([], $component->get('relations'));
        $this->assertSame([], $component->instance()->buildNodes());
    }

    public function test_old_board_uses_largest_id_when_creating_an_entity(): void
    {
        $diagram = Diagram::create([
            'name' => 'Legado',
            'type' => Diagram::TYPE_ENTITY_RELATIONSHIP,
            'data' => [
                'entities' => [
                    ['id' => 'e4', 'name' => 'A', 'x' => 0, 'y' => 0, 'attributes' => []],
                    ['id' => 'e5', 'name' => 'B', 'x' => 0, 'y' => 0, 'attributes' => []],
                    ['id' => 'e6', 'name' => 'C', 'x' => 0, 'y' => 0, 'attributes' => []],
                ],
                'relations' => [],
            ],
        ]);

        $component = Livewire::test(SchemaBoard::class, ['diagram' => $diagram])
            ->set('newEntityName', 'Nova')
            ->call('createEntity');

        $this->assertSame('e7', collect($component->get('entities'))->last()['id']);
    }

    public function test_er_board_saves_current_state_and_converts_directly(): void
    {
        $diagram = Diagram::create([
            'name' => 'Biblioteca',
            'type' => Diagram::TYPE_ENTITY_RELATIONSHIP,
            'data' => [],
        ]);

        Livewire::test(SchemaBoard::class, ['diagram' => $diagram])
            ->set('entities', [[
                'id' => 'books',
                'name' => 'Book',
                'x' => 40,
                'y' => 60,
                'attributes' => [[
                    'id' => 'books.id',
                    'name' => 'bookNo',
                    'type' => 'bigint',
                    'key' => 'PK',
                ]],
            ]])
            ->call('convertToRelational')
            ->assertRedirect();

        $this->assertSame('Book', $diagram->fresh()->data['entities'][0]['name']);
        $relational = Diagram::query()->where('source_diagram_id', $diagram->id)->sole();
        $this->assertSame(Diagram::TYPE_RELATIONAL, $relational->type);
        $this->assertSame('Book', $relational->data['tables'][0]['name']);
    }

    public function test_relational_diagram_is_independent_and_references_its_er_source(): void
    {
        $er = Diagram::create([
            'name' => 'Biblioteca',
            'type' => Diagram::TYPE_ENTITY_RELATIONSHIP,
            'data' => [
                'entities' => [[
                    'id' => 'books',
                    'name' => 'Book',
                    'x' => 0,
                    'y' => 0,
                    'attributes' => [[
                        'id' => 'books.id',
                        'name' => 'bookNo',
                        'type' => 'bigint',
                        'key' => 'PK',
                    ]],
                ]],
                'relations' => [],
            ],
        ]);

        Livewire::test(ProjectDashboard::class)
            ->call('createRelational', $er->id)
            ->assertRedirect();

        $relational = Diagram::query()->where('type', Diagram::TYPE_RELATIONAL)->sole();

        $this->assertSame($er->id, $relational->source_diagram_id);
        $this->assertNotSame($er->id, $relational->id);
        $this->assertSame('Book', $relational->data['tables'][0]['name']);
    }

    public function test_one_er_diagram_has_at_most_one_relational_board(): void
    {
        $er = Diagram::create([
            'name' => 'Biblioteca',
            'type' => Diagram::TYPE_ENTITY_RELATIONSHIP,
            'data' => [],
        ]);

        Livewire::test(ProjectDashboard::class)->call('createRelational', $er->id);
        Livewire::test(ProjectDashboard::class)->call('createRelational', $er->id);

        $this->assertSame(1, Diagram::query()->where('source_diagram_id', $er->id)->count());
    }

    public function test_deleting_a_project_also_deletes_its_relational_board(): void
    {
        $er = Diagram::create([
            'name' => 'Descartável',
            'type' => Diagram::TYPE_ENTITY_RELATIONSHIP,
            'data' => [],
        ]);
        $relational = Diagram::create([
            'name' => 'Descartável — Relacional',
            'type' => Diagram::TYPE_RELATIONAL,
            'source_diagram_id' => $er->id,
            'data' => [],
        ]);

        Livewire::test(ProjectDashboard::class)->call('deleteProject', $er->id);

        $this->assertModelMissing($er);
        $this->assertModelMissing($relational);
    }

    public function test_project_card_flags_only_the_relational_copies_left_behind_by_the_er(): void
    {
        $transformer = app(ErToRelationalTransformer::class);

        $fresh = Diagram::create(['name' => 'Em dia', 'type' => Diagram::TYPE_ENTITY_RELATIONSHIP, 'data' => $this->erData()]);
        Diagram::create([
            'name' => 'Em dia — Relacional',
            'type' => Diagram::TYPE_RELATIONAL,
            'source_diagram_id' => $fresh->id,
            'data' => $transformer->transform($fresh->data),
        ]);

        $late = Diagram::create(['name' => 'Atrasado', 'type' => Diagram::TYPE_ENTITY_RELATIONSHIP, 'data' => $this->erData()]);
        Diagram::create([
            'name' => 'Atrasado — Relacional',
            'type' => Diagram::TYPE_RELATIONAL,
            'source_diagram_id' => $late->id,
            'data' => $transformer->transform($late->data),
        ]);
        $changed = $late->data;
        $changed['entities'][0]['attributes'][] = [
            'id' => 'books.isbn',
            'name' => 'isbn',
            'type' => 'varchar',
            'key' => 'UQ',
        ];
        $late->update(['data' => $changed]);

        $component = Livewire::test(ProjectDashboard::class);

        $this->assertSame([$late->id], $component->instance()->outdatedRelational);
        $this->assertSame(1, substr_count($component->html(), 'Desatualizada'));
        $this->assertStringContainsString('Em dia', $component->html());
    }

    private function erData(): array
    {
        return [
            'entities' => [[
                'id' => 'books',
                'name' => 'Book',
                'x' => 0,
                'y' => 0,
                'attributes' => [[
                    'id' => 'books.id',
                    'name' => 'bookNo',
                    'type' => 'bigint',
                    'key' => 'PK',
                ]],
            ]],
            'relations' => [],
        ];
    }
}
