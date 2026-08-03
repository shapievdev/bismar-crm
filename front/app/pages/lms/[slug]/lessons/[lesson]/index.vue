<script setup lang="ts">
import type { QuizAttempt } from '~/types/lms'

definePageMeta({ middleware: 'auth', permission: 'courses.view' })

const route = useRoute()
const { fetchLesson, fetchCourse, completeLesson, submitQuiz } = useLmsApi()
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
const embedUrl = computed(() => toEmbedUrl(lesson.value?.video_url))

/** The body is plain text; blank lines separate paragraphs. */
const paragraphs = computed(() =>
  (lesson.value?.content ?? '').split(/\n{2,}/).map(block => block.trim()).filter(Boolean),
)

const isCompleted = computed(() => lesson.value?.is_completed ?? false)
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
        <video :src="lesson.video_upload_url" controls preload="metadata" />
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
          <EditorRichTextRenderer :content="lesson.content_json ?? null" :fallback-text="lesson.content" />

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
              Проходной балл — {{ lesson.quiz.passing_score }}%.
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
              <span :class="past.passed ? 'pass' : 'fail'">{{ past.passed ? 'сдано' : 'не сдано' }}</span>
              <span>{{ past.score }}%</span>
              <span class="faint">
                {{ past.completed_at ? new Date(past.completed_at).toLocaleString('ru-RU') : '' }}
              </span>
            </li>
          </ul>
        </details>
      </section>

      <div v-else-if="isEnrolled && !isCompleted" class="block">
        <button type="button" class="button-primary" :disabled="isWorking" @click="markDone">
          {{ isWorking ? 'Сохраняем…' : 'Отметить пройденным' }}
        </button>
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

.history li {
  display: flex;
  gap: 0.9rem;
  padding: 0.3rem 0;
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