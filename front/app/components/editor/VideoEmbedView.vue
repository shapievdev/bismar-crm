<script setup lang="ts">
import { NodeViewWrapper, nodeViewProps } from '@tiptap/vue-3'

const props = defineProps(nodeViewProps)

const src = computed<string>(() => props.node.attrs.src ?? '')

/** Загруженный файл: провайдер записан при вставке, а не угадывается сейчас. */
const isUpload = computed(() => props.node.attrs.provider === 'file')

/**
 * Чем показывать вставленную ссылку: рамкой провайдера или своим
 * проигрывателем.
 *
 * Адрес рамки собирается заново из разобранного номера, поэтому произвольная
 * ссылка источником iframe стать не может. Неузнанная остаётся ссылкой — как и
 * была. Провайдеров пять, плюс прямая ссылка на файл, см. resolveVideo.
 */
const resolved = computed(() => (isUpload.value ? null : resolveVideo(src.value)))

const embedUrl = computed(() => (resolved.value?.kind === 'embed' ? resolved.value.src : null))

/** Своим проигрывателем — и загруженное, и прямую ссылку на файл. */
const fileUrl = computed(() => {
  if (isUpload.value) {
    return src.value
  }

  return resolved.value?.kind === 'file' ? resolved.value.src : null
})
</script>

<template>
  <NodeViewWrapper class="video-embed" :class="{ 'video-embed--selected': selected }">
    <button
      v-if="editor.isEditable"
      type="button"
      class="button-danger button-sm video-embed__remove"
      contenteditable="false"
      @click="deleteNode()"
    >
      Удалить
    </button>

    <div class="video-embed__frame">
      <video v-if="fileUrl" :src="fileUrl" controls preload="metadata" />

      <iframe
        v-else-if="embedUrl"
        :src="embedUrl"
        title="Видео"
        loading="lazy"
        allowfullscreen
        referrerpolicy="strict-origin-when-cross-origin"
      />

      <p v-else class="video-embed__fallback">
        <a :href="src" target="_blank" rel="noopener noreferrer">{{ src }}</a>
      </p>
    </div>
  </NodeViewWrapper>
</template>

<style scoped>
.video-embed {
  position: relative;
  margin: 1.25rem 0;
}

.video-embed--selected .video-embed__frame {
  outline: 2px solid var(--color-accent);
  outline-offset: 2px;
}

.video-embed__remove {
  position: absolute;
  top: 0.5rem;
  right: 0.5rem;
  z-index: 1;
  background: var(--color-surface);
}

.video-embed__frame {
  aspect-ratio: 16 / 9;
  border-radius: var(--radius);
  overflow: hidden;
  background: #000;
}

.video-embed__frame iframe,
.video-embed__frame video {
  width: 100%;
  height: 100%;
  border: 0;
  display: block;
}

.video-embed__fallback {
  display: grid;
  place-items: center;
  height: 100%;
  margin: 0;
  padding: 1rem;
  background: var(--color-surface-sunken);
  word-break: break-all;
}
</style>