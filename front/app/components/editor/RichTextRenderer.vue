<script setup lang="ts">
import { EditorContent, useEditor } from '@tiptap/vue-3'
import type { JSONContent } from '@tiptap/core'

const props = defineProps<{
  content: JSONContent | null
  /** Shown when the lesson predates the rich editor and holds plain text. */
  fallbackText?: string | null
}>()

/**
 * Read-only rendering runs through the same schema as the editor, so a reader
 * can only ever be shown node types the application defines. Nothing here
 * interprets stored markup as HTML — the one raw block renders inside its own
 * sandboxed iframe.
 */
const editor = useEditor({
  content: props.content ?? undefined,
  editable: false,
  extensions: useRichTextExtensions(),
  editorProps: {
    attributes: { class: 'prose-rendered' },
  },
})

watch(() => props.content, (value) => {
  if (editor.value && value) {
    editor.value.commands.setContent(value, { emitUpdate: false })
  }
})

onBeforeUnmount(() => editor.value?.destroy())

const paragraphs = computed(() =>
  (props.fallbackText ?? '').split(/\n{2,}/).map(block => block.trim()).filter(Boolean),
)
</script>

<template>
  <div v-if="content" class="rendered">
    <EditorContent :editor="editor" />
  </div>

  <div v-else-if="paragraphs.length" class="rendered rendered--plain">
    <p v-for="(paragraph, index) in paragraphs" :key="index">
      {{ paragraph }}
    </p>
  </div>
</template>

<style scoped>
.rendered--plain p {
  margin: 0 0 1.05rem;
  white-space: pre-wrap;
}
</style>