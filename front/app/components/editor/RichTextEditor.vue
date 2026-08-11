<script setup lang="ts">
import { EditorContent, useEditor } from '@tiptap/vue-3'
import type { JSONContent } from '@tiptap/core'
import type { UploadedMedia } from '~/utils/editor/attachments'

const props = defineProps<{
  /**
   * Stores a file as a lesson attachment and says which one it became. The
   * document keeps the id, not the address — signed URLs expire, ids do not.
   * The options are passed straight through so the editor can report progress
   * on a large video instead of freezing on "загружаем".
   */
  uploadImage?: (file: File, options: UploadOptions) => Promise<UploadedMedia>
  uploadVideo?: (file: File, options: UploadOptions) => Promise<UploadedMedia>
  placeholder?: string
}>()

const model = defineModel<JSONContent | null>({ required: true })

// ProseMirror needs a DOM, so this component is mounted inside <ClientOnly>.
// Rendering it during SSR produces an empty surface and a hydration mismatch.

const editor = useEditor({
  content: model.value ?? undefined,
  extensions: useRichTextExtensions({ placeholder: props.placeholder }),
  editorProps: {
    attributes: { class: 'prose-editor__surface' },
  },
  onUpdate: ({ editor }) => {
    model.value = editor.getJSON()
  },
})

// The parent may replace the document (a reload, a discarded draft); pushing it
// back in only when it differs keeps the caret from jumping on every keystroke.
watch(model, (value) => {
  if (!editor.value || !value) {
    return
  }

  if (JSON.stringify(editor.value.getJSON()) !== JSON.stringify(value)) {
    editor.value.commands.setContent(value, { emitUpdate: false })
  }
})

onBeforeUnmount(() => editor.value?.destroy())

const imageInput = useTemplateRef<HTMLInputElement>('imageInput')
const videoInput = useTemplateRef<HTMLInputElement>('videoInput')
const uploadError = ref<string | null>(null)
const upload = useUploadProgress()
const isUploading = upload.isUploading

async function onImageChosen(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''

  if (!file || !props.uploadImage) {
    return
  }

  uploadError.value = null

  try {
    const media = await upload.track(file, options => props.uploadImage!(file, options))

    editor.value?.chain().focus().insertContent({
      type: 'image',
      attrs: { src: media.url, alt: file.name, attachmentId: media.id },
    }).run()
  }
  catch (caught) {
    // Cancelling is a decision, not a failure: nothing goes into the document
    // and nothing is reported.
    if (caught instanceof UploadAbortedError) {
      return
    }

    uploadError.value = 'Не удалось загрузить изображение.'
  }
}

async function onVideoChosen(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''

  if (!file || !props.uploadVideo) {
    return
  }

  uploadError.value = null

  try {
    const media = await upload.track(file, options => props.uploadVideo!(file, options))

    editor.value?.chain().focus()
      .setVideoEmbed({ src: media.url, provider: 'file', attachmentId: media.id })
      .run()
  }
  catch (caught) {
    if (caught instanceof UploadAbortedError) {
      return
    }

    uploadError.value = 'Не удалось загрузить видео.'
  }
}

function promptLink() {
  const previous = editor.value?.getAttributes('link').href ?? ''
  const href = window.prompt('Адрес ссылки', previous)

  if (href === null) {
    return
  }

  if (href === '') {
    editor.value?.chain().focus().extendMarkRange('link').unsetLink().run()
    return
  }

  editor.value?.chain().focus().extendMarkRange('link').setLink({ href }).run()
}

function promptVideoUrl() {
  const src = window.prompt('Ссылка на YouTube или Vimeo')

  if (src) {
    editor.value?.chain().focus().setVideoEmbed({ src, provider: 'embed' }).run()
  }
}

function isActive(name: string, attrs?: Record<string, unknown>): boolean {
  return editor.value?.isActive(name, attrs) ?? false
}
</script>

<template>
  <div v-if="editor" class="prose-editor">
    <div class="toolbar">
      <div class="toolbar__group">
        <button type="button" class="tool" :class="{ 'tool--on': isActive('bold') }" title="Полужирный" @click="editor.chain().focus().toggleBold().run()">
          <strong>B</strong>
        </button>
        <button type="button" class="tool" :class="{ 'tool--on': isActive('italic') }" title="Курсив" @click="editor.chain().focus().toggleItalic().run()">
          <em>I</em>
        </button>
        <button type="button" class="tool" :class="{ 'tool--on': isActive('underline') }" title="Подчёркнутый" @click="editor.chain().focus().toggleUnderline().run()">
          <u>U</u>
        </button>
        <button type="button" class="tool" :class="{ 'tool--on': isActive('strike') }" title="Зачёркнутый" @click="editor.chain().focus().toggleStrike().run()">
          <s>S</s>
        </button>
        <button type="button" class="tool" :class="{ 'tool--on': isActive('code') }" title="Моноширинный" @click="editor.chain().focus().toggleCode().run()">
          &lt;/&gt;
        </button>
      </div>

      <div class="toolbar__group">
        <button
          v-for="level in [2, 3, 4]"
          :key="level"
          type="button"
          class="tool"
          :class="{ 'tool--on': isActive('heading', { level }) }"
          :title="`Заголовок ${level}`"
          @click="editor.chain().focus().toggleHeading({ level: level as 2 | 3 | 4 }).run()"
        >
          H{{ level }}
        </button>
        <button type="button" class="tool" :class="{ 'tool--on': isActive('paragraph') }" title="Обычный текст" @click="editor.chain().focus().setParagraph().run()">
          ¶
        </button>
      </div>

      <div class="toolbar__group">
        <button type="button" class="tool" :class="{ 'tool--on': isActive('bulletList') }" title="Маркированный список" @click="editor.chain().focus().toggleBulletList().run()">
          •
        </button>
        <button type="button" class="tool" :class="{ 'tool--on': isActive('orderedList') }" title="Нумерованный список" @click="editor.chain().focus().toggleOrderedList().run()">
          1.
        </button>
        <button type="button" class="tool" :class="{ 'tool--on': isActive('blockquote') }" title="Цитата" @click="editor.chain().focus().toggleBlockquote().run()">
          ❝
        </button>
        <button type="button" class="tool" :class="{ 'tool--on': isActive('codeBlock') }" title="Блок кода" @click="editor.chain().focus().toggleCodeBlock().run()">
          { }
        </button>
        <button type="button" class="tool" title="Разделитель" @click="editor.chain().focus().setHorizontalRule().run()">
          —
        </button>
      </div>

      <div class="toolbar__group">
        <button
          v-for="align in (['left', 'center', 'right'] as const)"
          :key="align"
          type="button"
          class="tool"
          :class="{ 'tool--on': isActive('paragraph', { textAlign: align }) || isActive('heading', { textAlign: align }) }"
          :title="`Выравнивание: ${align}`"
          @click="editor.chain().focus().setTextAlign(align).run()"
        >
          {{ align === 'left' ? '⇤' : align === 'center' ? '⇔' : '⇥' }}
        </button>
      </div>

      <div class="toolbar__group">
        <button type="button" class="tool" :class="{ 'tool--on': isActive('link') }" title="Ссылка" @click="promptLink">
          🔗
        </button>
        <button type="button" class="tool" title="Изображение" :disabled="isUploading" @click="imageInput?.click()">
          🖼
        </button>
        <button type="button" class="tool" title="Видеофайл" :disabled="isUploading" @click="videoInput?.click()">
          🎬
        </button>
        <button type="button" class="tool" title="Видео по ссылке" @click="promptVideoUrl">
          ▶
        </button>
      </div>

      <div class="toolbar__group">
        <button type="button" class="tool" title="Вставить таблицу" @click="editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run()">
          ▦
        </button>
        <template v-if="isActive('table')">
          <button type="button" class="tool" title="Столбец" @click="editor.chain().focus().addColumnAfter().run()">
            +▏
          </button>
          <button type="button" class="tool" title="Строка" @click="editor.chain().focus().addRowAfter().run()">
            +▁
          </button>
          <button type="button" class="tool" title="Удалить столбец" @click="editor.chain().focus().deleteColumn().run()">
            −▏
          </button>
          <button type="button" class="tool" title="Удалить строку" @click="editor.chain().focus().deleteRow().run()">
            −▁
          </button>
          <button type="button" class="tool" title="Удалить таблицу" @click="editor.chain().focus().deleteTable().run()">
            ▦✕
          </button>
        </template>
      </div>

      <div class="toolbar__group">
        <button type="button" class="tool tool--wide" title="Блок HTML" @click="editor.chain().focus().setHtmlBlock('<h1>Привет</h1>').run()">
          HTML
        </button>
      </div>

      <div class="toolbar__group toolbar__group--end">
        <button type="button" class="tool" title="Отменить" :disabled="!editor.can().undo()" @click="editor.chain().focus().undo().run()">
          ↺
        </button>
        <button type="button" class="tool" title="Повторить" :disabled="!editor.can().redo()" @click="editor.chain().focus().redo().run()">
          ↻
        </button>
      </div>
    </div>

    <p v-if="uploadError" class="alert alert--danger" role="alert">
      {{ uploadError }}
    </p>

    <input ref="imageInput" type="file" accept="image/png,image/jpeg,image/webp,image/gif" class="visually-hidden" @change="onImageChosen">
    <input ref="videoInput" type="file" accept="video/mp4,video/webm,video/quicktime" class="visually-hidden" @change="onVideoChosen">

    <EditorContent :editor="editor" class="prose-editor__body" />

    <div v-if="isUploading" class="prose-editor__upload">
      <div class="prose-editor__upload-head">
        <span class="prose-editor__upload-name">{{ upload.fileName.value }}</span>
        <button
          type="button"
          class="button-ghost button-sm"
          :disabled="upload.isStoring.value"
          @click="upload.cancel"
        >
          Отменить
        </button>
      </div>

      <UiProgressBar
        :value="upload.percent.value"
        :indeterminate="upload.isStoring.value"
        :label="upload.label.value"
        size="sm"
      />
    </div>
  </div>
</template>

<style scoped>
.prose-editor {
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius);
  background: var(--color-surface);
}

.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 0.15rem;
  padding: 0.4rem;
  border-bottom: 1px solid var(--color-border);
  background: var(--color-surface-sunken);
  border-radius: var(--radius) var(--radius) 0 0;
  position: sticky;
  top: var(--header-height);
  z-index: 2;
}

.toolbar__group {
  display: flex;
  gap: 0.1rem;
  padding-right: 0.35rem;
  margin-right: 0.35rem;
  border-right: 1px solid var(--color-border);
}

.toolbar__group:last-child,
.toolbar__group--end {
  border-right: 0;
  margin-right: 0;
  padding-right: 0;
}

.toolbar__group--end {
  margin-left: auto;
}

.tool {
  min-width: 1.9rem;
  height: 1.9rem;
  padding: 0 0.35rem;
  border: 1px solid transparent;
  border-radius: var(--radius-sm);
  background: transparent;
  color: var(--color-text-muted);
  font: inherit;
  font-size: 0.85rem;
  line-height: 1;
  cursor: pointer;
}

.tool--wide {
  min-width: 3rem;
  font-size: 0.72rem;
  font-weight: 600;
}

.tool:hover:not(:disabled) {
  background: var(--color-surface);
  color: var(--color-text);
}

.tool--on {
  background: var(--color-accent-soft);
  color: var(--color-accent);
}

.tool:disabled {
  opacity: 0.35;
  cursor: default;
}

.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

/* Sits under the writing surface rather than over it: an overlay would hide the
   paragraph the file is about to be dropped into. */
.prose-editor__upload {
  padding: 0.7rem 0.9rem 0.85rem;
  border-top: 1px solid var(--color-border);
}

.prose-editor__upload-head {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 0.5rem;
}

.prose-editor__upload-name {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.87rem;
}
</style>