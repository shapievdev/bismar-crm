<script setup lang="ts">
import type { QuizAttempt } from '~/types/lms'

definePageMeta({ middleware: 'auth', permission: 'courses.view' })

const route = useRoute()
const { fetchLesson, completeLesson, submitQuiz } = useLmsApi()
const { can } = useAuth()

const lessonId = computed(() => String(route.params.lesson))
const courseSlug = computed(() => String(route.params.slug))

const { data, error, refresh } = await useAsyncData(
  () => `lms.lesson.${lessonId.value}`,
  () => fetchLesson(lessonId.value),
)

if (error.value) {
  throw createError({ statusCode: 404, statusMessage: 'Урок не найден', fatal: true })
}

const lesson = computed(() => data.value?.data)
useHead(() => ({ title: lesson.value?.title ?? 'Урок' }))

/** The body is plain text; blank lines separate paragraphs. */
const paragraphs = computed(() =>
  (lesson.value?.content ?? '').split(/\n{2,}/).map(block => block.trim()).filter(Boolean),
)

const isCompleted = ref(lesson.value?.is_completed ?? false)
const actionError = ref<string | null>(null)
const isWorking = ref(false)

// Question id => chosen option ids.
const answers = ref<Record<number, number[]>>({})
const attempt = ref<QuizAttempt | null>(null)

function toggleOption(questionId: number, optionId: number, isSingle: boolean) {
  const current = answers.value[questionId] ?? []

  if (isSingle) {
    answers.value[questionId] = [optionId]
    return
  }

  answers.value[questionId] = current.includes(optionId)
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
    isCompleted.value = true
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

    if (result.passed) {
      isCompleted.value = true
      await refresh()
    }
  }
  catch (caught) {
    const conflict = caught as { data?: { message?: string } }
    actionError.value = conflict.data?.message ?? 'Не удалось отправить ответы.'
  }
  finally {
    isWorking.value = false
  }
}

function formatSize(bytes: number): string {
  const mb = bytes / 1024 / 1024

  return mb >= 1 ? `${mb.toFixed(1)} МБ` : `${Math.max(1, Math.round(bytes / 1024))} КБ`
}
</script>

<template>
  <article v-if="lesson">
    <NuxtLink :to="`/lms/${courseSlug}`" class="back">
      ← К курсу
    </NuxtLink>

    <header class="header">
      <h1>{{ lesson.title }}</h1>
      <span v-if="isCompleted" class="done">Пройден</span>

      <NuxtLink
        v-if="can('courses.update')"
        :to="`/lms/${courseSlug}/lessons/${lessonId}/edit`"
        class="edit-link"
      >
        Редактировать
      </NuxtLink>
    </header>

    <div v-if="lesson.video_url" class="video">
      <a :href="lesson.video_url" target="_blank" rel="noopener noreferrer">
        Смотреть видео →
      </a>
    </div>

    <div class="content">
      <p v-for="(paragraph, index) in paragraphs" :key="index">
        {{ paragraph }}
      </p>
    </div>

    <section v-if="lesson.attachments?.length" class="attachments">
      <h2>Материалы</h2>
      <ul>
        <li v-for="file in lesson.attachments" :key="file.id">
          <a :href="file.url" target="_blank" rel="noopener noreferrer">{{ file.name }}</a>
          <span class="muted">{{ formatSize(file.size) }}</span>
        </li>
      </ul>
    </section>

    <section v-if="lesson.quiz" class="quiz">
      <h2>{{ lesson.quiz.title }}</h2>
      <p class="muted">
        Проходной балл — {{ lesson.quiz.passing_score }}%.
        <template v-if="lesson.quiz.max_attempts">
          Попыток: не более {{ lesson.quiz.max_attempts }}.
        </template>
      </p>

      <div v-for="question in lesson.quiz.questions ?? []" :key="question.id" class="question">
        <p class="question__text">
          {{ question.text }}
        </p>

        <label v-for="option in question.options" :key="option.id" class="option">
          <input
            :type="question.type === 'single' ? 'radio' : 'checkbox'"
            :name="`question-${question.id}`"
            :checked="isChosen(question.id, option.id)"
            @change="toggleOption(question.id, option.id, question.type === 'single')"
          >
          {{ option.text }}
        </label>
      </div>

      <div class="quiz__actions">
        <button type="button" class="button-primary" :disabled="isWorking" @click="sendQuiz">
          {{ isWorking ? 'Проверяем…' : 'Отправить ответы' }}
        </button>

        <p v-if="attempt" class="result" :class="{ 'result--passed': attempt.passed }">
          {{ attempt.passed ? `Сдано — ${attempt.score}%` : `Не сдано — ${attempt.score}%` }}
        </p>
      </div>
    </section>

    <footer v-else class="actions">
      <button
        v-if="!isCompleted"
        type="button"
        class="button-primary"
        :disabled="isWorking"
        @click="markDone"
      >
        {{ isWorking ? 'Сохраняем…' : 'Отметить пройденным' }}
      </button>
    </footer>

    <p v-if="actionError" class="auth-alert" role="alert">
      {{ actionError }}
    </p>
  </article>
</template>

<style scoped>
.back {
  display: inline-block;
  margin-bottom: 1rem;
  font-size: 0.9rem;
  text-decoration: none;
}

.header {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.header h1 {
  margin: 0;
  font-size: 1.6rem;
}

.edit-link {
  margin-left: auto;
  font-size: 0.9rem;
  text-decoration: none;
}

.done {
  padding: 0.1rem 0.5rem;
  border-radius: 999px;
  background: var(--color-accent);
  color: var(--color-accent-text);
  font-size: 0.75rem;
}

.video {
  margin-top: 1rem;
}

.content {
  max-width: 44rem;
  margin-top: 1.25rem;
}

.content p {
  margin: 0 0 1rem;
  white-space: pre-wrap;
}

.attachments,
.quiz {
  max-width: 44rem;
  margin-top: 2rem;
  padding-top: 1.25rem;
  border-top: 1px solid var(--color-border);
}

.attachments h2,
.quiz h2 {
  margin: 0 0 0.5rem;
  font-size: 1.1rem;
}

.attachments ul {
  margin: 0;
  padding: 0;
  list-style: none;
}

.attachments li {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.35rem 0;
}

.muted {
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.question {
  margin-top: 1.25rem;
}

.question__text {
  margin: 0 0 0.5rem;
  font-weight: 500;
}

.option {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.2rem 0;
}

.quiz__actions,
.actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-top: 1.5rem;
}

.result {
  margin: 0;
  color: var(--color-danger);
  font-weight: 500;
}

.result--passed {
  color: var(--color-accent);
}
</style>
