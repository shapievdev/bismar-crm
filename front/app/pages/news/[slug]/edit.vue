<script setup lang="ts">
import type { JSONContent } from '@tiptap/core'
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'
import type { UploadOptions } from '~/utils/upload'
import type { QuizPayload } from '~/types/lms'
import type { NewsAudienceKind, NewsPerson } from '~/types/news'
import { type UploadedMedia, withResolvedMedia, withoutResolvedMedia } from '~/utils/editor/attachments'

definePageMeta({ middleware: 'auth', permission: 'news.manage' })

const route = useRoute()
const slug = computed(() => String(route.params.slug))

const {
  fetchNews,
  updateNews,
  deleteNews,
  uploadAttachment,
  updateAttachment,
  deleteAttachment,
  saveQuiz,
  deleteQuiz,
  searchPeople,
} = useNewsApi()

const router = useRouter()

const { data, error, refresh } = await useAsyncData(
  () => `news.edit.${slug.value}`,
  () => fetchNews(slug.value),
)

if (error.value) {
  throw createError({ statusCode: 404, statusMessage: 'Новость не найдена', fatal: true })
}

const news = computed(() => data.value?.data ?? null)

useHead({ title: () => news.value ? `${news.value.title} — правка` : 'Правка новости' })

/* ---------- Сама новость ---------- */

const form = reactive({
  title: '',
  excerpt: '',
  status: 'draft' as 'draft' | 'published',
  is_pinned: false,
  audience: 'everyone' as NewsAudienceKind,
  requires_acknowledgement: false,
})

const document = ref<JSONContent | null>(null)
const recipients = ref<NewsPerson[]>([])

watch(news, (value) => {
  if (!value) {
    return
  }

  form.title = value.title
  form.excerpt = value.excerpt ?? ''
  form.status = value.status
  form.is_pinned = value.is_pinned
  form.audience = value.audience
  form.requires_acknowledgement = value.requires_acknowledgement

  // Адреса вложенных картинок и видео живут час, а статья — годы: документ
  // хранит номера, и адрес подставляется на пути к редактору.
  document.value = withResolvedMedia(value.content_json ?? null, value.attachments ?? [])
  recipients.value = value.recipients ?? []
}, { immediate: true })

const errors = ref<ValidationErrors>({})
const generalError = ref<string | null>(null)
const isSaving = ref(false)
const savedAt = ref<string | null>(null)

async function save() {
  isSaving.value = true
  errors.value = {}
  generalError.value = null
  savedAt.value = null

  try {
    await updateNews(slug.value, {
      title: form.title,
      excerpt: form.excerpt || null,
      content_json: withoutResolvedMedia(document.value),
      status: form.status,
      is_pinned: form.is_pinned,
      audience: form.audience,
      requires_acknowledgement: form.requires_acknowledgement,
      recipients: recipients.value.map(person => person.id),
    })

    savedAt.value = new Date().toLocaleTimeString('ru-RU')
    await refresh()
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      errors.value = caught.errors
    }
    else {
      generalError.value = 'Не удалось сохранить новость.'
    }
  }
  finally {
    isSaving.value = false
  }
}

async function remove() {
  await deleteNews(slug.value)
  await router.push('/news')
}

/* ---------- Файлы и медиа в статье ---------- */

/**
 * Вставленное в статью хранится обычным вложением новости: так у картинки есть
 * номер, переживающий подписанную ссылку, и уходит она вместе с новостью.
 */
async function uploadInline(file: File, options: UploadOptions, label: string): Promise<UploadedMedia> {
  const { data: attachment } = await uploadAttachment(slug.value, file, label, options)
  await refresh()

  return { id: attachment.id, url: attachment.url }
}

/* ---------- Проверка ---------- */

const quizErrors = ref<ValidationErrors>({})
const isSavingQuiz = ref(false)
const showQuizBuilder = ref(false)

watch(news, value => showQuizBuilder.value = Boolean(value?.quiz), { immediate: true })

async function storeQuiz(payload: QuizPayload) {
  isSavingQuiz.value = true
  quizErrors.value = {}

  try {
    await saveQuiz(slug.value, payload)
    await refresh()
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      quizErrors.value = caught.errors
    }
  }
  finally {
    isSavingQuiz.value = false
  }
}

async function dropQuiz() {
  await deleteQuiz(slug.value)
  showQuizBuilder.value = false
  await refresh()
}

/* ---------- Адресаты ---------- */

const people = useDebouncedSearch<NewsPerson>(async term => (await searchPeople(term)).data)

function addRecipient(person: NewsPerson) {
  people.clear()

  if (!recipients.value.some(one => one.id === person.id)) {
    recipients.value = [...recipients.value, person]
  }
}

function dropRecipient(id: number) {
  recipients.value = recipients.value.filter(person => person.id !== id)
}
</script>

<template>
  <section v-if="news" class="edit">
    <header class="head">
      <div>
        <h1 class="page-title">
          {{ form.title || 'Без заголовка' }}
        </h1>
        <p class="page-subtitle">
          {{ news.status_label }}<template v-if="news.audience_size !== undefined">
            · ознакомились {{ news.acknowledged_count ?? 0 }} из {{ news.audience_size }}
          </template>
        </p>
      </div>

      <NuxtLink :to="`/news/${news.slug}`" class="button-secondary button-sm">
        Посмотреть
      </NuxtLink>
    </header>

    <p v-if="generalError" class="alert alert--danger" role="alert">
      {{ generalError }}
    </p>

    <section class="card panel">
      <h2 class="panel__title">
        Новость
      </h2>

      <div class="field">
        <label class="field-label" for="title">Заголовок</label>
        <input id="title" v-model.trim="form.title" class="input" maxlength="255">
        <p v-if="errors.title?.length" class="field-error">
          {{ errors.title[0] }}
        </p>
      </div>

      <div class="field">
        <label class="field-label" for="excerpt">
          Короткое описание <span class="field-optional">— строка для ленты</span>
        </label>
        <input id="excerpt" v-model.trim="form.excerpt" class="input" maxlength="500">
      </div>

      <div class="field">
        <span class="field-label">Статья</span>
        <ClientOnly>
          <EditorRichTextEditor
            v-model="document"
            placeholder="Текст новости. Можно вставить картинку или видео."
            :upload-image="(file, options) => uploadInline(file, options, 'Изображение в новости')"
            :upload-video="(file, options) => uploadInline(file, options, 'Видео в новости')"
          />
        </ClientOnly>
      </div>
    </section>

    <section class="card panel">
      <h2 class="panel__title">
        Кому и как
      </h2>

      <div class="field">
        <span class="field-label">Кому видна</span>
        <label class="choice">
          <input v-model="form.audience" type="radio" value="everyone">
          Всем сотрудникам
        </label>
        <label class="choice">
          <input v-model="form.audience" type="radio" value="selected">
          Выбранным сотрудникам
        </label>
        <p v-if="errors.recipients?.length" class="field-error">
          {{ errors.recipients[0] }}
        </p>
      </div>

      <template v-if="form.audience === 'selected'">
        <ul v-if="recipients.length" class="chips">
          <li v-for="person in recipients" :key="person.id" class="chip">
            {{ person.name }}
            <button type="button" class="chip__drop" :aria-label="`Убрать ${person.name}`" @click="dropRecipient(person.id)">
              ×
            </button>
          </li>
        </ul>

        <div class="field">
          <label class="field-label" for="person-search">Добавить сотрудника</label>
          <input
            id="person-search"
            v-model="people.query.value"
            class="input"
            type="search"
            autocomplete="off"
            placeholder="Фамилия или почта"
          >
        </div>

        <p v-if="people.isSearching.value" class="faint">
          Ищем…
        </p>
        <ul v-else-if="people.results.value.length" class="found">
          <li v-for="person in people.results.value" :key="person.id">
            <button type="button" class="found__item" @click="addRecipient(person)">
              <UserAvatar :name="person.name" :src="person.avatar_url" :size="26" />
              <span class="found__body">
                <span>{{ person.name }}</span>
                <span class="faint">{{ person.email }}</span>
              </span>
            </button>
          </li>
        </ul>
      </template>

      <label class="choice">
        <input v-model="form.requires_acknowledgement" type="checkbox">
        Обязательна для ознакомления
      </label>

      <label class="choice">
        <input v-model="form.is_pinned" type="checkbox">
        Закрепить наверху ленты
      </label>

      <div class="field">
        <span class="field-label">Состояние</span>
        <label class="choice">
          <input v-model="form.status" type="radio" value="draft">
          Черновик — видна только тем, кто ведёт новости
        </label>
        <label class="choice">
          <input v-model="form.status" type="radio" value="published">
          Опубликована
        </label>
      </div>
    </section>

    <AttachmentManager
      :attachments="news.attachments ?? []"
      :upload-file="(file, description, options) => uploadAttachment(slug, file, description, options)"
      :rename-file="(id, description) => updateAttachment(slug, id, description)"
      :remove-file="(id) => deleteAttachment(slug, id)"
      @changed="refresh"
    />

    <!-- Проверка вместо кнопки «ознакомлен»: сдал — значит прочитал. -->
    <QuizBuilder
      v-if="showQuizBuilder"
      :quiz="news.quiz ?? null"
      :errors="quizErrors"
      :is-submitting="isSavingQuiz"
      @save="storeQuiz"
      @remove="dropQuiz"
    />

    <section v-else class="card panel">
      <h2 class="panel__title">
        Проверка
      </h2>
      <p class="faint">
        Несколько вопросов вместо кнопки «ознакомлен»: сдавший подтверждает, что прочитал.
      </p>
      <button type="button" class="button-secondary" @click="showQuizBuilder = true">
        Добавить проверку
      </button>
    </section>

    <div class="actions">
      <button type="button" class="button-primary" :disabled="isSaving" @click="save">
        {{ isSaving ? 'Сохраняем…' : 'Сохранить' }}
      </button>
      <span v-if="savedAt" class="faint">Сохранено в {{ savedAt }}</span>
      <button type="button" class="button-ghost actions__remove" @click="remove">
        Удалить новость
      </button>
    </div>
  </section>
</template>

<style scoped>
.edit {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 0.75rem;
}

.head a {
  text-decoration: none;
}

.panel {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1.4rem 1.5rem;
  align-items: flex-start;
}

.panel__title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 600;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  width: 100%;
}

.field-optional {
  color: var(--color-text-faint);
  font-weight: 400;
}

.choice {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.9rem;
}

.chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.chip {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.2rem 0.5rem 0.2rem 0.65rem;
  border-radius: var(--radius-pill);
  background: var(--color-surface-sunken);
  font-size: 0.85rem;
}

.chip__drop {
  border: 0;
  background: transparent;
  color: var(--color-text-muted);
  font: inherit;
  font-size: 1rem;
  line-height: 1;
  cursor: pointer;
}

.found {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  margin: 0;
  padding: 0;
  list-style: none;
  width: 100%;
}

.found__item {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  width: 100%;
  padding: 0.4rem 0.5rem;
  border: 0;
  border-radius: var(--radius);
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.found__item:hover {
  background: var(--color-surface-sunken);
}

.found__body {
  display: flex;
  flex-direction: column;
  gap: 0.05rem;
  min-width: 0;
  font-size: 0.9rem;
}

.actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.actions__remove {
  margin-left: auto;
  color: var(--color-danger);
}

@media (max-width: 48rem) {
  /* Заголовок и «посмотреть» перестают делить строку: на узком экране
     название новости важнее кнопки и должно занимать всю ширину. */
  .head {
    flex-direction: column;
    align-items: stretch;
    gap: 0.6rem;
  }

  .head .button-secondary {
    align-self: flex-start;
  }

  .panel {
    padding: 1.15rem 1.15rem 1.25rem;
  }
}

/*
 * «Сохранить» и «удалить новость» разъезжались по краям экрана, и удаление
 * оказывалось там, где большой палец держит телефон. Ниже 34rem удаление
 * уходит на свою строку и прижимается к левому краю — до него надо
 * дотянуться намеренно.
 */
@media (max-width: 34rem) {
  .actions {
    flex-wrap: wrap;
  }

  .actions__remove {
    margin-left: 0;
    padding-left: 0;
  }
}
</style>
