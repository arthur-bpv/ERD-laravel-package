<?php

namespace App\Services;

use App\Models\Diagram;

/**
 * Creates a relational copy of an ER diagram.
 *
 * The dashboard and ER editor share the same creation rule so they produce
 * the same name, type, and content for a project.
 *
 * Existing copies are returned unchanged. The relational board decides when
 * to regenerate because it knows which manual edits that would replace.
 */
class RelationalDiagramCreator
{
    public function __construct(private ErToRelationalTransformer $transformer) {}

    public function findOrCreate(Diagram $source): Diagram
    {
        return Diagram::query()->firstOrCreate(
            ['source_diagram_id' => $source->id],
            [
                'name' => $source->name.' — Relacional',
                'type' => Diagram::TYPE_RELATIONAL,
                'data' => $this->transformer->transform($source->data ?? []),
            ],
        );
    }
}
