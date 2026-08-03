<script setup lang="ts">
const props = defineProps<{
  name: string
  mimeType?: string | null
}>()

type Kind =
  | 'pdf' | 'doc' | 'sheet' | 'slides' | 'text' | 'code'
  | 'image' | 'video' | 'audio' | 'archive' | 'other'

const BY_EXTENSION: Record<string, Kind> = {
  pdf: 'pdf',
  doc: 'doc', docx: 'doc', rtf: 'doc', odt: 'doc',
  xls: 'sheet', xlsx: 'sheet', ods: 'sheet', csv: 'sheet',
  ppt: 'slides', pptx: 'slides', odp: 'slides',
  txt: 'text', md: 'text',
  html: 'code', htm: 'code',
  png: 'image', jpg: 'image', jpeg: 'image', gif: 'image', webp: 'image', heic: 'image',
  mp4: 'video', webm: 'video', mov: 'video',
  mp3: 'audio', wav: 'audio', m4a: 'audio',
  zip: 'archive', '7z': 'archive', rar: 'archive',
}

/**
 * The extension decides, with the MIME type as a fallback — a file named
 * "report" with no extension should still show as a document rather than a
 * blank sheet.
 */
const kind = computed<Kind>(() => {
  const extension = props.name.split('.').pop()?.toLowerCase() ?? ''
  const byExtension = BY_EXTENSION[extension]

  if (byExtension) {
    return byExtension
  }

  const mime = (props.mimeType ?? '').toLowerCase()

  if (mime.startsWith('image/')) return 'image'
  if (mime.startsWith('video/')) return 'video'
  if (mime.startsWith('audio/')) return 'audio'
  if (mime === 'application/pdf') return 'pdf'
  if (mime.startsWith('text/')) return 'text'

  return 'other'
})

const LABELS: Record<Kind, string> = {
  pdf: 'PDF',
  doc: 'DOC',
  sheet: 'XLS',
  slides: 'PPT',
  text: 'TXT',
  code: 'HTML',
  image: 'IMG',
  video: 'MP4',
  audio: 'MP3',
  archive: 'ZIP',
  other: 'FILE',
}

/** The extension itself when it is short enough to read at this size. */
const label = computed(() => {
  const extension = props.name.split('.').pop()?.toUpperCase() ?? ''

  return extension.length >= 2 && extension.length <= 4 ? extension : LABELS[kind.value]
})
</script>

<template>
  <span class="icon" :class="`icon--${kind}`" role="img" :aria-label="`Файл ${label}`">
    <svg viewBox="0 0 32 40" aria-hidden="true">
      <path
        class="icon__sheet"
        d="M4 3.5A2.5 2.5 0 0 1 6.5 1H20l8 8v27.5a2.5 2.5 0 0 1-2.5 2.5h-19A2.5 2.5 0 0 1 4 36.5z"
      />
      <path class="icon__fold" d="M20 1l8 8h-6a2 2 0 0 1-2-2z" />
    </svg>
    <span class="icon__label">{{ label }}</span>
  </span>
</template>

<style scoped>
.icon {
  position: relative;
  display: inline-grid;
  place-items: center;
  width: 2rem;
  height: 2.5rem;
  flex-shrink: 0;
}

svg {
  width: 100%;
  height: 100%;
}

.icon__sheet {
  fill: var(--icon-bg, var(--color-surface-sunken));
  stroke: var(--icon-fg, var(--color-border-strong));
  stroke-width: 1;
}

.icon__fold {
  fill: var(--icon-fg, var(--color-border-strong));
  opacity: 0.35;
}

.icon__label {
  position: absolute;
  bottom: 0.45rem;
  color: var(--icon-fg, var(--color-text-muted));
  font-size: 0.5rem;
  font-weight: 700;
  letter-spacing: 0.02em;
}

/* One hue per family, so a glance is enough to tell them apart. */
.icon--pdf { --icon-fg: #c8372d; --icon-bg: #fdecea; }
.icon--doc { --icon-fg: #2b5cb8; --icon-bg: #e8effc; }
.icon--sheet { --icon-fg: #1c8a5a; --icon-bg: #e3f5ec; }
.icon--slides { --icon-fg: #c2600f; --icon-bg: #fdf1e3; }
.icon--text { --icon-fg: #5a6472; --icon-bg: #eef0f4; }
.icon--code { --icon-fg: #7b3fbf; --icon-bg: #f2ebfb; }
.icon--image { --icon-fg: #0f8ea3; --icon-bg: #e2f4f7; }
.icon--video { --icon-fg: #b3306e; --icon-bg: #fbe9f2; }
.icon--audio { --icon-fg: #6a5acd; --icon-bg: #eeecfb; }
.icon--archive { --icon-fg: #8a6a1f; --icon-bg: #faf1dc; }
.icon--other { --icon-fg: #6c7686; --icon-bg: #eef0f4; }

@media (prefers-color-scheme: dark) {
  .icon--pdf { --icon-fg: #f08076; --icon-bg: #2c1917; }
  .icon--doc { --icon-fg: #7fa6f7; --icon-bg: #1b2740; }
  .icon--sheet { --icon-fg: #57cf9c; --icon-bg: #14291f; }
  .icon--slides { --icon-fg: #e0a25f; --icon-bg: #2a2113; }
  .icon--text { --icon-fg: #9aa3b2; --icon-bg: #1d232d; }
  .icon--code { --icon-fg: #b992f0; --icon-bg: #241b33; }
  .icon--image { --icon-fg: #55c2d4; --icon-bg: #12272b; }
  .icon--video { --icon-fg: #e57fb0; --icon-bg: #2d1723; }
  .icon--audio { --icon-fg: #9b90e8; --icon-bg: #1e1b33; }
  .icon--archive { --icon-fg: #cfae63; --icon-bg: #2a2313; }
  .icon--other { --icon-fg: #8a92a0; --icon-bg: #1d232d; }
}
</style>