<?php

namespace App\Services;

use App\Models\Diagram;

/**
 * Cria a cópia Relacional de um diagrama ER.
 *
 * A regra é uma só e morava escrita em dois lugares — o botão "Etapa 2" do
 * dashboard e o "Converter para relacional" do quadro ER — que precisam
 * concordar no nome, no tipo e no conteúdo, senão o mesmo projeto ganharia
 * duas cópias diferentes conforme o caminho usado para chegar nele.
 *
 * Não sobrescreve: se a cópia já existe, ela é devolvida como está. Quem
 * decide sobre a defasagem é o quadro Relacional, porque só ele conhece as
 * edições manuais que uma regeneração desfaria.
 */
class RelationalCopy
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
