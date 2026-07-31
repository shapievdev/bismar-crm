<script setup lang="ts">
definePageMeta({ middleware: 'auth', permission: 'courses.view' })

const route = useRoute()
const { fetchCourse, enroll } = useLmsApi()
const { can } = useAuth()

const slug = computed(() => String(route.params.slug))

const { data, error, refresh } = await useAsyncData(
  () => `lms.course.${slug.value}`,
  () => fetchCourse(slug.value),
)

if (error.value) {
  throw createError({ statusCode: 404, statusMessage: 'Курс не найден', fatal: true })
}

const course = computed(() => data.value?.data)
useHead(() => ({ title: course.value?.title ?? 'Курс' }))

const enrollment = computed(() => course.value?.enrollment ?? null)
const isEnrolling = ref(false)
const enrollError = ref<string | null>(null)

function isLessonDone(lessonId: number): boolean {
  return enrollment.value?.completed_lesson_ids.includes(lessonId) ?? false
}

async function join() {
  isEnrolling.value = true
  enrollError.value = null

  try {
    await enroll(slug.value)
    await refresh()
  }
  catch (caught) {
    const conflict = caught as { data?: { message?: string } }
    enrollError.value = conflict.data?.message ?? 'Не удалось записаться на курс.'
  }
  finally {
    isEnrolling.value = false
  }
}
</script>

<template>
  <section v-if="course">
    <div class="topbar">
      <NuxtLink to="/lms" class="back">
        ← К курсам
      </NuxtLink>

      <NuxtLink v-if="can('courses.update')" :to="`/lms/${course.slug}/edit`" class="button-plain">
        Редактировать курс
      </NuxtLink>
    </div>

    <header class="header">
      <h1>{{ course.title }}</h1>
      <p v-if="course.summary" class="summary">
        {{ course.summary }}
      </p>

      <div class="meta">
        <span v-if="course.status !== 'published'" class="badge">{{ course.status_label }}</span>
        <span>{{ course.lessons_count ?? 0 }} уроков</span>
        <span v-if="course.author">Автор: {{ course.author.name }}</span>
      </div>
    </header>

    <div v-if="enrollment" class="progress">
      <div class="progress__bar" role="progressbar" :aria-valuenow="enrollment.progress" aria-valuemin="0" aria-valuemax="100">
        <div class="progress__fill" :style="{ width: `${enrollment.progress}%` }" />
      </div>
      <span class="progress__label">
        {{ enrollment.is_completed ? 'Курс пройден' : `Пройдено ${enrollment.progress}%` }}
      </span>
    </div>

    <div v-else class="enroll">
      <button type="button" class="button-primary" :disabled="isEnrolling" @click="join">
        {{ isEnrolling ? 'Записываем…' : 'Записаться на курс' }}
      </button>
      <span v-if="enrollError" class="error">{{ enrollError }}</span>
    </div>

    <p v-if="course.description" class="description">
      {{ course.description }}
    </p>

    <div v-for="module in course.modules ?? []" :key="module.id" class="module">
      <h2>{{ module.title }}</h2>
      <p v-if="module.description" class="muted">
        {{ module.description }}
      </p>

      <ol class="lessons">
        <li v-for="lesson in module.lessons ?? []" :key="lesson.id" class="lesson">
          <NuxtLink :to="`/lms/${course.slug}/lessons/${lesson.id}`" class="lesson__link">
            <span class="lesson__status" :class="{ 'lesson__status--done': isLessonDone(lesson.id) }">
              {{ isLessonDone(lesson.id) ? '✓' : '' }}
            </span>
            <span class="lesson__title">{{ lesson.title }}</span>
            <span v-if="lesson.has_quiz" class="badge">тест</span>
            <span v-if="lesson.duration_minutes" class="muted">{{ lesson.duration_minutes }} мин</span>
          </NuxtLink>
        </li>
      </ol>

      <p v-if="!(module.lessons ?? []).length" class="muted">
        В модуле пока нет уроков.
      </p>
    </div>

    <p v-if="!(course.modules ?? []).length" class="empty">
      {{ can('courses.update') ? 'Курс пуст — добавьте модули и уроки в редакторе.' : 'Курс ещё готовится.' }}
    </p>
  </section>
</template>

<style scoped>
.topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1rem;
}

.back {
  font-size: 0.9rem;
  text-decoration: none;
}

.button-plain {
  padding: 0.45rem 0.9rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface);
  color: var(--color-text);
  font-size: 0.9rem;
  text-decoration: none;
}

.header h1 {
  margin: 0 0 0.35rem;
  font-size: 1.75rem;
}

.summary {
  margin: 0 0 0.5rem;
  color: var(--color-text-muted);
}

.meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.badge {
  padding: 0.05rem 0.45rem;
  border: 1px solid var(--color-border);
  border-radius: 999px;
  font-size: 0.75rem;
}

.progress {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin: 1.5rem 0;
}

.progress__bar {
  flex: 1;
  height: 0.5rem;
  max-width: 24rem;
  background: var(--color-border);
  border-radius: 999px;
  overflow: hidden;
}

.progress__fill {
  height: 100%;
  background: var(--color-accent);
  transition: width 0.2s ease;
}

.progress__label {
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.enroll {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin: 1.5rem 0;
}

.error {
  color: var(--color-danger);
  font-size: 0.9rem;
}

.description {
  max-width: 44rem;
  white-space: pre-wrap;
}

.module {
  margin-top: 2rem;
}

.module h2 {
  margin: 0 0 0.25rem;
  font-size: 1.1rem;
}

.muted {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.lessons {
  margin: 0.75rem 0 0;
  padding: 0;
  list-style: none;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  overflow: hidden;
}

.lesson + .lesson {
  border-top: 1px solid var(--color-border);
}

.lesson__link {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.7rem 1rem;
  background: var(--color-surface);
  color: inherit;
  text-decoration: none;
}

.lesson__link:hover {
  background: var(--color-bg);
}

.lesson__status {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.25rem;
  height: 1.25rem;
  border: 1px solid var(--color-border);
  border-radius: 999px;
  font-size: 0.75rem;
}

.lesson__status--done {
  background: var(--color-accent);
  border-color: var(--color-accent);
  color: var(--color-accent-text);
}

.lesson__title {
  flex: 1;
}

.empty {
  padding: 2rem;
  margin-top: 1.5rem;
  border: 1px dashed var(--color-border);
  border-radius: var(--radius);
  color: var(--color-text-muted);
  text-align: center;
}
</style>
