<script setup lang="ts">
import { withResolvedMedia } from '~/utils/editor/attachments'
import type { CoursePerson, QuizOutcome } from '~/types/lms'

definePageMeta({ middleware: 'auth', permission: 'courses.view' })

const route = useRoute()
const slug = computed(() => String(route.params.slug))

const { can } = useAuth()
const { fetchRegulation, acknowledge, fetchReaders, fetchCategories, submitQuiz } = useRegulationsApi()

const { data, error, refresh } = await useAsyncData(
  () => `lms.regulation.${slug.value}`,
  () => fetchRegulation(slug.value),
)

if (error.value) {
  throw createError({ statusCode: 404, statusMessage: 'Документ не найден', fatal: true })
}

const regulation = computed(() => data.value?.data ?? null)

useHead({ title: () => regulation.value?.title ?? 'Документ' })

/**
 * Крошки: раздел, категории по дороге сюда и сам документ.
 *
 * У документа своя категория, а крошкам нужен весь путь наверх — отсюда дерево
 * категорий. Ключ общий с разделом: дерево одно, и второй раз за ним не ходят.
 */
const { data: categoryData } = await useAsyncData(
  'lms.regulation-categories',
  () => fetchCategories(),
)

const trail = computed(() => categoryTrail(
  categoryData.value?.data ?? [],
  regulation.value?.category?.slug,
))

/**
 * Адреса вложенных картинок и видео подставляются на пути к экрану: статья
 * хранит их номера, а подписанные ссылки живут час.
 */
const article = computed(() => withResolvedMedia(
  regulation.value?.content_json ?? null,
  regulation.value?.attachments ?? [],
))

const documents = computed(() =>
  (regulation.value?.attachments ?? []).filter(file =>
    // Файл с Диска в списке всегда: он не бывает случайной картинкой из статьи
    // — его прикладывают руками и затем, чтобы его нашли.
    file.source === 'google_drive' || !file.opens_inline || file.description))

function day(value: string | null): string {
  return value ? new Date(value).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' }) : ''
}

/* ---------- Ознакомление ---------- */

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

/* ---------- Проверка вместо кнопки ---------- */

/**
 * Есть проверка — ознакомление засчитывается сдачей. Кнопки при ней нет вовсе:
 * нажатие обесценивало бы тест, и сервер её всё равно не примет.
 */
const quiz = computed(() => regulation.value?.quiz ?? null)
const attempts = computed(() => regulation.value?.own_attempts ?? [])

const isSubmitting = ref(false)
const quizError = ref<string | null>(null)
const outcome = ref<QuizOutcome | null>(null)

/**
 * Последняя работа, ушедшая на аттестацию, и её состояние.
 *
 * Ожидание живёт дольше одного захода: отправили вчера, ответ пришёл сегодня —
 * и человек должен увидеть его, просто открыв документ. Поэтому берётся не
 * итог отправки, а последняя попытка с сервера.
 */
const lastAttempt = computed(() => attempts.value[0] ?? null)

const awaitingAttestation = computed(() =>
  lastAttempt.value?.review_status === 'pending' ? lastAttempt.value : null)

const judgedAttestation = computed(() =>
  lastAttempt.value?.review_status === 'passed' || lastAttempt.value?.review_status === 'failed'
    ? lastAttempt.value
    : null)

async function sendAnswers(answers: Record<number, number[] | string | string[][]>) {
  isSubmitting.value = true
  quizError.value = null

  try {
    outcome.value = (await submitQuiz(slug.value, answers)).data

    // Сдал — документ прочитан: перечитываем его, чтобы отметка и история
    // попыток пришли с сервера, а не собирались на экране.
    if (outcome.value.passed) {
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

/* ---------- Кто прочитал: только тому, кто документ ведёт ---------- */

const readers = ref<CoursePerson[] | null>(null)

async function toggleReaders() {
  if (readers.value !== null) {
    readers.value = null

    return
  }

  readers.value = (await fetchReaders(slug.value)).data
}
</script>

<template>
  <article v-if="regulation" class="regulation">
    <nav class="crumbs" aria-label="Где я">
      <NuxtLink to="/lms/documents">
        Документы
      </NuxtLink>

      <template v-for="node in trail" :key="node.slug">
        <span class="crumbs__separator" aria-hidden="true">/</span>
        <NuxtLink :to="{ path: '/lms/documents', query: { category: node.slug } }">
          {{ node.name }}
        </NuxtLink>
      </template>

      <span class="crumbs__separator" aria-hidden="true">/</span>
      <span class="faint" aria-current="page">{{ regulation.title }}</span>
    </nav>

    <header class="head">
      <div class="head__marks">
        <span v-if="!regulation.is_published" class="badge badge--warning">{{ regulation.status_label }}</span>
        <span v-if="regulation.is_private" class="badge">{{ regulation.visibility_label }}</span>
        <span v-if="regulation.is_acknowledged" class="badge badge--success">Ознакомлен</span>
      </div>

      <h1 class="page-title">
        {{ regulation.title }}
      </h1>

      <p v-if="regulation.summary" class="page-subtitle">
        {{ regulation.summary }}
      </p>

      <p class="faint">
        <template v-if="regulation.category">{{ regulation.category.name }} · </template>
        <template v-if="regulation.author">{{ regulation.author.name }} · </template>
        {{ day(regulation.published_at) }}
      </p>

      <div v-if="can('courses.update')" class="head__actions">
        <NuxtLink :to="`/lms/documents/${regulation.slug}/edit`" class="button-secondary button-sm">
          Редактировать
        </NuxtLink>
        <button type="button" class="button-ghost button-sm" @click="toggleReaders">
          {{ readers ? 'Скрыть' : 'Кто ознакомился' }}
          <template v-if="regulation.acknowledged_count !== undefined">
            ({{ regulation.acknowledged_count }})
          </template>
        </button>
      </div>
    </header>

    <section v-if="readers" class="card readers">
      <h2 class="readers__title">
        Ознакомились — {{ readers.length }}
      </h2>
      <p v-if="!readers.length" class="faint">
        Пока никто.
      </p>
      <ul v-else class="people">
        <li v-for="person in readers" :key="person.id" class="person">
          <UserAvatar :name="person.name" :src="person.avatar_url" :size="26" />
          <span class="person__body">
            <span>{{ person.name }}</span>
            <span v-if="person.acknowledged_at" class="faint">{{ day(person.acknowledged_at) }}</span>
          </span>
        </li>
      </ul>
    </section>

    <EditorRichTextRenderer :content="article" />

    <section v-if="documents.length" class="card files">
      <h2 class="files__title">
        Документы
      </h2>
      <ul class="files__list">
        <li v-for="file in documents" :key="file.id" class="file">
          <div class="file__row">
            <UiFileIcon :name="file.name" :mime-type="file.mime_type" />

            <a :href="file.url" target="_blank" rel="noopener noreferrer" class="file__link">
              {{ file.name }}
              <span v-if="file.description" class="faint file__note">{{ file.description }}</span>
            </a>
          </div>

          <!-- Файл с Диска раскрыт сразу: его затем и прикладывают, чтобы
               читали здесь. Рамка стоит под строкой, а не рядом со значком:
               иначе листу A4 остаётся колонка в две трети ширины. -->
          <DriveEmbed
            v-if="file.embed_url"
            :src="file.embed_url"
            :title="file.name"
            :open-url="file.url"
          />
        </li>
      </ul>
    </section>

    <!-- Кому писать, если написанного не хватило. -->
    <section v-if="regulation.experts?.length" class="card experts">
      <h2 class="files__title">
        Спросите ответственного
      </h2>
      <ul class="people">
        <li v-for="person in regulation.experts" :key="person.id" class="person">
          <UserAvatar :name="person.name" :src="person.avatar_url" :size="26" />
          <span class="person__body">
            <span>{{ person.name }}</span>
            <NuxtLink :to="`/messenger?write=${person.id}`" class="faint person__write">
              Написать
            </NuxtLink>
          </span>
        </li>
      </ul>
    </section>

    <template v-if="regulation.is_published">
      <!-- Отметка стоит — говорим об этом и не спрашиваем второй раз, была она
           поставлена кнопкой или сдачей проверки. -->
      <section v-if="regulation.is_acknowledged" class="card confirm">
        <p class="confirm__done">
          Вы ознакомились с этим документом
          <template v-if="regulation.acknowledged_at">
            {{ day(regulation.acknowledged_at) }}
          </template>
        </p>
      </section>

      <!-- Работа ушла человеку: пока он не ответил, отвечать заново нечего. -->
      <section v-else-if="quiz && awaitingAttestation" class="card confirm">
        <h2 class="files__title">
          {{ quiz.title }}
        </h2>
        <AttestationStatusPanel :attempt="awaitingAttestation" :examiner="quiz.examiner?.name" />
      </section>

      <!-- Проверка вместо кнопки: сдал — значит прочитал. -->
      <QuizRunner
        v-else-if="quiz"
        :quiz="quiz"
        :is-submitting="isSubmitting"
        :error-message="quizError"
        :result="outcome"
        :rule="quiz.kind === 'attestation'
          ? `Работу читает ${quiz.examiner?.name ?? 'назначенный проверяющий'}: документ зачтётся после его ответа.`
          : 'Документ зачтётся, когда все ответы будут верными.'"
        passed-note="Документ отмечен как прочитанный."
        failed-note="Перечитайте документ и попробуйте снова."
        @submit="sendAnswers"
        @retry="outcome = null"
      />

      <!-- Ответ проверяющего, когда он уже пришёл: отказ с причиной или зачёт. -->
      <section v-if="quiz && judgedAttestation" class="card confirm">
        <AttestationStatusPanel :attempt="judgedAttestation" :examiner="quiz.examiner?.name" />
      </section>

      <section v-else class="card confirm">
        <p v-if="confirmError" class="alert alert--danger" role="alert">
          {{ confirmError }}
        </p>
        <p class="faint">
          Отметьтесь, когда прочтёте: по этой отметке видно, что правило до вас дошло.
        </p>
        <button type="button" class="button-primary" :disabled="isConfirming" @click="confirm">
          {{ isConfirming ? 'Отмечаем…' : 'Ознакомлен' }}
        </button>
      </section>

      <!-- Прошлые попытки с разбором каждой — как у теста урока. -->
      <QuizAttemptsHistory :attempts="attempts" />
    </template>
  </article>
</template>

<style scoped>
.crumbs {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem;
  margin-bottom: 1rem;
  font-size: 0.87rem;
}

.crumbs a {
  color: var(--color-text-muted);
  text-decoration: none;
}

.crumbs a:hover {
  color: var(--color-text);
  text-decoration: underline;
}

.crumbs__separator {
  color: var(--color-text-faint);
}

.regulation {
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

.readers,
.files,
.experts,
.confirm {
  padding: 1.25rem;
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
  font-size: 0.9rem;
}

/* Значок и имя — в ряд; рамка просмотра, если она есть, — под ними во всю
   ширину, иначе листу A4 достаётся колонка в две трети. Строка файла и строка
   человека устроены одинаково, поэтому и правило одно. */
.person,
.file__row {
  display: flex;
  align-items: center;
  gap: 0.6rem;
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
