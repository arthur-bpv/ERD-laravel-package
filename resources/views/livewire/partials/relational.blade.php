{{--
    ================================================================
    CANVAS DO SCHEMA RELACIONAL — derivado, não editável à mão.
    ================================================================

    Isto NÃO é uma repintura do canvas ER: `$relationalNodes`/`$relationalEdges`
    vêm de SchemaBoard::buildRelationalNodes()/buildRelationalEdges(), que por
    baixo chamam App\Support\RelationalSchemaConverter — a conversão de
    verdade ER → relacional (1:1/1:N vira linha direta FK→PK; N:M vira uma
    tabela associativa nova, com chave primária composta).

    Por ser derivado deste outro estado, o canvas aqui é só leitura: sem
    handles de conexão, sem excluir coluna, sem renomear. Pra mudar o
    modelo, o usuário volta em "Ver diagrama ER" — a conversão se refaz
    sozinha na próxima vez que abrir este modo.
--}}
<x-flow
    wire:ignore
    :nodes="$relationalNodes"
    :edges="$relationalEdges"
    :controls="true"
    :minimap="true"
    background="dots"
    default-edge-type="floating"
    @node-drag-end="onNodeDragEnd"
    style="width: 100%; height: 100%;"
>
    <x-slot:node>
        {{--
            Tabela do schema relacional. `rel-node-assoc` marca as tabelas
            que RelationalSchemaConverter criou pra resolver um N:M — elas
            não existem no diagrama ER, então ganham um contorno tracejado e
            a etiqueta "tabela associativa" pra ficar óbvio de onde vieram.
        --}}
        <div class="rel-node" :class="{ 'rel-node-assoc': node.data.isAssociative }" :data-id="node.id">
            <div class="rel-head">
                <span class="rel-head-name" x-text="node.data.name"></span>
            </div>

            <div class="rel-tag" x-show="node.data.isAssociative" x-cloak>
                tabela associativa — nasceu de um relacionamento N:M
            </div>

            <div class="rel-rows">
                <template x-for="attr in node.data.attributes" :key="attr.id">
                    <div class="rel-row" :class="{ 'is-pk': attr.key === 'PK' }">
                        <span class="rel-key">
                            <span x-show="attr.key === 'PK'" title="Chave primária">🔑</span>
                        </span>
                        <span class="rel-attr-name" x-text="attr.name"></span>
                        <span class="rel-attr-fk" x-show="attr.key === 'FK' || attr.fk" x-cloak>FK</span>
                        <span class="rel-attr-type" x-text="attr.type"></span>
                    </div>
                </template>
            </div>
        </div>
    </x-slot:node>

    {{-- ================= LEGENDA ================= --}}
    <x-flow-panel position="bottom-right" class="er-legend" x-data="{ aberta: false }">
        <button class="er-legend-toggle" @click="aberta = !aberta">
            <span x-text="aberta ? '✕' : '?'"></span>
            <span x-show="!aberta">ajuda</span>
        </button>

        <div x-show="aberta" x-cloak class="er-legend-body">
            <div class="er-legend-title">Schema relacional</div>
            <div class="er-legend-hint">
                Gerado automaticamente a partir do diagrama ER: cada seta vai
                da chave estrangeira até a chave primária que ela referencia.
                Relacionamentos N:M (as duas pontas em pé de galinha "muitos")
                viram uma <strong>tabela associativa</strong> nova, com chave
                primária composta pelas duas FKs. Pra editar o modelo, use
                <strong>"Ver diagrama ER"</strong>, no cabeçalho — este canvas
                só mostra o resultado.
            </div>
        </div>
    </x-flow-panel>
</x-flow>
