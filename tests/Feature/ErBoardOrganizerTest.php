<?php

namespace Tests\Feature;

use App\Livewire\SchemaBoard;
use App\Models\Diagram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ErBoardOrganizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_toolbar_exposes_the_organizer_action(): void
    {
        $this->get('/schema')
            ->assertOk()
            ->assertSee('wire:click="organizeBoard"', false)
            ->assertSee('Organizar quadro');
    }

    public function test_it_organizes_only_entities_and_keeps_the_classic_relationship_edges(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('organizeBoard')
            ->assertDispatched('flow:fromObject')
            ->assertDispatched('flow:fitView');

        $entities = collect($component->get('entities'))->keyBy('id');
        $nodes = collect($component->instance()->buildNodes());
        $edges = collect($component->instance()->buildEdges());

        $this->assertTrue($entities['posts']['x'] < $entities['users']['x']);
        $this->assertTrue($entities['comments']['x'] > $entities['users']['x']);
        $this->assertNull($nodes->firstWhere('id', 'relation-r1'));

        $relationship = $edges->firstWhere('id', 'r1');
        $this->assertSame(['posts', 'users'], [$relationship['source'], $relationship['target']]);
        $this->assertSame('floating', $relationship['type']);
        $this->assertSame('smoothstep', $relationship['pathType']);
        $this->assertSame('escreve', $relationship['label']);
    }

    public function test_it_preserves_a_self_relationship_offset_while_organizing_its_entity(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('createSelfRelation', 'comments')
            ->call('onNodeDragEnd', 'relation-r4', ['x' => 400, 'y' => 120]);

        $beforeEntity = collect($component->get('entities'))->firstWhere('id', 'comments');
        $beforeDiamond = collect($component->instance()->buildNodes())->firstWhere('id', 'relation-r4');
        $offset = [
            'x' => $beforeDiamond['position']['x'] - $beforeEntity['x'],
            'y' => $beforeDiamond['position']['y'] - $beforeEntity['y'],
        ];

        $component->call('organizeBoard');

        $afterEntity = collect($component->get('entities'))->firstWhere('id', 'comments');
        $afterDiamond = collect($component->instance()->buildNodes())->firstWhere('id', 'relation-r4');

        $this->assertSame($offset['x'], $afterDiamond['position']['x'] - $afterEntity['x']);
        $this->assertSame($offset['y'], $afterDiamond['position']['y'] - $afterEntity['y']);
    }

    public function test_it_saves_the_arranged_board_automatically(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('save')
            ->assertHasNoErrors();

        $diagramId = $component->get('diagramId');
        $this->assertNotNull($diagramId);
        $before = $component->get('entities');

        $component->call('organizeBoard');

        $expected = $component->get('entities');
        $this->assertNotSame($before, $expected, 'O arranjo precisa ter mexido nas coordenadas.');

        $saved = Diagram::query()->findOrFail($diagramId);
        $this->assertSame($expected, $saved->data['entities']);
    }

    public function test_it_keeps_relationship_attributes_attached_after_organizing(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('addRelationAttribute', 'r1', 'desde')
            ->call('organizeBoard');

        $relation = collect($component->get('relations'))->firstWhere('id', 'r1');
        $attribute = $relation['attributes'][0];
        $this->assertArrayHasKey('offsetX', $attribute);
        $this->assertArrayHasKey('offsetY', $attribute);

        $anchor = collect($component->instance()->buildNodes())
            ->firstWhere('id', 'relation-r1-attribute-anchor');
        $balloon = collect($component->instance()->buildNodes())
            ->firstWhere('id', 'relation-r1-attr-'.$attribute['id']);

        // O balão é a âncora do seu relacionamento mais um offset: é esse
        // offset que o mantém preso ao relacionamento quando o quadro muda.
        $this->assertSame($anchor['position']['x'] + $attribute['offsetX'], $balloon['position']['x']);
        $this->assertSame($anchor['position']['y'] + $attribute['offsetY'], $balloon['position']['y']);

        // E o arranjo sobrevive ao reload, já que o offset foi gravado.
        $saved = Diagram::query()->findOrFail($component->get('diagramId'));
        $this->assertSame(
            $attribute['offsetX'],
            collect($saved->data['relations'])->firstWhere('id', 'r1')['attributes'][0]['offsetX'],
        );
    }

    public function test_it_places_relationship_attributes_outside_the_entities(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('addRelationAttribute', 'r1', 'desde')
            ->call('addRelationAttribute', 'r2', 'motivo')
            ->call('organizeBoard');

        $balloons = collect($component->instance()->buildNodes())
            ->filter(fn (array $node): bool => ($node['data']['kind'] ?? null) === 'relationship-attribute');
        $this->assertCount(2, $balloons);

        $entities = collect($component->get('entities'))->map(fn (array $entity): array => [
            'left' => $entity['x'] - 28,
            'right' => $entity['x'] + 232 + 28,
            'top' => $entity['y'] - 28,
            'bottom' => $entity['y'] + (84 + (count($entity['attributes']) * 24.5)) + 28,
        ])->all();

        foreach ($balloons as $balloon) {
            $rectangle = [
                'left' => $balloon['position']['x'] - 28,
                'right' => $balloon['position']['x'] + 136 + 28,
                'top' => $balloon['position']['y'] - 28,
                'bottom' => $balloon['position']['y'] + 58 + 28,
            ];

            foreach ($entities as $entity) {
                $overlaps = $rectangle['left'] < $entity['right']
                    && $rectangle['right'] > $entity['left']
                    && $rectangle['top'] < $entity['bottom']
                    && $rectangle['bottom'] > $entity['top'];

                $this->assertFalse(
                    $overlaps,
                    sprintf('O balão %s ficou sobre a entidade em (%s, %s).', $balloon['id'], $entity['left'], $entity['top']),
                );
            }
        }
    }

    public function test_it_follows_a_self_relationship_diamond_that_was_never_dragged(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('createSelfRelation', 'comments')
            ->call('addRelationAttribute', 'r4', 'nivel')
            ->call('organizeBoard');

        $entity = collect($component->get('entities'))->firstWhere('id', 'comments');
        $nodes = collect($component->instance()->buildNodes());
        $diamond = $nodes->firstWhere('id', 'relation-r4');
        $balloon = $nodes->firstWhere('data.kind', 'relationship-attribute');

        // O losango padrão é derivado da entidade, então o arranjo tem que
        // levá-lo junto — e o balão que dele pende junto também.
        $this->assertSame($entity['x'] + 56, $diamond['position']['x']);
        $this->assertSame($diamond['position']['x'], $balloon['position']['x'] - $balloon['data']['offsetX']);
        $this->assertSame($diamond['position']['y'], $balloon['position']['y'] - $balloon['data']['offsetY']);
    }

    public function test_it_leaves_room_above_an_entity_that_owns_a_relationship_diamond(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('createSelfRelation', 'comments')
            ->call('organizeBoard');

        // Sem folga, o losango de uma autorrelação é preso no topo do quadro
        // e não sobra ponto livre ao redor dele para o balão.
        $this->assertGreaterThanOrEqual(
            220,
            collect($component->get('entities'))->firstWhere('id', 'comments')['y'],
        );
    }

    public function test_it_keeps_every_relationship_attribute_close_to_its_relationship(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('createSelfRelation', 'comments');

        foreach (['r1', 'r2', 'r3', 'r4'] as $relationId) {
            foreach (['a', 'b', 'c', 'd'] as $name) {
                $component->call('addRelationAttribute', $relationId, $name);
            }
        }

        $component->call('organizeBoard');

        $nodes = collect($component->instance()->buildNodes());
        $anchors = $nodes
            ->filter(fn (array $node): bool => in_array(
                $node['data']['kind'] ?? null,
                ['relationship-attribute-anchor', 'relationship'],
                true,
            ))
            ->keyBy('data.relationId');

        $balloons = $nodes->filter(fn (array $node): bool => ($node['data']['kind'] ?? null) === 'relationship-attribute');
        $this->assertCount(16, $balloons);

        $distances = [];
        foreach ($balloons as $balloon) {
            $anchor = $anchors[$balloon['data']['relationId']]['position'];
            $distance = hypot(
                $balloon['position']['x'] - $anchor['x'],
                $balloon['position']['y'] - $anchor['y'],
            );
            $distances[$balloon['data']['relationId']][] = $distance;

            // Ordenar os candidatos por faixa (e não por distância) mandava o
            // balão para 450px de lado da relação; por distância o vínculo
            // visual se mantém.
            $this->assertLessThanOrEqual(
                280,
                $distance,
                sprintf('O balão %s ficou a %.0fpx do seu relacionamento.', $balloon['id'], $distance),
            );
        }

        // E o grupo inteiro fica no anel mais interno possível: a espera só
        // deve aumentar quando o entorno do relacionamento está ocupado.
        foreach ($distances as $relationId => $relationDistances) {
            $close = count(array_filter($relationDistances, fn (float $d): bool => $d <= 250));
            $this->assertGreaterThanOrEqual(
                3,
                $close,
                sprintf('A relação %s deixou só %d de %d balões no anel interno.', $relationId, $close, count($relationDistances)),
            );
        }
    }

    public function test_the_anchor_sits_where_the_client_will_draw_the_relationship_label(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('addRelationAttribute', 'r1', 'desde');

        $entities = collect($component->get('entities'))->keyBy('id');
        $relation = collect($component->get('relations'))->firstWhere('id', 'r1');
        $from = $entities[$relation['from']];
        $to = $entities[$relation['to']];

        // O cliente ancora no meio do trecho visível da linha, na altura do
        // centro de cada entidade. Usar uma altura fixa aqui deixava a âncora
        // dezenas de pixels fora do lugar, e a escolha do ponto do balão era
        // validada contra uma posição que ninguém veria.
        $fromCenter = $from['y'] + ((84 + (count($from['attributes']) * 24.5)) / 2);
        $toCenter = $to['y'] + ((84 + (count($to['attributes']) * 24.5)) / 2);

        $anchor = collect($component->instance()->buildNodes())
            ->firstWhere('id', 'relation-r1-attribute-anchor');

        $this->assertSame((int) round((($from['x'] + $to['x'] + 232) / 2)), $anchor['position']['x']);
        $this->assertSame((int) round(($fromCenter + $toCenter) / 2), $anchor['position']['y']);
    }
}
