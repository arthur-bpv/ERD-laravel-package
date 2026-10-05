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
        $rendered = $this->cardinalitiesDeclaredIn(SchemaBoard::class, 'CARDINALIDADES');

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

        $markers = $this->cardinalitiesWithAJsMarker();
        sort($markers);
        $this->assertSame($expected, $markers, 'resources/js/erd/markers.js divergiu do servidor.');

        $options = $this->cardinalitiesOfferedByTheEdgeEditor();
        sort($options);
        $this->assertSame($expected, $options, 'resources/js/erd/edge-editor.js divergiu do servidor.');

        $declared = $this->cardinalitiesDeclaredIn(SchemaBoard::class, 'CARDINALIDADES');
        sort($declared);
        $this->assertSame($expected, $declared, 'SchemaBoard::CARDINALIDADES divergiu do servidor.');
    }

    public function test_a_cardinality_with_no_marker_does_not_come_from_the_import(): void
    {
        // Guarda o motivo de a lista ser comparada: `cf-many` era aceito pelo
        // import e pelo transformador, mas não existia nem no PHP nem no JS.
        $this->assertContains('cf-many', $this->acceptedByImport());
        $this->assertContains('cf-many', $this->cardinalitiesWithAJsMarker());
    }

    /**
     * Nomes que o import aceita (App\Support\ErDiagramImport).
     *
     * @return array<int, string>
     */
    private function acceptedByImport(): array
    {
        $source = file_get_contents((string) app_path('Support/ErDiagramImport.php'));

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
        preg_match_all("/registrarCardinalidade\('(cf-[a-z-]+)'/", (string) $source, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * As opções que o painel flutuante de relacionamento oferece.
     *
     * @return array<int, string>
     */
    private function cardinalitiesOfferedByTheEdgeEditor(): array
    {
        $source = file_get_contents(resource_path('js/erd/edge-editor.js'));

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
