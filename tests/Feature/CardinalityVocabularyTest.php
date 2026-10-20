<?php

namespace Tests\Feature;

use App\Livewire\SchemaBoard;
use Tests\TestCase;

/**
 * A cardinalidade atravessa três linguagens: o servidor valida no import, o
 * `SchemaBoard` barra valor vindo do cliente, e o JS registra o marcador SVG.
 *
 * Um nome aceito em uma lista e ausente em outra falha em silêncio — a
 * relação vira uma seta comum, sem erro no console. Estes testes existem para
 * essa divergência não voltar.
 */
class CardinalityVocabularyTest extends TestCase
{
    public function test_every_cardinality_the_server_accepts_reaches_the_canvas(): void
    {
        $accepted = $this->acceptedByImport();

        $this->assertNotSame([], $accepted);

        // O SchemaBoard barra valor fora da lista, então um nome que ele
        // recusa nunca chega a ser desenhado.
        $rendered = $this->cardinalitiesDeclaredIn(SchemaBoard::class, 'CARDINALITY_MARKERS');

        $this->assertSame([], array_diff($accepted, $rendered), 'O SchemaBoard rejeita uma cardinalidade que o import aceita.');

        // O transformador precisa saber converter todas, senão a cópia
        // relacional nasce com cardinalidade errada.
        $this->assertSame([], array_diff($accepted, $this->cardinalitiesKnownToTheTransformer()));

        // E o JS precisa ter marcador para todas, senão o AlpineFlow cai no
        // fallback de seta fechada.
        $this->assertSame([], array_diff($accepted, $this->cardinalitiesWithAJsMarker()));
    }

    public function test_the_three_declarations_list_exactly_the_same_cardinalities(): void
    {
        $expected = $this->acceptedByImport();
        sort($expected);
        $this->assertSame(['cf-one-many', 'cf-one-one', 'cf-zero-many', 'cf-zero-one'], $expected);

        $markers = $this->cardinalitiesWithAJsMarker();
        sort($markers);
        $this->assertSame($expected, $markers, 'resources/js/erd/markers.js diverges from the server.');

        $options = $this->cardinalitiesOfferedByTheEdgeEditor();
        sort($options);
        $this->assertSame($expected, $options, 'resources/js/erd/relationship-editor.js diverges from the server.');

        $declared = $this->cardinalitiesDeclaredIn(SchemaBoard::class, 'CARDINALITY_MARKERS');
        sort($declared);
        $this->assertSame($expected, $declared, 'SchemaBoard::CARDINALITY_MARKERS diverges from the server.');
    }

    /**
     * Nomes que o import aceita (App\Support\ErDiagramImporter).
     *
     * @return array<int, string>
     */
    private function acceptedByImport(): array
    {
        $source = file_get_contents((string) app_path('Support/ErDiagramImporter.php'));

        preg_match_all("/'(cf-[a-z-]+)'/", (string) $source, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Nomes que o transformador sabe converter para min/many.
     *
     * @return array<int, string>
     */
    private function cardinalitiesKnownToTheTransformer(): array
    {
        $source = file_get_contents((string) app_path('Services/ErToRelationalTransformer.php'));

        preg_match_all("/'(cf-[a-z-]+)'/", (string) $source, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return array<int, string>
     */
    private function cardinalitiesWithAJsMarker(): array
    {
        $source = file_get_contents(resource_path('js/erd/markers.js'));

        // Só as chamadas que registram de fato um marcador, e não a lista de
        // conferência que o próprio arquivo mantém.
        preg_match_all("/registerCardinality\('(cf-[a-z-]+)'/", (string) $source, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * As opções que o painel flutuante de relacionamento oferece.
     *
     * @return array<int, string>
     */
    private function cardinalitiesOfferedByTheEdgeEditor(): array
    {
        $source = file_get_contents(resource_path('js/erd/relationship-editor.js'));

        preg_match_all("/m: '(cf-[a-z-]+)'/", (string) $source, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @param  class-string  $class
     * @return array<int, string>
     */
    private function cardinalitiesDeclaredIn(string $class, string $constant): array
    {
        $reflection = new \ReflectionClass($class);

        /** @var array<int, string> $values */
        $values = array_values($reflection->getConstant($constant));

        return $values;
    }
}
