<div class="relative w-32 h-32">
    <x-flow-handle
        type="target"
        position="top"
    />

    <div
        class="absolute inset-0 border-2 border-gray-700 bg-white transform rotate-45"
    ></div>

    <div
        class="absolute inset-0 flex items-center justify-center text-center px-4"
    >
        <span class="transform -rotate-45 font-medium">
            {{ $node['data']['label'] ?? 'Decisão' }}
        </span>
    </div>

    <x-flow-handle
        type="source"
        position="bottom"
    />
</div>