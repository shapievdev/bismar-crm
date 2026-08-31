<script setup lang="ts">
import type { QuizAttempt, QuizReview } from '~/types/lms'
import { withResolvedMedia } from '~/utils/editor/attachments'

definePageMeta({ middleware: 'auth', permission: 'courses.view' })

const route = useRoute()
const { fetchLesson, fetchCourse, completeLesson, submitQuiz, fetchAttempt } = useLmsApi()
const { can } = useAuth()

const lessonId = computed(() => String(route.params.lesson))
const courseSlug = computed(() => String(route.params.slug))

const { data, error, refresh } = await useAsyncData(
  () => `lms.player.${lessonId.value}`,
  async () => {
    const [lesson, course] = await Promise.all([
      fetchLesson(lessonId.value),
      fetchCourse(courseSlug.value),
    ])

    return { lesson: lesson.data, course: course.data }
  },
)

if (error.value) {
  throw createError({ statusCode: 404, statusMessage: 'Урок не найден', fatal: true })
}

const lesson = computed(() => data.value?.lesson)
const course = computed(() => data.value?.course)

useHead(() => ({ title: lesson.value?.title ?? 'Урок' }))

const completedIds = computed(() => new Set(course.value?.enrollment?.completed_lesson_ids ?? []))
const isEnrolled = computed(() => Boolean(course.value?.enrollment))
/**
 * Куда вести читателя, пришедшего по ссылке на источник.
 *
 * Консультант ссылается не на урок, а на место в нём: секунду записи или абзац
 * текста. Без этого ссылка означала бы «ищите сами», а урок бывает на десять
 * экранов и на час записи.
 */
const startSeconds = computed(() => {
  const raw = Number(route.query.t)

  return Number.isFinite(raw) && raw > 0 ? raw : null
})

const targetBlock = computed(() => {
  const raw = route.query.block

  return typeof raw === 'string' && raw !== '' ? raw : null
})

const embedUrl = computed(() => toEmbedUrl(lesson.value?.video_url, startSeconds.value))

const uploadedVideo = useTemplateRef<HTMLVideoElement>('uploadedVideo')

/**
 * Загруженная запись перематывается свойством плеера: адрес подписан, и лишний
 * параметр в нём сломал бы подпись.
 */
watch([uploadedVideo, startSeconds], ([player, seconds]) => {
  if (player && seconds !== null) {
    player.currentTime = seconds
  }
})

/**
 * Прокрутка к абзацу.
 *
 * Статью рисует редактор внутри ClientOnly, поэтому нужного узла на момент
 * появления параметра ещё нет — ждём кадр отрисовки. Подсветка снимается сама:
 * она нужна, чтобы глаз нашёл место, а не чтобы остаться в тексте навсегда.
 */
watch(targetBlock, async (blockId) => {
  if (!blockId || !import.meta.client) {
    return
  }

  await nextTick()

  const target = document.querySelector(`[data-block-id="${CSS.escape(blockId)}"]`)

  if (!(target instanceof HTMLElement)) {
    return
  }

  target.scrollIntoView({ behavior: 'smooth', block: 'center' })
  target.classList.add('block--highlighted')

  setTimeout(() => target.classList.remove('block--highlighted'), 2600)
}, { immediate: true })

/**
 * The article with a current address for every picture and video it embeds.
 * The document stores attachment ids; the signatures are minted per request.
 */
const article = computed(() =>
  withResolvedMedia(lesson.value?.content_json ?? null, lesson.value?.attachments ?? []),
)

/** The body is plain text; blank lines separate paragraphs. */
const paragraphs = computed(() =>
  (lesson.value?.content ?? '').split(/\n{2,}/).map(block => block.trim()).filter(Boolean),
)

const isCompleted = computed(() => lesson.value?.is_completed ?? false)

/**
 * Урок, из-за которого этот пока нельзя закрыть: курс проходят по порядку.
 * Считает сервер — он один знает, что уже пройдено, — а экран лишь гасит кнопку
 * и называет причину.
 */
const blockedBy = computed(() => lesson.value?.blocked_by ?? null)
const actionError = ref<string | null>(null)
const isWorking = ref(false)

// Question id => chosen option ids.
const answers = ref<Record<number, number[]>>({})
const attempt = ref<QuizAttempt | null>(null)

const attemptsUsed = computed(() => lesson.value?.own_attempts?.length ?? 0)
const attemptsLeft = computed(() => {
  const max = lesson.value?.quiz?.max_attempts

  return max === null || max === undefined ? null : Math.max(0, max - attemptsUsed.value)
})

const answeredCount = computed(
  () => Object.values(answers.value).filter(chosen => chosen.length > 0).length,
)
const questionCount = computed(() => lesson.value?.quiz?.questions?.length ?? 0)

function toggleOption(questionId: number, optionId: number, isSingle: boolean) {
  const current = answers.value[questionId] ?? []

  answers.value[questionId] = isSingle
    ? [optionId]
    : current.includes(optionId)
      ? current.filter(id => id !== optionId)
      : [...current, optionId]
}

function isChosen(questionId: number, optionId: number): boolean {
  return (answers.value[questionId] ?? []).includes(optionId)
}

async function markDone() {
  isWorking.value = true
  actionError.value = null

  try {
    await completeLesson(lessonId.value)
    await refresh()
  }
  catch (caught) {
    const conflict = caught as { data?: { message?: string } }
    actionError.value = conflict.data?.message ?? 'Не удалось отметить урок пройденным.'
  }
  finally {
    isWorking.value = false
  }
}

async function sendQuiz() {
  isWorking.value = true
  actionError.value = null

  try {
    const { data: result } = await submitQuiz(lessonId.value, answers.value)
    attempt.value = result
    await refresh()
  }
  catch (caught) {
    const conflict = caught as { data?: { message?: string } }
    actionError.value = conflict.data?.message ?? 'Не удалось отправить ответы.'
  }
  finally {
    isWorking.value = false
  }
}

function retry() {
  attempt.value = null
  answers.value = {}
}

/**
 * Разбор прошлой попытки.
 *
 * К нему возвращаются: сдал с третьего раза, через месяц перечитываешь урок и
 * хочешь вспомнить, что тогда понял неверно. Поэтому не в свежем ответе, а по
 * запросу — и по одной попытке за раз, чтобы список не превращался в простыню.
 */
const openedAttemptId = ref<number | null>(null)
const openedReview = ref<QuizReview | null>(null)
const isLoadingReview = ref(false)

async function openReview(id: number) {
  if (openedAttemptId.value === id) {
    openedAttemptId.value = null
    openedReview.value = null

    return
  }

  openedAttemptId.value = id
  openedReview.value = null
  isLoadingReview.value = true

  try {
    openedReview.value = (await fetchAttempt(id)).data.review ?? null
  }
  catch {
    actionError.value = 'Не удалось показать разбор попытки.'
    openedAttemptId.value = null
  }
  finally {
    isLoadingReview.value = false
  }
}

function formatSize(bytes: number): string {
  const mb = bytes / 1024 / 1024

  return mb >= 1 ? `${mb.toFixed(1)} МБ` : `${Math.max(1, Math.round(bytes / 1024))} КБ`
}
</script>

<template>
  <div v-if="lesson && course" class="player">
    <aside class="sidebar card">
      <NuxtLink :to="`/lms/${courseSlug}`" class="sidebar__course">
        ← {{ course.title }}
      </NuxtLink>

      <UiProgressBar
        v-if="course.enrollment"
        :value="course.enrollment.progress"
        size="sm"
        :label="`${course.enrollment.progress}%`"
        class="sidebar__progress"
      />

      <nav class="outline">
        <div v-for="module in course.modules ?? []" :key="module.id" class="outline__module">
          <p class="outline__title">
            {{ module.title }}
          </p>

          <NuxtLink
            v-for="item in module.lessons ?? []"
            :key="item.id"
            :to="`/lms/${courseSlug}/lessons/${item.id}`"
            class="outline__lesson"
            :class="{ 'outline__lesson--current': String(item.id) === lessonId }"
          >
            <span class="outline__check" :class="{ 'outline__check--done': completedIds.has(item.id) }">
              <template v-if="completedIds.has(item.id)">✓</template>
            </span>
            <span class="outline__label">{{ item.title }}</span>
          </NuxtLink>
        </div>
      </nav>
    </aside>

    <article class="content">
      <header class="head">
        <div>
          <p class="faint head__eyebrow">
            {{ lesson.course_title }}
          </p>
          <h1 class="page-title">
            {{ lesson.title }}
          </h1>
        </div>

        <div class="head__side">
          <span v-if="isCompleted" class="badge badge--success">Пройден</span>
          <NuxtLink
            v-if="can('courses.update')"
            :to="`/lms/${courseSlug}/lessons/${lessonId}/edit`"
            class="button-ghost button-sm"
          >
            Редактировать
          </NuxtLink>
        </div>
      </header>

      <div v-if="lesson.video_upload_url" class="video">
        <video ref="uploadedVideo" :src="lesson.video_upload_url" controls preload="metadata" />
      </div>

      <div v-else-if="embedUrl" class="video">
        <iframe
          :src="embedUrl"
          title="Видео урока"
          loading="lazy"
          allowfullscreen
          referrerpolicy="strict-origin-when-cross-origin"
        />
      </div>

      <p v-else-if="lesson.video_url" class="video-link">
        <a :href="lesson.video_url" target="_blank" rel="noopener noreferrer">Открыть видео →</a>
      </p>

      <div class="prose">
        <ClientOnly>
          <EditorRichTextRenderer :content="article" :fallback-text="lesson.content" />

          <template #fallback>
            <p v-for="(paragraph, index) in paragraphs" :key="index">
              {{ paragraph }}
            </p>
          </template>
        </ClientOnly>
      </div>

      <section v-if="lesson.attachments?.length" class="block">
        <h2 class="block__title">
          Материалы
        </h2>
        <ul class="files">
          <li v-for="file in lesson.attachments" :key="file.id" class="file">
            <UiFileIcon :name="file.name" :mime-type="file.mime_type" />

            <div class="file__body">
              <a
                :href="file.url"
                class="file__name"
                :target="file.opens_inline ? '_blank' : undefined"
                rel="noopener noreferrer"
              >{{ file.name }}</a>
              <span v-if="file.description" class="file__description">{{ file.description }}</span>
            </div>

            <span class="faint file__size">{{ formatSize(file.size) }}</span>
          </li>
        </ul>
      </section>

      <section v-if="lesson.quiz" class="block quiz card">
        <header class="quiz__head">
          <div>
            <h2 class="block__title">
              {{ lesson.quiz.title }}
            </h2>
            <p class="muted quiz__rules">
              Урок зачтётся, когда все ответы будут верными.
              <template v-if="attemptsLeft !== null">
                Осталось попыток: {{ attemptsLeft }}.
              </template>
            </p>
          </div>

          <span v-if="questionCount && !isCompleted" class="badge">
            {{ answeredCount }} / {{ questionCount }}
          </span>
        </header>

        <div v-if="attempt" class="result" :class="attempt.passed ? 'result--pass' : 'result--fail'">
          <strong>{{ attempt.passed ? 'Тест сдан' : 'Тест не сдан' }}</strong>
          <span>Ваш результат — {{ attempt.score }}%</span>
          <button
            v-if="!attempt.passed && (attemptsLeft === null || attemptsLeft > 0)"
            type="button"
            class="button-secondary button-sm"
            @click="retry"
          >
            Пройти ещё раз
          </button>
        </div>

        <!-- Разбор сразу под результатом: «68%, не сдано» без него отправляет
             человека пересдавать с тем же знанием, с каким он пришёл. -->
        <QuizReviewPanel v-if="attempt?.review" :review="attempt.review" />

        <template v-if="!isCompleted && !attempt?.passed">
          <div v-for="(question, index) in lesson.quiz.questions ?? []" :key="question.id" class="question">
            <p class="question__text">
              <span class="question__num">{{ index + 1 }}.</span>
              {{ question.text }}
              <span v-if="question.type === 'multiple'" class="badge">неск. ответов</span>
            </p>

            <label
              v-for="option in question.options"
              :key="option.id"
              class="option"
              :class="{ 'option--chosen': isChosen(question.id, option.id) }"
            >
              <input
                :type="question.type === 'single' ? 'radio' : 'checkbox'"
                :name="`question-${question.id}`"
                :checked="isChosen(question.id, option.id)"
                :disabled="!isEnrolled"
                @change="toggleOption(question.id, option.id, question.type === 'single')"
              >
              <span>{{ option.text }}</span>
            </label>
          </div>

          <button
            type="button"
            class="button-primary quiz__submit"
            :disabled="isWorking || !isEnrolled || answeredCount === 0 || attemptsLeft === 0"
            @click="sendQuiz"
          >
            {{ isWorking ? 'Проверяем…' : 'Отправить ответы' }}
          </button>
        </template>

        <details v-if="(lesson.own_attempts?.length ?? 0) > 0" class="history">
          <summary>Мои попытки ({{ attemptsUsed }})</summary>
          <ul>
            <li v-for="past in lesson.own_attempts" :key="past.id">
              <button
                type="button"
                class="history__row"
                :aria-expanded="openedAttemptId === past.id"
                @click="openReview(past.id)"
              >
                <span :class="past.passed ? 'pass' : 'fail'">{{ past.passed ? 'сдано' : 'не сдано' }}</span>
                <span>{{ past.score }}%</span>
                <span class="faint">
                  {{ past.completed_at ? new Date(past.completed_at).toLocaleString('ru-RU') : '' }}
                </span>
                <span class="faint history__toggle">
                  {{ openedAttemptId === past.id ? 'скрыть разбор' : 'разбор' }}
                </span>
              </button>

              <p v-if="openedAttemptId === past.id && isLoadingReview" class="faint">
                Загружаем разбор…
              </p>
              <QuizReviewPanel
                v-else-if="openedAttemptId === past.id && openedReview"
                :review="openedReview"
                class="history__review"
              />
            </li>
          </ul>
        </details>
      </section>

      <div v-else-if="isEnrolled && !isCompleted" class="block">
        <!-- Курс проходят по порядку: пока предыдущий урок открыт, кнопка
             гаснет и говорит, с чего начать, — вместо отказа с сервера после
             нажатия. -->
        <button
          type="button"
          class="button-primary"
          :disabled="isWorking || blockedBy !== null"
          @click="markDone"
        >
          {{ isWorking ? 'Сохраняем…' : 'Отметить пройденным' }}
        </button>

        <p v-if="blockedBy" class="muted blocked">
          Сначала пройдите предыдущие уроки — начните с
          <NuxtLink :to="`/lms/${courseSlug}/lessons/${blockedBy.id}`" class="blocked__link">
            «{{ blockedBy.title }}»
          </NuxtLink>.
        </p>
      </div>

      <p v-if="actionError" class="alert alert--danger" role="alert">
        {{ actionError }}
      </p>

      <nav class="pager">
        <NuxtLink
          v-if="lesson.neighbours?.previous"
          :to="`/lms/${courseSlug}/lessons/${lesson.neighbours.previous.id}`"
          class="pager__link"
        >
          <span class="faint">← Предыдущий</span>
          <span>{{ lesson.neighbours.previous.title }}</span>
        </NuxtLink>
        <span v-else />

        <NuxtLink
          v-if="lesson.neighbours?.next"
          :to="`/lms/${courseSlug}/lessons/${lesson.neighbours.next.id}`"
          class="pager__link pager__link--next"
        >
          <span class="faint">Следующий →</span>
          <span>{{ lesson.neighbours.next.title }}</span>
        </NuxtLink>
      </nav>
    </article>
  </div>
</template>

<style scoped>
.blocked {
  margin-top: 0.6rem;
}

.blocked__link {
  color: inherit;
}

.player {
  display: grid;
  grid-template-columns: 1fr;
  gap: 1.25rem;
}

@media (min-width: 62rem) {
  .player {
    grid-template-columns: 17rem 1fr;
    align-items: start;
  }

  .sidebar {
    position: sticky;
    top: calc(var(--header-height) + 1rem);
    max-height: calc(100vh - var(--header-height) - 3rem);
    overflow-y: auto;
  }
}

.sidebar {
  padding: 1rem;
}

.sidebar__course {
  display: block;
  margin-bottom: 0.6rem;
  font-size: 0.88rem;
  font-weight: 500;
  text-decoration: none;
}

.sidebar__progress {
  margin-bottom: 1rem;
}

.outline__module + .outline__module {
  margin-top: 1rem;
}

.outline__title {
  margin: 0 0 0.35rem;
  color: var(--color-text-faint);
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.outline__lesson {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.4rem 0.5rem;
  border-radius: var(--radius-sm);
  color: var(--color-text-muted);
  font-size: 0.87rem;
  text-decoration: none;
}

.outline__lesson:hover {
  background: var(--color-surface-sunken);
  color: var(--color-text);
}

.outline__lesson--current {
  background: var(--color-accent-soft);
  color: var(--color-accent);
  font-weight: 500;
}

.outline__check {
  display: grid;
  place-items: center;
  width: 1.05rem;
  height: 1.05rem;
  flex-shrink: 0;
  border: 1.5px solid var(--color-border-strong);
  border-radius: var(--radius-pill);
  font-size: 0.6rem;
}

.outline__check--done {
  background: var(--color-success);
  border-color: var(--color-success);
  color: #fff;
}

.outline__label {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.content {
  min-width: 0;
}

.head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1.25rem;
}

.head__eyebrow {
  margin: 0 0 0.2rem;
  font-size: 0.82rem;
}

.head__side {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.notice {
  display: flex;
  gap: 0.5rem;
}

.video {
  aspect-ratio: 16 / 9;
  margin-bottom: 1.5rem;
  border-radius: var(--radius-lg);
  overflow: hidden;
  background: #000;
}

.video iframe,
.video video {
  width: 100%;
  height: 100%;
  border: 0;
  display: block;
}

.video-link {
  margin-bottom: 1.25rem;
}

/*
 * Место, на которое сослался консультант.
 *
 * Подсветка, а не прокрутка молча: читатель пришёл проверить одно утверждение,
 * и середина экрана сама по себе не говорит, какой именно абзац имелся в виду.
 * Гаснет через пару секунд — глаз уже нашёл, а насовсем крашеный абзац читать
 * мешает.
 *
 * :deep, потому что абзац рисует редактор внутри своего поддерева, куда
 * scoped-стили страницы не достают.
 */
:deep(.block--highlighted) {
  animation: block-found 2.6s ease-out;
  border-radius: 0.35rem;
}

/*
 * Мягкий фон, а не акцент: лаймовый акцент закреплён за активной вкладкой, и
 * подсвеченный им абзац читался бы как выбранный раздел. К тому же в светлой
 * теме акцент почти чёрный — подсветкой ему не быть.
 */
@keyframes block-found {
  0%, 55% {
    background: var(--color-accent-soft);
    box-shadow: 0 0 0 0.4rem var(--color-accent-soft);
  }
  100% {
    background: transparent;
    box-shadow: 0 0 0 0.4rem transparent;
  }
}

@media (prefers-reduced-motion: reduce) {
  :deep(.block--highlighted) {
    animation: none;
    background: var(--color-accent-soft);
    box-shadow: 0 0 0 0.4rem var(--color-accent-soft);
  }
}

.prose {
  max-width: 44rem;
}

.prose p {
  margin: 0 0 1.05rem;
  white-space: pre-wrap;
}

.block {
  margin-top: 2rem;
}

.block__title {
  margin: 0;
  font-size: 1.08rem;
  font-weight: 600;
}

.files {
  margin: 0.6rem 0 0;
  padding: 0;
  list-style: none;
  max-width: 34rem;
}

.file {
  display: flex;
  align-items: center;
  gap: 0.8rem;
  padding: 0.6rem 0.8rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  margin-bottom: 0.4rem;
}

.file__body {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-width: 0;
  gap: 0.1rem;
}

.file__name {
  color: inherit;
  font-size: 0.92rem;
  font-weight: 500;
  text-decoration: none;
}

.file__name:hover {
  color: var(--color-accent);
  text-decoration: underline;
}

.file__description {
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.file__size {
  font-size: 0.82rem;
  white-space: nowrap;
}

.quiz {
  padding: 1.25rem;
  max-width: 44rem;
}

.quiz__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
}

.quiz__rules {
  margin: 0.25rem 0 0;
  font-size: 0.86rem;
}

.quiz__submit {
  margin-top: 1.25rem;
}

.result {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
  padding: 0.8rem 1rem;
  margin: 1rem 0;
  border-radius: var(--radius);
}

.result--pass {
  background: var(--color-success-soft);
  color: var(--color-success);
}

.result--fail {
  background: var(--color-danger-soft);
  color: var(--color-danger);
}

.question {
  margin-top: 1.4rem;
}

.question__text {
  margin: 0 0 0.55rem;
  font-weight: 500;
}

.question__num {
  color: var(--color-text-faint);
  margin-right: 0.2rem;
}

.option {
  display: flex;
  align-items: flex-start;
  gap: 0.6rem;
  padding: 0.5rem 0.7rem;
  margin-bottom: 0.35rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  font-size: 0.93rem;
  cursor: pointer;
}

.option:hover {
  border-color: var(--color-border-strong);
}

.option--chosen {
  border-color: var(--color-accent);
  background: var(--color-accent-soft);
}

.history {
  margin-top: 1.5rem;
  font-size: 0.87rem;
}

.history summary {
  cursor: pointer;
  color: var(--color-text-muted);
}

.history ul {
  margin: 0.6rem 0 0;
  padding: 0;
  list-style: none;
}

/* Строка попытки и её разбор — одним столбцом: разбор раскрывается под той
   строкой, к которой относится. */
.history li {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  padding: 0.3rem 0;
}

.history__row {
  display: flex;
  align-items: baseline;
  gap: 0.9rem;
  width: 100%;
  padding: 0.2rem 0;
  border: none;
  background: none;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.history__toggle {
  margin-left: auto;
  text-decoration: underline;
  text-underline-offset: 0.2em;
}

.history__review {
  padding-left: 0.2rem;
}

.pass { color: var(--color-success); }
.fail { color: var(--color-danger); }

.pager {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.75rem;
  margin-top: 2.5rem;
  padding-top: 1.25rem;
  border-top: 1px solid var(--color-border);
}

.pager__link {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  padding: 0.7rem 0.9rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  color: inherit;
  font-size: 0.9rem;
  text-decoration: none;
}

.pager__link:hover {
  border-color: var(--color-border-strong);
}

.pager__link--next {
  text-align: right;
}

@media (max-width: 48rem) {
  .head {
    flex-direction: column;
    align-items: flex-start;
    gap: 0.6rem;
  }

  /* One link per row: two half-width cards leave no room for a lesson title. */
  .pager {
    grid-template-columns: minmax(0, 1fr);
  }

  .pager__link--next {
    text-align: left;
  }

  .quiz {
    padding: 1rem;
  }

  .files li {
    flex-wrap: wrap;
  }

  .sidebar {
    /* Collapsed by default on a phone: the outline is a whole screen of links
       standing between the reader and the lesson. */
    max-height: 13rem;
    overflow-y: auto;
  }
}
</style>