<div x-show="$wire.showStructureEditor" x-cloak class="rel-modal" role="dialog" aria-modal="true" aria-labelledby="structure-title"
    @click.self="$wire.showStructureEditor = false" @keydown.escape.window="$wire.showStructureEditor = false">
    <div class="rel-modal-card rel-structure-editor">
        <header>
            <strong id="structure-title">Relationships</strong>
            <button type="button" wire:click="$set('showStructureEditor', false)" aria-label="Close relationships">✕</button>
        </header>
        <div class="rel-structure-body">
            <p class="rel-structure-help">Edit tables and columns on the diagram. Link foreign key columns here. Cardinality follows PRIMARY/UNIQUE keys and column nullability.</p>
            <h2>Relationships (foreign keys)</h2>
            @php($seenRelationships = [])
            @foreach ($foreignKeys as $key)
                @php($group = json_encode([$key['fromTable'], $key['toTable'], $key['relationshipName'] ?? $key['id']]))
                @continue(in_array($group, $seenRelationships, true))
                @php($seenRelationships[] = $group)
                <div wire:key="structure-relationship-{{ $key['id'] }}" class="rel-structure-item">
                    <p>{{ collect($tables)->firstWhere('id', $key['fromTable'])['name'] }} → {{ collect($tables)->firstWhere('id', $key['toTable'])['name'] }}</p>
                    <p class="rel-structure-help">{{ $key['cardinality'] ?? 'N:1' }} · {{ ($key['targetCard'] ?? '') === 'cf-zero-one' ? 'optional reference' : 'required reference' }}<br>
                        @foreach ($foreignKeys as $member)
                            @if ($member['id'] === $key['id'] || (!empty($key['relationshipName']) && $member['fromTable'] === $key['fromTable'] && $member['toTable'] === $key['toTable'] && ($member['relationshipName'] ?? '') === $key['relationshipName']))
                                {{ $member['fromColumn'] }} → {{ $member['toColumn'] }};
                            @endif
                        @endforeach
                    </p>
                    <form x-data="{ name: @js($key['relationshipName'] ?? '') }" @submit.prevent="$wire.renameRelationship(@js($key['id']), name)" class="rel-structure-row">
                        <input x-model="name" required maxlength="80" aria-label="Relationship name">
                        <button type="submit" wire:loading.attr="disabled">Rename relationship</button>
                        <button type="button" class="is-danger" wire:click="deleteRelationship(@js($key['id']))"
                            wire:confirm="Remove this relationship? Its columns will be kept." wire:loading.attr="disabled">Remove relationship</button>
                    </form>
                </div>
            @endforeach
            <form wire:key="new-relationship-form" x-data="{ name: '', from: '', to: '', pairs: [{ id: 0, from: '', to: '' }], nextId: 1 }"
                @submit.prevent="$wire.addRelationship(name, from, pairs.map(pair => pair.from), to, pairs.map(pair => pair.to))" class="rel-structure-item">
                <h3>New relationship</h3>
                <div class="rel-structure-row">
                    <input x-model="name" required maxlength="80" placeholder="Relationship name" aria-label="New relationship name">
                    <select x-model="from" required @change="pairs.forEach(pair => pair.from = '')" aria-label="FK source table">
                        <option value="">Source table (FK)</option>
                        @foreach ($tables as $table)<option value="{{ $table['id'] }}">{{ $table['name'] }}</option>@endforeach
                    </select>
                    <select x-model="to" required @change="pairs.forEach(pair => pair.to = '')" aria-label="Referenced table">
                        <option value="">Referenced table (PK/UNIQUE)</option>
                        @foreach ($tables as $table)<option value="{{ $table['id'] }}">{{ $table['name'] }}</option>@endforeach
                    </select>
                </div>
                <div wire:ignore>
                <template x-for="(pair, index) in pairs" :key="pair.id">
                    <div class="rel-structure-row">
                        <select x-model="pair.from" required aria-label="FK source column">
                            <option value="">Source column</option>
                            <template x-for="column in ($wire.tables.find(table => table.id === from)?.columns ?? [])" :key="column.id"><option :value="column.id" x-text="column.name"></option></template>
                        </select>
                        <span>→</span>
                        <select x-model="pair.to" required aria-label="Referenced column">
                            <option value="">Referenced column</option>
                            <template x-for="column in ($wire.tables.find(table => table.id === to)?.columns ?? [])" :key="column.id"><option :value="column.id" x-text="column.name"></option></template>
                        </select>
                        <button type="button" x-show="pairs.length > 1" @click="pairs.splice(index, 1)">Remove pair</button>
                    </div>
                </template>
                </div>
                <p class="rel-structure-help">The target must contain a complete PRIMARY or UNIQUE key. For composite keys, add one pair per column. Source and target can be the same table.</p>
                <div class="rel-structure-row">
                    <button type="button" @click="pairs.push({ id: nextId++, from: '', to: '' })">Add column pair</button>
                    <button type="submit" wire:loading.attr="disabled">Add relationship</button>
                </div>
            </form>
        </div>
    </div>
</div>
