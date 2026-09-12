<script setup lang="ts">
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'
import type { MaterialSection, MaterialVersion, QuizPayload } from '~/types/lms'
import type { Group } from '~/types/structure'
import { type UploadedMedia, withoutResolvedMedia } from '~/utils/editor/attachments'
import type { UploadOptions } from '~/utils/upload'

/**
 * Правка одной версии материала — один экран на оба раздела.
 *
 * У версии своё тело: статья, файлы и проверка. Всё остальное — заголовок,
 * категория, слова поиска, ответственные, соседи, допуск — общее на документ и
 * правится там же, где правился всегда.
 *
 * Отдельным экраном, а не вкладкой в редакторе документа: это полноценное
 * тело со своим редактором статьи, своим списком файлов и своим конструктором
 * вопросов, и втискивать второй такой же набор в уже плотный экран значило бы
 * запутать, какой текст сейчас правят.
 */
const props = defineProps<{ section: MaterialSection }>()

const copy = useMaterialSection(props.section)

const route = useRoute()
const router = useRouter()

const slug = computed(() => String(route.params.slug))
const versionId = computed(() => Number(route.params.version))

const {
  fetchRegulation,
  fetchVersion,
  updateVersion,
  saveVersionQuiz,
  deleteVersionQuiz,
  uploadVersionAttachment,
  updateAttachment,
  deleteAttachment,
  attachVersionDriveFile,
} = useMaterialsApi(props.section)

const { fetchGroups } = useGroupsApi()

const { data, error, refresh } = await useAsyncData(
  () => `lms.${props.section}.version.${versionId.value}`,
  async () => {
    const [material, version, groups] = await Promise.all([
      fetchRegulation(slug.value),
      fetchVersion(slug.value, versionId.value),
      fetchGroups(),
    ])

    return { material: material.data, version: version.data, groups: groups.data }
  },
)

if (error.value) {
  throw createError({ statusCode: 404, statusMessage: 'Версия не найдена', fatal: true })
}

const material = computed(() => data.value?.material ?? null)
const version = computed<MaterialVersion | null>(() => data.value?.version ?? null)
const groups = computed<Group[]>(() => data.value?.groups ?? [])

useHead({ title: () => version.value ? `${version.value.name} — версия` : 'Версия' })

/* ---------- Название, круг групп, закрытость ---------- */

const form = reactive({
  name: '',
  is_private: false,
  groups: [] as number[],
})

// Заполняется один раз на версию, а не при каждом перечитывании записи:
// перечитывают её отсюда и после загрузки файла, и после правки проверки, —
// и набранное название возвращалось бы к сохранённому от любого из них.
watch(() => version.value?.id, () => {
  const value = version.value

  if (!value) {
    return
  }

  form.name = value.name
  form.is_private = value.is_private
  form.groups = (value.groups ?? []).map(group => group.id)
}, { immediate: true })

/**
 * Статья версии. Адреса вложенных картинок живут час, а текст — годы: версия
 * хранит номера, а адрес подставляется на пути к редактору. Написанное
 * переживает перечитывание записи — см. useArticleDocument.
 */
const { document, isDirty: hasArticleEdits } = useArticleDocument(version, value => ({
  content: value.content_json ?? null,
  attachments: value.attachments ?? [],
}))

const isDirty = computed(() => {
  const saved = version.value

  if (!saved) {
    return false
  }

  return hasArticleEdits.value
    || form.name !== saved.name
    || form.is_private !== saved.is_private
    || form.groups.join(',') !== (saved.groups ?? []).map(group => group.id).join(',')
})

const errors = ref<ValidationErrors>({})
const generalError = ref<string | null>(null)
const isSaving = ref(false)

async function save() {
  isSaving.value = true
  errors.value = {}
  generalError.value = null

  try {
    await updateVersion(slug.value, versionId.value, {
      name: form.name,
      is_private: form.is_private,
      groups: form.groups,
      content_json: withoutResolvedMedia(document.value),
    })

    await router.push(`/lms/${copy.section}/${slug.value}/edit`)
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      errors.value = caught.errors
    }
    else {
      generalError.value = 'Не удалось сохранить версию.'
    }
  }
  finally {
    isSaving.value = false
  }
}

function toggleGroup(id: number) {
  form.groups = form.groups.includes(id)
    ? form.groups.filter(one => one !== id)
    : [...form.groups, id]
}

/* ---------- Проверка при версии ---------- */

const quizErrors = ref<ValidationErrors>({})
const isSavingQuiz = ref(false)
const showQuizBuilder = ref(false)

watch(() => version.value?.id, () => showQuizBuilder.value = Boolean(version.value?.quiz), { immediate: true })

async function persistQuiz(payload: QuizPayload) {
  isSavingQuiz.value = true
  quizErrors.value = {}

  try {
    await saveVersionQuiz(slug.value, versionId.value, payload)
    await refresh()
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      quizErrors.value = caught.errors
    }
    else {
      generalError.value = 'Не удалось сохранить проверку.'
    }
  }
  finally {
    isSavingQuiz.value = false
  }
}

async function dropQuiz() {
  isSavingQuiz.value = true

  try {
    await deleteVersionQuiz(slug.value, versionId.value)
    showQuizBuilder.value = false
    await refresh()
  }
  catch {
    generalError.value = 'Не удалось удалить проверку.'
  }
  finally {
    isSavingQuiz.value = false
  }
}

/**
 * Вставленное в статью хранится обычным вложением этой же версии: так у
 * картинки есть номер, переживающий подписанную ссылку, и уходит она вместе с
 * версией.
 */
async function uploadInline(file: File, options: UploadOptions, label: string): Promise<UploadedMedia> {
  const { data: attachment } = await uploadVersionAttachment(slug.value, versionId.value, file, label, options)

  void refresh()

  return { id: attachment.id, url: attachment.url }
}
</script>

<template>
  <section v-if="version && material" class="version-editor">
    <header class="page-header">
      <div>
        <p class="faint">
          <NuxtLink :to="`/lms/${copy.section}/${slug}/edit`">
            ← {{ material.title }}
          </NuxtLink>
        </p>
        <h1 class="page-title">
          Версия «{{ version.name }}»
        </h1>
        <p class="page-subtitle">
          Своя статья, свои файлы и своя проверка. Заголовок, категория,
          ответственные и допуск — общие на весь {{ copy.materialLabel.toLowerCase() }}.
        </p>
      </div>
    </header>

    <p v-if="generalError" class="alert alert--danger" role="alert">
      {{ generalError }}
    </p>

    <section class="card editor-panel">
      <div class="field">
        <label class="field-label" for="version-name">Название версии</label>
        <input id="version-name" v-model.trim="form.name" class="input" maxlength="120">
        <p v-if="errors.name?.length" class="field-error">
          {{ errors.name[0] }}
        </p>
      </div>

      <div class="field">
        <span class="field-label">Для кого</span>
        <p class="faint field-hint">
          Кому эти группы подошли — тот открывает эту версию первой.
        </p>
        <ul v-if="groups.length" class="picker">
          <li v-for="group in groups" :key="group.id">
            <label class="choice">
              <input
                type="checkbox"
                :checked="form.groups.includes(group.id)"
                @change="toggleGroup(group.id)"
              >
              {{ group.name }}
              <span class="faint">· {{ group.people_count }}</span>
            </label>
          </li>
        </ul>
        <p v-else class="faint">
          Групп пока нет — заведите их в разделе «Сотрудники».
        </p>
        <p v-if="errors.groups?.length" class="field-error">
          {{ errors.groups[0] }}
        </p>
      </div>

      <!-- Закрытая видна только своим группам; у открытой группы решают лишь
           то, кому она откроется первой. -->
      <label class="choice">
        <input v-model="form.is_private" type="checkbox">
        Закрытая — видна только выбранным группам
      </label>

      <div class="field">
        <span class="field-label">Статья версии</span>
        <ClientOnly>
          <EditorRichTextEditor
            v-model="document"
            :placeholder="copy.articlePlaceholder"
            :upload-image="(file, options) => uploadInline(file, options, 'Изображение в версии')"
            :upload-video="(file, options) => uploadInline(file, options, 'Видео в версии')"
          />
        </ClientOnly>
      </div>
    </section>

    <!-- Файлы версии: свой бланк расчёта у каждой. Правка подписи и удаление
         идут общими адресами документа — файл при нём и лежит. -->
    <AttachmentManager
      :attachments="version.attachments ?? []"
      :upload-file="(file, description, options) => uploadVersionAttachment(slug, versionId, file, description, options)"
      :rename-file="(id, description) => updateAttachment(slug, id, description)"
      :remove-file="(id) => deleteAttachment(slug, id)"
      :attach-drive-file="(file) => attachVersionDriveFile(slug, versionId, file)"
      @changed="refresh"
    />

    <section v-if="!showQuizBuilder" class="card editor-panel add-quiz">
      <div>
        <h2 class="editor-panel__title">
          Проверка версии
        </h2>
        <p class="faint">
          Своя у каждой версии. Сдал — {{ copy.materialLabel.toLowerCase() }}
          зачтётся прочитанным: отметка одна на человека, и версия остаётся на
          ней пометкой.
        </p>
      </div>

      <button type="button" class="button-secondary" @click="showQuizBuilder = true">
        Добавить проверку
      </button>
    </section>

    <QuizBuilder
      v-else
      :quiz="version.quiz ?? null"
      :errors="quizErrors"
      :is-submitting="isSavingQuiz"
      :fixed-passing-score="100"
      @save="persistQuiz"
      @remove="dropQuiz"
    />

    <div class="actions">
      <button type="button" class="button-primary" :disabled="isSaving" @click="save">
        {{ isSaving ? 'Сохраняем…' : 'Сохранить версию' }}
      </button>
      <span v-if="isDirty" class="faint">Есть несохранённые правки</span>
    </div>
  </section>
</template>

<style scoped>
.version-editor {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.page-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
}

.page-header a {
  text-decoration: none;
}

/*
 * Панель и поля — свои, а не занятые у редактора документа: его стили
 * скоуплены, и снаружи от них остаётся одна разметка без отступов.
 */
.editor-panel {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 1.1rem 1.25rem;
  align-items: flex-start;
}

.editor-panel__title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 500;
}

.field {
  width: 100%;
}

.choice {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.9rem;
}

.field-hint {
  margin: 0 0 0.35rem;
  font-size: 0.85rem;
}

.picker {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem 1rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.add-quiz {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
}

.actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}
</style>
