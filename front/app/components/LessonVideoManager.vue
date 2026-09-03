<script setup lang="ts">
import type { LessonSummary } from '~/types/lms'

defineProps<{
  lessonId: number | string
  lesson: LessonSummary
}>()

const emit = defineEmits<{ changed: [] }>()

const { uploadVideo, deleteVideo } = useLmsApi()

const fileInput = useTemplateRef<HTMLInputElement>('fileInput')
const error = ref<string | null>(null)

const upload = useUploadProgress()

async function onFileChosen(event: Event, lessonId: number | string) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]

  if (!file) {
    return
  }

  error.value = null

  try {
    await upload.track(file, options => uploadVideo(lessonId, file, options))
    emit('changed')
  }
  catch (caught) {
    // Cancelling is a decision, not a failure: nothing to report.
    if (caught instanceof UploadAbortedError) {
      return
    }

    const failure = caught as { data?: { message?: string, errors?: Record<string, string[]> } }
    error.value = failure.data?.errors?.video?.[0]
      ?? failure.data?.message
      ?? 'Не удалось загрузить видео.'
  }
  finally {
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
        :disabled="upload.isUploading.value"
        @click="fileInput?.click()"
      >
        {{ lesson.video_upload_url ? 'Заменить' : 'Загрузить видео' }}
      </button>

      <button
        v-if="lesson.video_upload_url && !upload.isUploading.value"
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

    <div v-if="upload.isUploading.value" class="card upload">
      <div class="upload__head">
        <span class="upload__name">{{ upload.fileName.value }}</span>
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
      />

      <p v-if="upload.transferred.value" class="faint upload__transferred">
        {{ upload.transferred.value }}
      </p>
    </div>

    <div v-else-if="lesson.video_upload_url" class="preview">
      <video :src="lesson.video_upload_url" controls preload="metadata" />
      <p class="faint">
        {{ lesson.video_name }} · {{ formatBytes(lesson.video_size) }}
      </p>
    </div>

    <p v-else class="faint">
      Видео не загружено.
    </p>
  </section>
</template>

<style scoped>
/* Раздел — карточка, как и файлы с расшифровками: страница редактора читается
   стопкой блоков, а не сплошной лентой с отчёркиваниями. */
.video-manager {
  margin-top: 1.25rem;
  padding: 1.35rem 1.5rem 1.5rem;
  border-radius: var(--radius-lg);
  background: var(--color-surface);
}

.video-manager__head {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  flex-wrap: wrap;
}

.video-manager__title {
  flex: 1;
  margin: 0;
  font-size: 1.05rem;
  font-weight: 550;
}

.hint {
  max-width: 62ch;
  margin: 0.45rem 0 1rem;
  font-size: 0.82rem;
  line-height: 1.5;
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

.upload {
  padding: 0.9rem 1rem;
  max-width: 32rem;
}

.upload__head {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 0.7rem;
}

.upload__name {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.92rem;
  font-weight: 550;
}

.upload__transferred {
  margin: 0.5rem 0 0;
  font-size: 0.82rem;
  font-variant-numeric: tabular-nums;
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