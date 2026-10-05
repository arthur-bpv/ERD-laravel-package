<?php

namespace Tests\Unit\Services;

use App\Services\ErToRelationalTransformer;
use App\Services\RelationalDrift;
use PHPUnit\Framework\TestCase;

class RelationalDriftTest extends TestCase
{
    private RelationalDrift $drift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->drift = new RelationalDrift(new ErToRelationalTransformer);
    }

    public function test_a_generated_model_is_never_reported_as_outdated(): void
    {
        $source = $this->library();

        $report = $this->drift->report($source, $this->generated($source));

        $this->assertFalse($report['outdated']);
        $this->assertSame([], $report['changes']);
    }

    public function test_key_order_does_not_change_the_fingerprint(): void
    {
        $transformer = new ErToRelationalTransformer;
        $source = $this->library();

        $this->assertSame(
            $transformer->fingerprint($source),
            $transformer->fingerprint(['relations' => $source['relations'], 'entities' => $source['entities']]),
        );
    }

    public function test_a_new_er_attribute_is_reported_as_a_pending_change(): void
    {
        $source = $this->library();
        $relational = $this->generated($source);

        $changed = $this->library();
        $changed['entities'][0]['attributes'][] = [
            'id' => 'books.isbn',
            'name' => 'isbn',
            'type' => 'varchar',
            'key' => 'UQ',
        ];

        $report = $this->drift->report($changed, $relational);

        $this->assertTrue($report['outdated']);
        $this->assertContains('Coluna "isbn" foi adicionada em "Livros".', $report['changes']);
    }

    public function test_moving_a_table_in_the_relational_board_is_not_a_drift(): void
    {
        $source = $this->library();
        $relational = $this->generated($source);
        $relational['tables'][0]['x'] = 4200;
        $relational['tables'][0]['y'] = 900;

        $this->assertFalse($this->drift->report($source, $relational)['outdated']);
    }

    public function test_a_table_created_by_hand_alone_does_not_accuse_the_er(): void
    {
        $source = $this->library();
        $relational = $this->generated($source);
        $relational['tables'][] = [
            'id' => 'audit',
            'name' => 'Auditoria',
            'kind' => 'strong',
            'x' => 0,
            'y' => 0,
            'columns' => [['id' => 'audit.id', 'name' => 'id', 'type' => 'bigint', 'key' => 'PK', 'nullable' => false]],
            'primaryKey' => ['id'],
        ];
        $relational['customized'] = true;

        $report = $this->drift->report($source, $relational);

        $this->assertContains('A tabela "Auditoria" não existe no ER e seria removida.', $report['changes']);
        $this->assertFalse($report['outdated']);
    }

    public function test_a_legacy_model_without_fingerprint_is_trusted_only_while_untouched(): void
    {
        $source = $this->library();
        $legacy = $this->generated($source);
        unset($legacy['sourceFingerprint']);

        $changed = $this->library();
        $changed['entities'] = [$changed['entities'][0]];
        $changed['relations'] = [];

        $this->assertTrue($this->drift->report($changed, $legacy)['outdated']);

        $legacy['customized'] = true;
        $this->assertFalse($this->drift->report($changed, $legacy)['outdated']);
    }

    public function test_removed_relationship_is_reported_with_its_foreign_key(): void
    {
        $source = $this->library();
        $relational = $this->generated($source);

        $changed = $this->library();
        $changed['relations'] = [];
        unset($changed['entities'][1]['attributes'][1]); // a FK também nasce no ER

        $report = $this->drift->report($changed, $relational);

        $this->assertTrue($report['outdated']);
        $this->assertContains('A coluna "book_id" não existe no ER e seria removida de "Empréstimos".', $report['changes']);
        $this->assertContains(
            'A chave estrangeira loans.book_id → books.id não existe no ER e seria removida.',
            $report['changes'],
        );
    }

    public function test_type_key_and_nullability_changes_are_listed_separately(): void
    {
        $source = $this->library();
        $relational = $this->generated($source);

        $changed = $this->library();
        $changed['entities'][0]['attributes'][0] = [
            'id' => 'books.id',
            'name' => 'id',
            'type' => 'integer',
            'key' => '',
            'nullable' => true,
        ];

        $changes = $this->drift->report($changed, $relational)['changes'];

        $this->assertContains('A coluna "id" de "Livros" passou de bigint para integer.', $changes);
        $this->assertContains('A coluna "id" de "Livros" passou a ser sem chave no ER.', $changes);
        $this->assertContains('A coluna "id" de "Livros" passou a aceitar NULL.', $changes);
        $this->assertContains('A chave primária de "Livros" mudou de id para sem chave primária.', $changes);
    }

    public function test_a_foreign_key_that_moves_to_another_column_is_reported_as_two_changes(): void
    {
        $source = $this->library();
        $relational = $this->generated($source);

        $changed = $this->library();
        $changed['relations'][0]['childCard'] = 'cf-one-one';
        $changed['relations'][0]['parentCard'] = 'cf-zero-one';

        $changes = $this->drift->report($changed, $relational)['changes'];

        $this->assertContains('A coluna "book_id" de "Empréstimos" deixou de ser chave estrangeira.', $changes);
        $this->assertContains('Chave estrangeira nova no ER: books.id → loans.id (1:1).', $changes);
        $this->assertContains('A chave estrangeira loans.book_id → books.id não existe no ER e seria removida.', $changes);
    }

    public function test_a_manual_column_size_would_be_reported_before_regeneration(): void
    {
        $source = $this->library();
        $relational = $this->generated($source);
        $relational['customized'] = true;
        $relational['tables'][0]['columns'][1]['length'] = 120;

        // O tamanho editado à mão aparece entre as mudanças que a regeneração
        // desfaria, ainda que ele não esteja no ER (lá não existe tamanho).
        $relational['tables'][0]['columns'][1]['nullable'] = true;

        $changed = $source;
        $changed['entities'][0]['attributes'][] = [
            'id' => 'books.isbn',
            'name' => 'isbn',
            'type' => 'varchar',
            'key' => '',
        ];

        $changes = $this->drift->report($changed, $relational)['changes'];

        $this->assertContains('A coluna "title" de "Livros" mudou de tamanho 120 para 255.', $changes);
        $this->assertContains('Coluna "isbn" foi adicionada em "Livros".', $changes);
    }

    private function generated(array $source): array
    {
        return (new ErToRelationalTransformer)->transform($source);
    }

    private function library(): array
    {
        return [
            'entities' => [
                [
                    'id' => 'books',
                    'name' => 'Livros',
                    'x' => 40,
                    'y' => 40,
                    'attributes' => [
                        ['id' => 'books.id', 'name' => 'id', 'type' => 'bigint', 'key' => 'PK'],
                        ['id' => 'books.title', 'name' => 'title', 'type' => 'varchar', 'key' => ''],
                    ],
                ],
                [
                    'id' => 'loans',
                    'name' => 'Empréstimos',
                    'x' => 420,
                    'y' => 180,
                    'attributes' => [
                        ['id' => 'loans.id', 'name' => 'id', 'type' => 'bigint', 'key' => 'PK'],
                        ['id' => 'loans.book_id', 'name' => 'book_id', 'type' => 'bigint', 'key' => 'FK'],
                    ],
                ],
            ],
            'relations' => [[
                'id' => 'loans-books',
                'name' => 'Empresta',
                'from' => 'loans',
                'fromAttr' => 'loans.book_id',
                'to' => 'books',
                'toAttr' => 'books.id',
                'childCard' => 'cf-zero-many',
                'parentCard' => 'cf-one-one',
            ]],
        ];
    }
}
