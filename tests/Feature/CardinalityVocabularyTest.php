<?php

namespace Tests\Feature;

use App\Livewire\SchemaBoard;
use Tests\TestCase;

/**
 * Cardinality spans import validation, SchemaBoard, and JavaScript SVG markers.
 *
 * A name missing from one list silently becomes an ordinary arrow. These
 * tests keep the three declarations synchronized.
 */
class CardinalityVocabularyTest extends TestCase
{
    public function test_every_cardinality_the_server_accepts_reaches_the_canvas(): void
    {
        $accepted = $this->acceptedByImport();

        $this->assertNotSame([], $accepted);

        // SchemaBoard rejects unknown markers before drawing them.
        $rendered = $this->cardinalitiesDeclaredIn(SchemaBoard::class, 'CARDINALITY_MARKERS');

        $this->assertSame([], array_diff($accepted, $rendered), 'O SchemaBoard rejeita uma cardinalidade que o import aceita.');

        // The transformer must convert every accepted cardinality.
        $this->assertSame([], array_diff($accepted, $this->cardinalitiesKnownToTheTransformer()));

        // JavaScript must register every marker to avoid arrow fallback.
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
     * Names accepted by ErDiagramImporter.
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
     * Names the transformer can convert to min/many.
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

        // Match registrations, not the file's verification list.
        preg_match_all("/registerCardinality\('(cf-[a-z-]+)'/", (string) $source, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Options offered by the floating relationship editor.
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
