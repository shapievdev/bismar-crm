<script setup lang="ts">
import type { LessonSummary } from '~/types/lms'

defineProps<{
  lessonId: number | string
  lesson: LessonSummary
}>()

const emit = defineEmits<{ changed: [] }>()

const { uploadVideo, deleteVideo } = useLmsApi()

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
    await uploadVideo(lessonId, file)
    emit('changed')
  }
  catch (caught) {
    const failure = caught as { data?: { message?: string, errors?: Record<string, string[]> } }
    error.value = failure.data?.errors?.video?.[0]
      ?? failure.data?.message
      ?? 'Не удалось загрузить видео.'
  }
  finally {
    isUploading.value = false
    // Clear the input so the same file can be retried after a failure.
    input.value = ''
  }
}

async function remove(lessonId: number | string) {
  error.value = null

  try {
    await deleteVideo(lessonId)
    emit('changed')
  }
  catch {
    error.value = 'Не удалось удалить видео.'
  }
}

function formatSize(bytes: number | null | undefined): string {
  if (!bytes) {
    return ''
  }

  const mb = bytes / 1024 / 1024

  return mb >= 1 ? `${mb.toFixed(1)} МБ` : `${Math.max(1, Math.round(bytes / 1024))} КБ`
}
</script>

<template>
  <section class="video-manager">
    <header class="video-manager__head">
      <h2 class="video-manager__title">
        Видео
      </h2>

      <button
        type="button"
        class="button-secondary button-sm"
        :disabled="isUploading"
        @click="fileInput?.click()"
      >
        {{ isUploading ? 'Загружаем…' : (lesson.video_upload_url ? 'Заменить' : 'Загрузить видео') }}
      </button>

      <button
        v-if="lesson.video_upload_url"
        type="button"
        class="button-ghost button-sm"
        @click="remove(lessonId)"
      >
        Удалить
      </button>

      <input
        ref="fileInput"
        type="file"
        accept="video/mp4,video/webm,video/quicktime"
        class="visually-hidden"
        @change="onFileChosen($event, lessonId)"
      >
    </header>

    <p class="faint hint">
      MP4, WebM или MOV. Файл кладётся в S3, читатель получает подписанную ссылку.
      Ссылку на YouTube или Vimeo можно указать отдельно в поле выше — тогда видео встроится оттуда.
    </p>

    <p v-if="error" class="alert alert--danger" role="alert">
      {{ error }}
    </p>

    <div v-if="lesson.video_upload_url" class="preview">
      <video :src="lesson.video_upload_url" controls preload="metadata" />
      <p class="faint">
        {{ lesson.video_name }} · {{ formatSize(lesson.video_size) }}
      </p>
    </div>

    <p v-else class="faint">
      Видео не загружено.
    </p>
  </section>
</template>

<style scoped>
.video-manager {
  max-width: 46rem;
  margin-top: 2.5rem;
  padding-top: 1.5rem;
  border-top: 1px solid var(--color-border);
}

.video-manager__head {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.video-manager__title {
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

.preview video {
  width: 100%;
  max-width: 32rem;
  border-radius: var(--radius);
  background: #000;
  display: block;
}

.preview p {
  margin: 0.4rem 0 0;
  font-size: 0.85rem;
}
</style>