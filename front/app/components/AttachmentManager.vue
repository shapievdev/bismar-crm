<script setup lang="ts">
import type { LessonAttachment } from '~/types/lms'

defineProps<{
  lessonId: number | string
  attachments: LessonAttachment[]
}>()

const emit = defineEmits<{ changed: [] }>()

const { uploadAttachment, updateAttachment, deleteAttachment } = useLmsApi()

const fileInput = useTemplateRef<HTMLInputElement>('fileInput')
const error = ref<string | null>(null)

const upload = useUploadProgress()

/** Chosen but not yet sent: the caption is written before the upload starts. */
const pendingFile = ref<File | null>(null)
const pendingDescription = ref('')

const editingId = ref<number | null>(null)
const editingDescription = ref('')

function chooseFile(event: Event) {
  const input = event.target as HTMLInputElement

  pendingFile.value = input.files?.[0] ?? null
  pendingDescription.value = ''
  error.value = null
  input.value = ''
}

function cancelPending() {
  pendingFile.value = null
  pendingDescription.value = ''
}

async function send(lessonId: number | string) {
  const file = pendingFile.value

  if (!file) {
    return
  }

  error.value = null

  try {
    await upload.track(file, options =>
      uploadAttachment(lessonId, file, pendingDescription.value || null, options))

    cancelPending()
    emit('changed')
  }
  catch (caught) {
    // Cancelling is a decision, not a failure: the file stays chosen so it can
    // be sent again without picking it a second time.
    if (caught instanceof UploadAbortedError) {
      return
    }

    const failure = caught as { data?: { message?: string, errors?: Record<string, string[]> } }
    error.value = failure.data?.errors?.file?.[0]
      ?? failure.data?.errors?.description?.[0]
      ?? failure.data?.message
      ?? 'Не удалось загрузить файл.'
  }
}

function startEditing(attachment: LessonAttachment) {
  editingId.value = attachment.id
  editingDescription.value = attachment.description ?? ''
}

async function saveDescription(attachment: LessonAttachment) {
  error.value = null

  try {
    await updateAttachment(attachment.id, editingDescription.value || null)
    editingId.value = null
    emit('changed')
  }
  catch {
    error.value = 'Не удалось сохранить подпись.'
  }
}

async function remove(attachment: LessonAttachment) {
  error.value = null

  try {
    await deleteAttachment(attachment.id)
    emit('changed')
  }
  catch {
    error.value = 'Не удалось удалить файл.'
  }
}

</script>

<template>
  <section class="attachments">
    <header class="attachments__head">
      <h2 class="attachments__title">
        Файлы
      </h2>

      <button
        type="button"
        class="button-secondary button-sm"
        :disabled="upload.isUploading.value"
        @click="fileInput?.click()"
      >
        Выбрать файл
      </button>

      <input ref="fileInput" type="file" class="visually-hidden" @change="chooseFile">
    </header>

    <p class="faint hint">
      Документы, таблицы, презентации, изображения, архивы и HTML. Хранятся в S3,
      ссылки подписанные и живут ограниченное время.
    </p>

    <p v-if="error" class="alert alert--danger" role="alert">
      {{ error }}
    </p>

    <form v-if="pendingFile" class="card pending" @submit.prevent="send(lessonId)">
      <UiFileIcon :name="pendingFile.name" :mime-type="pendingFile.type" />

      <div class="pending__body">
        <span class="pending__name">{{ pendingFile.name }}</span>

        <input
          v-if="!upload.isUploading.value"
          v-model.trim="pendingDescription"
          class="input"
          maxlength="500"
          placeholder="Что в этом файле? Например: скан подписанного договора"
        >

        <template v-else>
          <UiProgressBar
            :value="upload.percent.value"
            :indeterminate="upload.isStoring.value"
            :label="upload.label.value"
            size="sm"
          />
          <span v-if="upload.transferred.value" class="faint pending__transferred">
            {{ upload.transferred.value }}
          </span>
        </template>
      </div>

      <div class="pending__actions">
        <button
          v-if="upload.isUploading.value"
          type="button"
          class="button-ghost button-sm"
          :disabled="upload.isStoring.value"
          @click="upload.cancel"
        >
          Отменить
        </button>

        <template v-else>
          <button type="submit" class="button-primary button-sm">
            Загрузить
          </button>
          <button type="button" class="button-ghost button-sm" @click="cancelPending">
            Отмена
          </button>
        </template>
      </div>
    </form>

    <ul v-if="attachments.length" class="list">
      <li v-for="file in attachments" :key="file.id" class="card item">
        <UiFileIcon :name="file.name" :mime-type="file.mime_type" />

        <div class="item__body">
          <a
            :href="file.url"
            class="item__name"
            :target="file.opens_inline ? '_blank' : undefined"
            rel="noopener noreferrer"
          >
            {{ file.name }}
          </a>

          <form
            v-if="editingId === file.id"
            class="item__edit"
            @submit.prevent="saveDescription(file)"
          >
            <input v-model.trim="editingDescription" class="input" maxlength="500" placeholder="Подпись">
            <button type="submit" class="button-primary button-sm">
              Ок
            </button>
            <button type="button" class="button-ghost button-sm" @click="editingId = null">
              Отмена
            </button>
          </form>

          <p v-else-if="file.description" class="item__description">
            {{ file.description }}
          </p>

          <p v-else class="faint item__description item__description--missing">
            Без подписи — непонятно, что внутри
          </p>

          <span class="faint item__meta">
            {{ formatBytes(file.size) }}
            <template v-if="!file.opens_inline"> · скачается файлом</template>
          </span>
        </div>

        <div class="item__actions">
          <button type="button" class="button-ghost button-sm" @click="startEditing(file)">
            Подписать
          </button>
          <button type="button" class="button-danger button-sm" @click="remove(file)">
            Удалить
          </button>
        </div>
      </li>
    </ul>

    <p v-else-if="!pendingFile" class="faint">
      Файлов пока нет.
    </p>
  </section>
</template>

<style scoped>
.attachments {
  max-width: 46rem;
  margin-top: 2.5rem;
  padding-top: 1.5rem;
  border-top: 1px solid var(--color-border);
}

.attachments__head {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.attachments__title {
  flex: 1;
  margin: 0;
  font-size: 1.15rem;
  font-weight: 600;
}

.hint {
  margin: 0.4rem 0 0.9rem;
  font-size: 0.85rem;
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

.pending,
.item {
  display: flex;
  align-items: flex-start;
  gap: 0.85rem;
  padding: 0.85rem 1rem;
  margin-bottom: 0.6rem;
}

.pending__body,
.item__body {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-width: 0;
  gap: 0.3rem;
}

.pending__name,
.item__name {
  font-weight: 550;
  font-size: 0.94rem;
  overflow: hidden;
  text-overflow: ellipsis;
}

.item__name {
  color: inherit;
  text-decoration: none;
}

.item__name:hover {
  color: var(--color-accent);
  text-decoration: underline;
}

.pending__transferred {
  font-size: 0.82rem;
  font-variant-numeric: tabular-nums;
}

.pending__actions,
.item__actions {
  display: flex;
  gap: 0.35rem;
  flex-shrink: 0;
}

.item__edit {
  display: flex;
  gap: 0.35rem;
}

.item__description {
  margin: 0;
  font-size: 0.87rem;
  color: var(--color-text-muted);
}

.item__description--missing {
  font-style: italic;
}

.item__meta {
  font-size: 0.8rem;
}

.list {
  margin: 0;
  padding: 0;
  list-style: none;
}
</style>