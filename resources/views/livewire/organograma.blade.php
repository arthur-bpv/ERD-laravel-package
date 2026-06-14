<div style="height:100vh;display:flex;flex-direction:column;background:#030712;font-family:ui-sans-serif,system-ui,sans-serif;">

    {{-- Header --}}
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 24px;background:#0f172a;border-bottom:1px solid #1e293b;flex-shrink:0;">
        <div>
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:8px;height:8px;border-radius:50%;background:#3b82f6;box-shadow:0 0 8px #3b82f6;"></div>
                <h1 style="color:#f1f5f9;font-size:16px;font-weight:700;margin:0;">Organograma</h1>
            </div>
            <p style="color:#475569;font-size:12px;margin:2px 0 0 18px;">{{ count($nodes) }} colaboradores · {{ count($edges) }} conexões</p>
        </div>
        <div style="display:flex;gap:8px;">
            <button
                wire:click="fitView"
                style="display:flex;align-items:center;gap:6px;padding:7px 14px;background:#1e293b;border:1px solid #334155;border-radius:8px;color:#94a3b8;font-size:12px;cursor:pointer;transition:all 0.15s;"
                onmouseover="this.style.borderColor='#3b82f6';this.style.color='#3b82f6'"
                onmouseout="this.style.borderColor='#334155';this.style.color='#94a3b8'"
            >
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/>
                </svg>
                Fit View
            </button>
        </div>
    </div>

    {{-- Canvas + Panel --}}
    <div style="flex:1;display:flex;overflow:hidden;">

        {{-- Flow Canvas --}}
        <x-flow
            :nodes="$nodes"
            :edges="$edges"
            background="dots"
            :minimap="true"
            :controls="true"
            :fit-view-on-init="true"
            default-edge-type="smoothstep"
            style="flex:1;background:#030712;"
            @node-click="onNodeClick"
        >
            <x-slot:node>
                <div x-flow-handle:target.top></div>

                <div
                    style="background:#0f172a;border:1px solid #1e293b;border-radius:12px;padding:12px 14px;min-width:180px;cursor:pointer;transition:border-color 0.15s,box-shadow 0.15s;"
                    :style="node.selected ? 'border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,0.15),0 0 20px rgba(59,130,246,0.1)' : ''"
                >
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div
                            style="width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#6366f1);display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:12px;flex-shrink:0;"
                            x-text="node.data.avatar"
                        ></div>
                        <div style="min-width:0;">
                            <div style="color:#f1f5f9;font-weight:600;font-size:13px;line-height:1.3;white-space:nowrap;" x-text="node.data.label"></div>
                            <div style="color:#3b82f6;font-size:11px;font-weight:500;margin-top:1px;" x-text="node.data.role"></div>
                            <div style="color:#475569;font-size:10px;margin-top:1px;" x-text="node.data.dept"></div>
                        </div>
                    </div>
                </div>

                <div x-flow-handle:source.bottom></div>
            </x-slot:node>
        </x-flow>

        {{-- Side Panel --}}
        @if($selectedData)
        <div style="width:260px;background:#0f172a;border-left:1px solid #1e293b;display:flex;flex-direction:column;flex-shrink:0;overflow-y:auto;">
            {{-- Panel Header --}}
            <div style="display:flex;align-items:center;justify-content:space-between;padding:16px;border-bottom:1px solid #1e293b;">
                <span style="color:#475569;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;">Detalhes</span>
                <button
                    wire:click="closePanel"
                    style="color:#475569;background:none;border:none;cursor:pointer;padding:2px;border-radius:4px;"
                    onmouseover="this.style.color='#94a3b8'" onmouseout="this.style.color='#475569'"
                >
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            {{-- Profile --}}
            <div style="padding:24px 16px;display:flex;flex-direction:column;align-items:center;gap:12px;border-bottom:1px solid #1e293b;">
                <div style="width:60px;height:60px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#6366f1);display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:18px;box-shadow:0 0 20px rgba(59,130,246,0.3);">
                    {{ $selectedData['avatar'] }}
                </div>
                <div style="text-align:center;">
                    <div style="color:#f1f5f9;font-weight:700;font-size:15px;">{{ $selectedData['label'] }}</div>
                    <div style="color:#3b82f6;font-size:12px;font-weight:500;margin-top:3px;">{{ $selectedData['role'] }}</div>
                </div>
            </div>

            {{-- Info --}}
            <div style="padding:16px;display:flex;flex-direction:column;gap:14px;">
                <div>
                    <div style="color:#334155;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:4px;">Departamento</div>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <div style="width:6px;height:6px;border-radius:50%;background:#3b82f6;flex-shrink:0;"></div>
                        <span style="color:#cbd5e1;font-size:13px;">{{ $selectedData['dept'] }}</span>
                    </div>
                </div>
                <div>
                    <div style="color:#334155;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:4px;">Cargo</div>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <div style="width:6px;height:6px;border-radius:50%;background:#6366f1;flex-shrink:0;"></div>
                        <span style="color:#cbd5e1;font-size:13px;">{{ $selectedData['role'] }}</span>
                    </div>
                </div>
            </div>
        </div>
        @endif

    </div>
</div>
