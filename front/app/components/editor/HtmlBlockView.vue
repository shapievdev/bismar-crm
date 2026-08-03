<script setup lang="ts">
import { NodeViewWrapper, nodeViewProps } from '@tiptap/vue-3'

const props = defineProps(nodeViewProps)

const isEditing = ref(false)
const draft = ref<string>(props.node.attrs.html ?? '')

const html = computed<string>(() => props.node.attrs.html ?? '')
const height = computed<number>(() => Number(props.node.attrs.height) || 320)

/**
 * The block renders in a sandboxed iframe with no `allow-same-origin`, so the
 * markup gets a unique opaque origin: its scripts cannot read this page, our
 * cookies, or anything in the storage bucket. That isolation is what makes it
 * safe to render author-supplied HTML at all — the alternative is to serve it
 * from the storage domain, where a script would sit alongside every uploaded
 * file. `allow-scripts` is granted so the block can actually do something.
 */
const SANDBOX = 'allow-scripts allow-popups allow-forms allow-modals'

function save() {
  props.updateAttributes({ html: draft.value })
  isEditing.value = false
}

function cancel() {
  draft.value = html.value
  isEditing.value = false
}

function resize(delta: number) {
  props.updateAttributes({ height: Math.min(1200, Math.max(120, height.value + delta)) })
}
</script>

<template>
  <NodeViewWrapper class="html-block" :class="{ 'html-block--selected': selected }">
    <header v-if="editor.isEditable" class="html-block__bar" contenteditable="false">
      <span class="badge badge--accent">HTML</span>
      <span class="faint html-block__note">Выполняется изолированно, без доступа к странице</span>

      <div class="html-block__actions">
        <button type="button" class="button-ghost button-sm" @click="resize(-80)">
          −
        </button>
        <button type="button" class="button-ghost button-sm" @click="resize(80)">
          +
        </button>
        <button type="button" class="button-secondary button-sm" @click="isEditing = !isEditing">
          {{ isEditing ? 'Просмотр' : 'Код' }}
        </button>
        <button type="button" class="button-danger button-sm" @click="deleteNode()">
          Удалить
        </button>
      </div>
    </header>

    <div v-if="isEditing" class="html-block__editor" contenteditable="false">
      <textarea
        v-model="draft"
        class="textarea html-block__code"
        spellcheck="false"
        placeholder="<div>Ваша разметка…</div>"
      />
      <div class="html-block__editor-actions">
        <button type="button" class="button-primary button-sm" @click="save">
          Применить
        </button>
        <button type="button" class="button-ghost button-sm" @click="cancel">
          Отмена
        </button>
      </div>
    </div>

    <iframe
      v-else-if="html"
      class="html-block__frame"
      :style="{ height: `${height}px` }"
      :sandbox="SANDBOX"
      :srcdoc="html"
      title="Встроенный HTML"
      loading="lazy"
    />

    <p v-else class="html-block__empty faint">
      Пустой HTML-блок — нажмите «Код», чтобы вставить разметку.
    </p>
  </NodeViewWrapper>
</template>

<style scoped>
.html-block {
  margin: 1.25rem 0;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  overflow: hidden;
}

.html-block--selected {
  border-color: var(--color-accent);
}

.html-block__bar {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.45rem 0.7rem;
  background: var(--color-surface-sunken);
  border-bottom: 1px solid var(--color-border);
}

.html-block__note {
  flex: 1;
  font-size: 0.78rem;
}

.html-block__actions {
  display: flex;
  gap: 0.3rem;
}

.html-block__editor {
  padding: 0.7rem;
}

.html-block__code {
  width: 100%;
  min-height: 12rem;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.85rem;
}

.html-block__editor-actions {
  display: flex;
  gap: 0.4rem;
  margin-top: 0.5rem;
}

.html-block__frame {
  display: block;
  width: 100%;
  border: 0;
  background: #fff;
}

.html-block__empty {
  margin: 0;
  padding: 1.5rem;
  text-align: center;
  font-size: 0.88rem;
}
</style>