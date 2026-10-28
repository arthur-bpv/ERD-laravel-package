<div x-show="$wire.showStructureEditor" x-cloak class="rel-modal" role="dialog" aria-modal="true" aria-labelledby="structure-title"
    @click.self="$wire.showStructureEditor = false" @keydown.escape.window="$wire.showStructureEditor = false">
    <div class="rel-modal-card rel-structure-editor">
        <header>
            <strong id="structure-title">Editar estrutura relacional</strong>
            <button type="button" wire:click="$set('showStructureEditor', false)" aria-label="Fechar editor de estrutura">✕</button>
        </header>
        <div class="rel-structure-body">
            <h2>Tabelas e colunas</h2>
            <form x-data="{ name: '' }" @submit.prevent="$wire.addTable(name)" class="rel-structure-row">
                <input x-model="name" required maxlength="80" placeholder="Nome da nova tabela" aria-label="Nome da nova tabela">
                <button type="submit" wire:loading.attr="disabled">Adicionar tabela</button>
            </form>
            <p class="rel-structure-help">Novas tabelas começam com uma coluna id (chave primária). Tipos, tamanhos e nulabilidade também podem ser editados no quadro.</p>
            @foreach ($tables as $table)
                <details wire:key="structure-table-{{ $table['id'] }}" class="rel-structure-item">
                    <summary>{{ $table['name'] }} · {{ count($table['columns']) }} colunas</summary>
                    <form x-data="{ name: @js($table['name']) }" @submit.prevent="$wire.renameTable(@js($table['id']), name)" class="rel-structure-row">
                        <input x-model="name" required maxlength="80" aria-label="Nome da tabela {{ $table['name'] }}">
                        <button type="submit" wire:loading.attr="disabled">Renomear tabela</button>
                        <button type="button" class="is-danger" wire:click="deleteSelection(@js([$table['id']]))"
                            wire:confirm="Remover esta tabela, suas colunas e seus relacionamentos?" wire:loading.attr="disabled">Remover tabela</button>
                    </form>
                    @foreach ($table['columns'] as $column)
                        <form wire:key="structure-column-{{ $column['id'] }}" x-data="{ name: @js($column['name']) }"
                            @submit.prevent="$wire.renameColumn(@js($table['id']), @js($column['id']), name)" class="rel-structure-row">
                            <span class="rel-structure-key">{{ $column['key'] ?: '—' }}</span>
                            <input x-model="name" required maxlength="80" aria-label="Nome da coluna {{ $column['name'] }}">
                            <span>{{ $column['type'] }}</span>
                            <button type="submit" wire:loading.attr="disabled">Renomear coluna</button>
                            <button type="button" class="is-danger" wire:click="deleteColumn(@js($table['id']), @js($column['id']))"
                                wire:confirm="Remover esta coluna e os relacionamentos que dependem dela?" wire:loading.attr="disabled">Remover coluna</button>
                        </form>
                    @endforeach
                    <form x-data="{ name: '', type: 'varchar', key: '' }"
                        @submit.prevent="$wire.addColumn(@js($table['id']), name, type, key)" class="rel-structure-row">
                        <input x-model="name" required maxlength="80" placeholder="Nova coluna" aria-label="Nome da nova coluna em {{ $table['name'] }}">
                        <select x-model="type" aria-label="Tipo da nova coluna">
                            <template x-for="(label, value) in catalog[dialect].types" :key="value">
                                <option :value="value" x-text="label" :disabled="['enum', 'custom'].includes(value)"></option>
                            </template>
                        </select>
                        <select x-model="key" aria-label="Chave da nova coluna">
                            <option value="">Sem chave</option>
                            <option value="PK">Primária (PK)</option>
                            <option value="UQ">Única (UNIQUE)</option>
                        </select>
                        <button type="submit" wire:loading.attr="disabled">Adicionar coluna</button>
                    </form>
                </details>
            @endforeach

            <h2>Relacionamentos (chaves estrangeiras)</h2>
            @php($seenRelationships = [])
            @foreach ($foreignKeys as $key)
                @php($group = json_encode([$key['fromTable'], $key['toTable'], $key['relationshipName'] ?? $key['id']]))
                @continue(in_array($group, $seenRelationships, true))
                @php($seenRelationships[] = $group)
                <div wire:key="structure-relationship-{{ $key['id'] }}" class="rel-structure-item">
                    <p>{{ collect($tables)->firstWhere('id', $key['fromTable'])['name'] }} → {{ collect($tables)->firstWhere('id', $key['toTable'])['name'] }}</p>
                    <p class="rel-structure-help">
                        @foreach ($foreignKeys as $member)
                            @if ($member['id'] === $key['id'] || (!empty($key['relationshipName']) && $member['fromTable'] === $key['fromTable'] && $member['toTable'] === $key['toTable'] && ($member['relationshipName'] ?? '') === $key['relationshipName']))
                                {{ $member['fromColumn'] }} → {{ $member['toColumn'] }};
                            @endif
                        @endforeach
                    </p>
                    <form x-data="{ name: @js($key['relationshipName'] ?? '') }" @submit.prevent="$wire.renameRelationship(@js($key['id']), name)" class="rel-structure-row">
                        <input x-model="name" required maxlength="80" aria-label="Nome do relacionamento">
                        <button type="submit" wire:loading.attr="disabled">Renomear relacionamento</button>
                        <button type="button" class="is-danger" wire:click="deleteRelationship(@js($key['id']))"
                            wire:confirm="Remover este relacionamento? As colunas serão mantidas." wire:loading.attr="disabled">Remover relacionamento</button>
                    </form>
                </div>
            @endforeach
            <form x-data="{ name: '', from: '', to: '', pairs: [{ id: 0, from: '', to: '' }], nextId: 1 }"
                @submit.prevent="$wire.addRelationship(name, from, pairs.map(pair => pair.from), to, pairs.map(pair => pair.to))" class="rel-structure-item">
                <h3>Novo relacionamento</h3>
                <div class="rel-structure-row">
                    <input x-model="name" required maxlength="80" placeholder="Nome do relacionamento" aria-label="Nome do novo relacionamento">
                    <select x-model="from" required @change="pairs.forEach(pair => pair.from = '')" aria-label="Tabela de origem da FK">
                        <option value="">Tabela de origem (FK)</option>
                        <template x-for="table in $wire.tables" :key="table.id"><option :value="table.id" x-text="table.name"></option></template>
                    </select>
                    <select x-model="to" required @change="pairs.forEach(pair => pair.to = '')" aria-label="Tabela referenciada">
                        <option value="">Tabela referenciada (PK/UNIQUE)</option>
                        <template x-for="table in $wire.tables" :key="table.id"><option :value="table.id" x-text="table.name"></option></template>
                    </select>
                </div>
                <template x-for="(pair, index) in pairs" :key="pair.id">
                    <div class="rel-structure-row">
                        <select x-model="pair.from" required aria-label="Coluna de origem da FK">
                            <option value="">Coluna de origem</option>
                            <template x-for="column in ($wire.tables.find(table => table.id === from)?.columns ?? [])" :key="column.id"><option :value="column.id" x-text="column.name"></option></template>
                        </select>
                        <span>→</span>
                        <select x-model="pair.to" required aria-label="Coluna referenciada">
                            <option value="">Coluna referenciada</option>
                            <template x-for="column in ($wire.tables.find(table => table.id === to)?.columns ?? [])" :key="column.id"><option :value="column.id" x-text="column.name"></option></template>
                        </select>
                        <button type="button" x-show="pairs.length > 1" @click="pairs.splice(index, 1)">Remover par</button>
                    </div>
                </template>
                <p class="rel-structure-help">O destino deve conter uma chave PRIMARY ou UNIQUE completa. Para chaves compostas, adicione um par por coluna. A mesma tabela pode ser origem e destino.</p>
                <div class="rel-structure-row">
                    <button type="button" @click="pairs.push({ id: nextId++, from: '', to: '' })">Adicionar par de colunas</button>
                    <button type="submit" wire:loading.attr="disabled">Adicionar relacionamento</button>
                </div>
            </form>
        </div>
    </div>
</div>
