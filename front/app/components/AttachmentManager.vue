<script setup lang="ts">
import type { UploadOptions } from '~/utils/upload'
import type { DriveFile } from '~/composables/useGoogleDrive'
import type { LessonAttachment } from '~/types/lms'

/**
 * Список файлов при чём угодно: у урока и у новости он один и тот же.
 *
 * Куда ходить за данными, панель не знает — три действия приходят с вызова.
 * Так она не зависит ни от базы знаний, ни от новостей, и вторая копия этой
 * разметки не заводится ради того, что отличается только адресом запроса.
 */
const props = defineProps<{
  attachments: LessonAttachment[]
  uploadFile: (file: File, description: string | null, options: UploadOptions) => Promise<unknown>
  renameFile: (id: number, description: string | null) => Promise<unknown>
  removeFile: (id: number) => Promise<unknown>
  /**
   * Приложить файл, оставшийся жить на Google Диске.
   *
   * Необязательно: у новостей такого нет, и кнопки там не будет. Панель, как и
   * прежде, не знает, куда ходить, — действие приходит с вызова.
   */
  attachDriveFile?: (file: DriveFile) => Promise<unknown>
}>()

const emit = defineEmits<{ changed: [] }>()

const fileInput = useTemplateRef<HTMLInputElement>('fileInput')
const error = ref<string | null>(null)

const upload = useUploadProgress()

const drive = useGoogleDrive()

/** Кнопка есть, только когда ей есть куда вести и чем открыться. */
const canAttachFromDrive = computed(() => Boolean(props.attachDriveFile) && drive.isConfigured.value)

const isAttachingFromDrive = ref(false)

/** Раскрытый просмотр: один за раз — их и открывают по одному. */
const previewedId = ref<number | null>(null)

async function attachFromDrive() {
  const attach = props.attachDriveFile

  if (!attach) {
    return
  }

  error.value = null
  isAttachingFromDrive.value = true

  try {
    const chosen = await drive.pick()

    if (chosen.length === 0) {
      return
    }

    // По одному, а не разом: сервер отвечает на файл, и при отказе на третьем
    // первые два всё равно приложены — так и должно быть.
    for (const file of chosen) {
      await attach(file)
    }

    emit('changed')
  }
  catch (caught) {
    error.value = caught instanceof Error && caught.message
      ? caught.message
      : 'Не удалось приложить файл с Google Диска.'
  }
  finally {
    isAttachingFromDrive.value = false
  }
}

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

async function send() {
  const file = pendingFile.value

  if (!file) {
    return
  }

  error.value = null

  try {
    await upload.track(file, options =>
      props.uploadFile(file, pendingDescription.value || null, options))

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
    await props.renameFile(attachment.id, editingDescription.value || null)
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
    await props.removeFile(attachment.id)
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
        v-if="canAttachFromDrive"
        type="button"
        class="button-secondary button-sm"
        :disabled="isAttachingFromDrive"
        @click="attachFromDrive"
      >
        {{ isAttachingFromDrive ? 'Открываем Диск…' : 'С Google Диска' }}
      </button>

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
      <!-- Коротко: подробности про S3 и подписанные ссылки автору ни о чём не
           говорят — они о нашем устройстве, а не о его работе. Важно лишь то,
           что меняет его решения: файл с Диска остаётся у Google. -->
      Документы, таблицы, презентации, изображения, архивы и HTML.
      <template v-if="canAttachFromDrive">
        Файл с Google Диска остаётся у Google — увидят его те, кому он там открыт.
      </template>
    </p>

    <p v-if="error" class="alert alert--danger" role="alert">
      {{ error }}
    </p>

    <form v-if="pendingFile" class="card pending" @submit.prevent="send()">
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
            <template v-if="file.source === 'google_drive'">Google Диск</template>
            <template v-else>
              {{ formatBytes(file.size) }}
              <template v-if="!file.opens_inline"> · скачается файлом</template>
            </template>
          </span>

          <!-- Свёрнут по умолчанию: здесь список правят, а не читают, и десять
               открытых рамок подряд мешали бы этому. -->
          <DriveEmbed
            v-if="previewedId === file.id && file.embed_url"
            :src="file.embed_url"
            :title="file.name"
            :open-url="file.url"
          />
        </div>

        <div class="item__actions">
          <button
            v-if="file.embed_url"
            type="button"
            class="button-ghost button-sm"
            :aria-expanded="previewedId === file.id"
            @click="previewedId = previewedId === file.id ? null : file.id"
          >
            {{ previewedId === file.id ? 'Скрыть' : 'Показать' }}
          </button>
          <button type="button" class="button-ghost button-sm" @click="startEditing(file)">
            Подписать
          </button>
          <!-- Приглушённая, как соседние: красной кнопка в каждой строке
               списка кричит громче всего, что на странице есть, — а удаление
               здесь не главное действие, а последнее. Цвет она берёт под
               курсором, когда до неё и правда потянулись. -->
          <button type="button" class="button-ghost button-sm item__remove" @click="remove(file)">
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
/*
 * Раздел — карточка, а не кусок страницы, отчёркнутый линией.
 *
 * Внутри всё равно лежат карточки файлов, и волосяная черта вокруг них ничего
 * не держала: страница читалась сплошной лентой, где непонятно, что к чему
 * относится. Карточка отвечает на это одним видом.
 */
.attachments {
  margin-top: 1.25rem;
  padding: 1.35rem 1.5rem 1.5rem;
  border-radius: var(--radius-lg);
  background: var(--color-surface);
}

.attachments__head {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  flex-wrap: wrap;
}

.attachments__title {
  flex: 1;
  margin: 0;
  font-size: 1.05rem;
  font-weight: 550;
}

/* Подсказка не тянется во всю карточку: строка длиной в полсотни слов не
   читается, а проглядывается. */
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

/* Карточка файла лежит на карточке раздела, поэтому она темнее фоном, а не
   обведена: две рамки одна в другой дробят и без того плотный список. */
.pending,
.item {
  display: flex;
  align-items: flex-start;
  gap: 0.85rem;
  padding: 0.7rem 0.85rem;
  margin-bottom: 0.4rem;
  background: var(--color-surface-sunken);
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

.item__remove:hover {
  color: var(--color-danger);
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

/*
 * На телефоне кнопки уходят под имя файла, а не встают рядом с ним: вдвоём
 * они отбирали ровно ту ширину, ради которой имя и показывают, — от него
 * оставалось «документ-отгр…».
 */
@media (max-width: 34rem) {
  .item,
  .pending {
    flex-wrap: wrap;
  }

  .item__actions,
  .pending__actions {
    width: 100%;
    justify-content: flex-end;
  }

  .item__edit {
    flex-wrap: wrap;
  }

  .item__edit input {
    flex: 1 1 100%;
  }
}
</style>