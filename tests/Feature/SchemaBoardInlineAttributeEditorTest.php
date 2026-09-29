<?php

namespace Tests\Feature;

use Tests\TestCase;

class SchemaBoardInlineAttributeEditorTest extends TestCase
{
    public function test_entity_attribute_name_is_rendered_as_a_simple_inline_text_input(): void
    {
        $editor = $this->entityAttributeEditorMarkup();

        $this->assertMatchesRegularExpression('/<input[\s\S]*class="er-attr-name-input nodrag"/', $editor);
        $this->assertStringContainsString('x-model="draft"', $editor);
        $this->assertStringContainsString('$wire.renameAttribute(node.id, attr.id, draft.trim())', $editor);
    }

    public function test_entity_attribute_editor_does_not_use_prompt_or_double_click(): void
    {
        $editor = $this->entityAttributeEditorMarkup();

        $this->assertStringNotContainsString('window.prompt', $editor);
        $this->assertStringNotContainsString('@dblclick', $editor);
    }

    private function entityAttributeEditorMarkup(): string
    {
        $blade = file_get_contents(resource_path('views/livewire/schema-board.blade.php'));

        preg_match(
            '/{{-- nome do atributo --}}(?<editor>[\s\S]*?){{-- excluir atributo --}}/',
            $blade,
            $matches,
        );

        $this->assertArrayHasKey('editor', $matches);

        return $matches['editor'];
    }
}
