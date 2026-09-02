<script setup lang="ts">
import { NodeViewWrapper, nodeViewProps } from '@tiptap/vue-3'

/**
 * Файл с Google Диска внутри статьи.
 *
 * Адрес собирается из хранимого номера, а не берётся из документа: даже если в
 * сохранённый текст подставить чужой адрес, в рамку он не попадёт. Номер, на
 * номер файла не похожий, оставляет вместо рамки честную строку — молча пустое
 * место было бы хуже.
 */
const props = defineProps(nodeViewProps)

const fileId = computed<string | null>(() => props.node.attrs.fileId ?? null)
const mimeType = computed<string | null>(() => props.node.attrs.mimeType ?? null)
const name = computed<string>(() => props.node.attrs.name || 'Файл с Google Диска')

const embedUrl = computed(() => driveEmbedUrl(fileId.value, mimeType.value))
const openUrl = computed(() => driveViewUrl(fileId.value, mimeType.value))
</script>

<template>
  <NodeViewWrapper class="drive-node" :class="{ 'drive-node--selected': selected }">
    <button
      v-if="editor.isEditable"
      type="button"
      class="button-danger button-sm drive-node__remove"
      contenteditable="false"
      @click="deleteNode()"
    >
      Удалить
    </button>

    <p class="drive-node__name">
      {{ name }}
    </p>

    <DriveEmbed
      v-if="embedUrl && openUrl"
      :src="embedUrl"
      :title="name"
      :open-url="openUrl"
    />

    <p v-else class="alert alert--danger">
      Этот файл с Google Диска не открыть: вставьте его заново.
    </p>
  </NodeViewWrapper>
</template>

<style scoped>
.drive-node {
  position: relative;
  margin: 1.25rem 0;
}

.drive-node--selected {
  outline: 2px solid var(--color-accent);
  outline-offset: 4px;
  border-radius: var(--radius);
}

.drive-node__remove {
  position: absolute;
  top: 0;
  right: 0;
  z-index: 1;
  background: var(--color-surface);
}

.drive-node__name {
  margin: 0;
  padding-right: 6rem;
  font-size: 0.92rem;
  font-weight: 550;
}
</style>
