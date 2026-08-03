<script setup lang="ts">
import { NodeViewWrapper, nodeViewProps } from '@tiptap/vue-3'

const props = defineProps(nodeViewProps)

const src = computed<string>(() => props.node.attrs.src ?? '')
const isFile = computed(() => props.node.attrs.provider === 'file')

/**
 * External sources are rebuilt from an id extracted from a known host, so a
 * hostile URL cannot become an iframe source. An unrecognised link falls back
 * to a plain hyperlink.
 */
const embedUrl = computed(() => (isFile.value ? null : toEmbedUrl(src.value)))
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
      <video v-if="isFile" :src="src" controls preload="metadata" />

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