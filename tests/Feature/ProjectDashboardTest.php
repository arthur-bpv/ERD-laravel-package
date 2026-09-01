<?php

namespace Tests\Feature;

use App\Livewire\ProjectDashboard;
use App\Livewire\SchemaBoard;
use App\Models\Diagram;
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
}
