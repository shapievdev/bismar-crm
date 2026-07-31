<script setup lang="ts">
import type { LessonAttachment } from '~/types/lms'

defineProps<{
  lessonId: number | string
  attachments: LessonAttachment[]
}>()

const emit = defineEmits<{ changed: [] }>()

const { uploadAttachment, deleteAttachment } = useLmsApi()

const fileInput = useTemplateRef<HTMLInputElement>('fileInput')
const isUploading = ref(false)
const error = ref<string | null>(null)

async function onFileChosen(event: Event, lessonId: number | string) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]

  if (!file) {
    return
  }

  isUploading.value = true
  error.value = null

  try {
    await uploadAttachment(lessonId, file)
    emit('changed')
  }
  catch (caught) {
    const failure = caught as { data?: { message?: string, errors?: Record<string, string[]> } }
    error.value = failure.data?.errors?.file?.[0] ?? failure.data?.message ?? 'Не удалось загрузить файл.'
  }
  finally {
    isUploading.value = false
    // Clear the input so the same file can be retried after a failure.
    input.value = ''
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

function formatSize(bytes: number): string {
  const mb = bytes / 1024 / 1024

  return mb >= 1 ? `${mb.toFixed(1)} МБ` : `${Math.max(1, Math.round(bytes / 1024))} КБ`
}
</script>

<template>
  <section class="attachments">
    <header class="attachments__header">
      <h2>Материалы</h2>

      <button
        type="button"
        class="button-plain"
        :disabled="isUploading"
        @click="fileInput?.click()"
      >
        {{ isUploading ? 'Загружаем…' : 'Загрузить файл' }}
      </button>

      <input
        ref="fileInput"
        type="file"
        class="visually-hidden"
        @change="onFileChosen($event, lessonId)"
      >
    </header>

    <p class="muted">
      Файлы хранятся в S3, ссылки подписанные и действуют ограниченное время.
    </p>

    <p v-if="error" class="auth-alert" role="alert">
      {{ error }}
    </p>

    <ul v-if="attachments.length" class="list">
      <li v-for="file in attachments" :key="file.id">
        <a :href="file.url" target="_blank" rel="noopener noreferrer">{{ file.name }}</a>
        <span class="muted">{{ formatSize(file.size) }}</span>
        <button type="button" class="danger" @click="remove(file)">
          Удалить
        </button>
      </li>
    </ul>

    <p v-else class="muted">
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

.attachments__header {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.attachments__header h2 {
  flex: 1;
  margin: 0;
  font-size: 1.2rem;
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

.muted {
  margin: 0.4rem 0;
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.list {
  margin: 0.75rem 0 0;
  padding: 0;
  list-style: none;
}

.list li {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.45rem 0;
  border-top: 1px solid var(--color-border);
}

.list li a {
  flex: 1;
}

.button-plain,
.danger {
  padding: 0.4rem 0.85rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface);
  color: var(--color-text);
  font: inherit;
  font-size: 0.9rem;
  cursor: pointer;
}

.danger {
  color: var(--color-danger);
  border-color: var(--color-danger);
}

.button-plain:disabled {
  opacity: 0.6;
  cursor: default;
}
</style>
