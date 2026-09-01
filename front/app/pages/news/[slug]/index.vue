<script setup lang="ts">
import { withResolvedMedia } from '~/utils/editor/attachments'
import type { NewsAcknowledgements, NewsQuizResult } from '~/types/news'

definePageMeta({ middleware: 'auth' })

const route = useRoute()
const slug = computed(() => String(route.params.slug))

const { can } = useAuth()
const { fetchNews, acknowledge, submitQuiz, fetchAcknowledgements } = useNewsApi()

const { data, error, refresh } = await useAsyncData(
  () => `news.${slug.value}`,
  () => fetchNews(slug.value),
)

if (error.value) {
  throw createError({ statusCode: 404, statusMessage: 'Новость не найдена', fatal: true })
}

const news = computed(() => data.value?.data ?? null)

useHead({ title: () => news.value?.title ?? 'Новость' })

/**
 * Адреса вложенных картинок и видео подставляются на пути к экрану: статья
 * хранит их номера, а подписанные ссылки живут час.
 */
const article = computed(() => withResolvedMedia(
  news.value?.content_json ?? null,
  news.value?.attachments ?? [],
))

const documents = computed(() => (news.value?.attachments ?? []).filter(file => !file.opens_inline || file.description))

function day(value: string | null): string {
  return value ? new Date(value).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' }) : ''
}

/* ---------- Ознакомление кнопкой ---------- */

const isConfirming = ref(false)
const confirmError = ref<string | null>(null)

async function confirm() {
  isConfirming.value = true
  confirmError.value = null

  try {
    await acknowledge(slug.value)
    await refresh()
  }
  catch {
    confirmError.value = 'Не удалось отметить ознакомление.'
  }
  finally {
    isConfirming.value = false
  }
}

/* ---------- Ознакомление проверкой ---------- */

const isSubmitting = ref(false)
const quizError = ref<string | null>(null)
const result = ref<NewsQuizResult | null>(null)

async function send(answers: Record<number, number[]>) {
  isSubmitting.value = true
  quizError.value = null

  try {
    const { data: outcome } = await submitQuiz(slug.value, answers)
    result.value = outcome

    if (outcome.passed) {
      await refresh()
    }
  }
  catch (caught) {
    const failure = caught as { data?: { message?: string } }
    quizError.value = failure.data?.message ?? 'Не удалось отправить ответы.'
  }
  finally {
    isSubmitting.value = false
  }
}

function retry() {
  result.value = null
}

/* ---------- Кто ознакомился: только тому, кто новость ведёт ---------- */

const readers = ref<NewsAcknowledgements | null>(null)
const isLoadingReaders = ref(false)

async function loadReaders() {
  if (readers.value !== null) {
    readers.value = null

    return
  }

  isLoadingReaders.value = true

  try {
    readers.value = (await fetchAcknowledgements(slug.value)).data
  }
  finally {
    isLoadingReaders.value = false
  }
}
</script>

<template>
  <article v-if="news" class="news">
    <header class="head">
      <div class="head__marks">
        <span v-if="!news.is_published" class="badge badge--warning">Черновик</span>
        <span v-if="news.is_pinned" class="badge">Закреплена</span>
        <span v-if="news.is_acknowledged" class="badge badge--success">Ознакомлен</span>
        <span v-else-if="news.awaits_acknowledgement" class="badge badge--warning">Нужно ознакомиться</span>
      </div>

      <h1 class="page-title">
        {{ news.title }}
      </h1>

      <p class="faint">
        <template v-if="news.author">{{ news.author.name }} · </template>{{ day(news.published_at) }}
        <template v-if="can('news.manage')"> · {{ news.audience_label }}</template>
      </p>

      <div v-if="can('news.manage')" class="head__actions">
        <NuxtLink :to="`/news/${news.slug}/edit`" class="button-secondary button-sm">
          Редактировать
        </NuxtLink>
        <button type="button" class="button-ghost button-sm" @click="loadReaders">
          {{ readers ? 'Скрыть' : 'Кто ознакомился' }}
          <template v-if="news.audience_size !== undefined">
            ({{ news.acknowledged_count ?? 0 }} из {{ news.audience_size }})
          </template>
        </button>
      </div>
    </header>

    <!-- Списки людей — над статьёй: тот, кто новость ведёт, заходит сюда
         именно за ними, а текст он и так знает. -->
    <section v-if="readers" class="card readers">
      <div class="readers__column">
        <h2 class="readers__title">
          Ознакомились — {{ readers.acknowledged.length }}
        </h2>
        <p v-if="!readers.acknowledged.length" class="faint">
          Пока никто.
        </p>
        <ul v-else class="people">
          <li v-for="person in readers.acknowledged" :key="person.id" class="person">
            <UserAvatar :name="person.name" :src="person.avatar_url" :size="26" />
            <span class="person__body">
              <span>{{ person.name }}</span>
              <span class="faint">{{ person.acknowledged_via }}</span>
            </span>
          </li>
        </ul>
      </div>

      <div class="readers__column">
        <h2 class="readers__title">
          Ещё нет — {{ readers.pending.length }}
        </h2>
        <p v-if="!readers.pending.length" class="faint">
          Все ознакомились.
        </p>
        <ul v-else class="people">
          <li v-for="person in readers.pending" :key="person.id" class="person">
            <UserAvatar :name="person.name" :src="person.avatar_url" :size="26" />
            <span class="person__body">
              <span>{{ person.name }}</span>
              <!-- Напомнить, а не строить уведомления: мессенджер уже есть. -->
              <NuxtLink :to="`/messenger?write=${person.id}`" class="faint person__write">
                Написать
              </NuxtLink>
            </span>
          </li>
        </ul>
      </div>
    </section>

    <EditorRichTextRenderer :content="article" />

    <!-- Куда сходить после новости: правило поменялось — вот само правило. -->
    <section v-if="news.links?.length" class="card links">
      <h2 class="files__title">
        Обязательно для просмотра
      </h2>
      <ul class="links__list">
        <li v-for="link in news.links" :key="link.id">
          <NuxtLink v-if="link.url" :to="link.url" class="link">
            <span class="badge link__kind">{{ link.kind_label }}</span>
            <span class="link__body">
              <span class="link__title">{{ link.title }}</span>
              <span v-if="link.subtitle" class="faint">{{ link.subtitle }}</span>
            </span>
          </NuxtLink>
        </li>
      </ul>
    </section>

    <section v-if="documents.length" class="card files">
      <h2 class="files__title">
        Документы
      </h2>
      <ul class="files__list">
        <li v-for="file in documents" :key="file.id" class="file">
          <UiFileIcon :name="file.name" :mime-type="file.mime_type" />
          <a :href="file.url" target="_blank" rel="noopener noreferrer" class="file__link">
            {{ file.name }}
            <span v-if="file.description" class="faint file__note">{{ file.description }}</span>
          </a>
        </li>
      </ul>
    </section>

    <!-- Проверка вместо кнопки: сдал — значит прочитал. Разметка общая с
         документом: там и там тест подтверждает ознакомление. -->
    <QuizRunner
      v-if="news.quiz && !news.is_acknowledged"
      :quiz="news.quiz"
      :is-submitting="isSubmitting"
      :error-message="quizError"
      :result="result"
      :rule="`Проходной балл — ${news.quiz.passing_score}%. Сдав проверку, вы подтверждаете, что прочитали новость.`"
      passed-note="Новость отмечена как прочитанная."
      failed-note="Перечитайте новость и попробуйте снова."
      @submit="send"
      @retry="retry"
    />

    <!-- Кнопка — когда проверки нет. Есть у любой новости, а не только у той,
         где ознакомление требуют: отметиться человек вправе всегда. -->
    <section v-else-if="!news.quiz" class="card confirm">
      <template v-if="news.is_acknowledged">
        <p class="confirm__done">
          Вы ознакомились с этой новостью
          <template v-if="news.acknowledged_at">
            {{ day(news.acknowledged_at) }}
          </template>
        </p>
      </template>

      <template v-else>
        <p v-if="confirmError" class="alert alert--danger" role="alert">
          {{ confirmError }}
        </p>
        <p class="faint">
          {{ news.awaits_acknowledgement
            ? 'С этой новостью нужно ознакомиться — отметьтесь, когда прочтёте.'
            : 'Можно отметить, что вы её прочитали.' }}
        </p>
        <button type="button" class="button-primary" :disabled="isConfirming" @click="confirm">
          {{ isConfirming ? 'Отмечаем…' : 'Ознакомлен' }}
        </button>
      </template>
    </section>
  </article>
</template>

<style scoped>
.news {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.head {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.head__marks,
.head__actions {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.head__actions {
  margin-top: 0.35rem;
}

.head__actions a {
  text-decoration: none;
}

.readers {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 1.25rem;
  padding: 1.25rem;
}

@media (min-width: 48rem) {
  .readers {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  }
}

.readers__title,
.files__title {
  margin: 0 0 0.6rem;
  font-size: 1rem;
  font-weight: 600;
}

.people,
.files__list {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.person,
.file {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  font-size: 0.9rem;
}

.person__body,
.file__link {
  display: flex;
  flex-direction: column;
  gap: 0.05rem;
  min-width: 0;
}

.person__write {
  align-self: flex-start;
  font-size: 0.8rem;
}

.file__link {
  color: inherit;
  text-decoration: none;
}

.file__link:hover {
  text-decoration: underline;
}

.file__note {
  font-size: 0.825rem;
}

.files,
.links,
.confirm {
  padding: 1.25rem;
}

.links__list {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.link {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.5rem 0.6rem;
  border-radius: var(--radius);
  color: inherit;
  text-decoration: none;
}

.link:hover {
  background: var(--color-surface-sunken);
}

.link__kind {
  flex-shrink: 0;
}

.link__body {
  display: flex;
  flex-direction: column;
  gap: 0.05rem;
  min-width: 0;
  font-size: 0.9rem;
}

.link__title {
  font-weight: 550;
}

.confirm {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  align-items: flex-start;
}

.confirm__done {
  margin: 0;
  color: var(--color-success);
  font-weight: 550;
}
</style>
