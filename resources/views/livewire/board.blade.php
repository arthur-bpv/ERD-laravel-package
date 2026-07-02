{{-- resources/views/livewire/flow-editor.blade.php --}}
<div>
    <x-flow :nodes="$nodes" :edges="$edges" style="height: 400px;">
        <x-slot:node>
            <x-flow-handle type="target" position="top" />
            <span x-text="node.data.label"></span>
            <x-flow-handle type="source" position="bottom" />
        </x-slot:node>
    </x-flow>
</div>