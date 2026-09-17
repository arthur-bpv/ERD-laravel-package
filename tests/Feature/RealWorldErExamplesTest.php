<?php

namespace Tests\Feature;

use App\Services\ErToRelationalTransformer;
use App\Support\ErDiagramImport;
use Tests\TestCase;

class RealWorldErExamplesTest extends TestCase
{
    public function test_curated_examples_are_valid_spaced_and_convertible(): void
    {
        $examples = [
            'marketplace-er.json' => [
                'entities' => 7,
                'relations' => 6,
                'tables' => [
                    'relation_order_items' => ['quantity', 'unit_price', 'discount_percent'],
                    'relation_supplier_catalog' => ['supplier_sku', 'cost_price', 'lead_time_days'],
                    'orders' => ['delivery_instructions'],
                    'customers_phone' => ['phone'],
                ],
            ],
            'clinic-er.json' => [
                'entities' => 6,
                'relations' => 6,
                'tables' => [
                    'relation_doctor_specialties' => ['certified_at'],
                    'relation_prescription_items' => ['dosage', 'frequency', 'duration_days'],
                    'doctors' => ['mentorDoctor_id', 'mentorship_started_at'],
                    'patients_phone' => ['phone'],
                ],
            ],
        ];

        foreach ($examples as $filename => $expectation) {
            $diagram = ErDiagramImport::parse(
                file_get_contents(public_path("examples/{$filename}")),
            );

            $this->assertCount($expectation['entities'], $diagram['entities'], $filename);
            $this->assertCount($expectation['relations'], $diagram['relations'], $filename);
            $this->assertEntitiesDoNotOverlap($diagram['entities'], $filename);

            $relational = (new ErToRelationalTransformer)->transform($diagram);

            foreach ($expectation['tables'] as $tableId => $columnNames) {
                $table = collect($relational['tables'])->firstWhere('id', $tableId);

                $this->assertNotNull($table, "{$filename}: tabela {$tableId} não foi criada.");
                $this->assertEmpty(
                    array_diff($columnNames, array_column($table['columns'], 'name')),
                    "{$filename}: colunas esperadas ausentes em {$tableId}.",
                );
            }
        }
    }

    private function assertEntitiesDoNotOverlap(array $entities, string $filename): void
    {
        foreach ($entities as $index => $entity) {
            foreach (array_slice($entities, $index + 1) as $other) {
                $separated = abs($entity['x'] - $other['x']) >= 280
                    || abs($entity['y'] - $other['y']) >= 220;

                $this->assertTrue(
                    $separated,
                    "{$filename}: {$entity['name']} e {$other['name']} estão visualmente sobrepostas.",
                );
            }
        }
    }
}
