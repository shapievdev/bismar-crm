<script setup lang="ts">
definePageMeta({ middleware: 'auth', permission: 'courses.view' })

const route = useRoute()
const { fetchCourse } = useLmsApi()
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
const modules = computed(() => course.value?.modules ?? [])

const allLessons = computed(() => modules.value.flatMap(module => module.lessons ?? []))
const completedIds = computed(() => new Set(enrollment.value?.completed_lesson_ids ?? []))

function isDone(lessonId: number): boolean {
  return completedIds.value.has(lessonId)
}

/** Where a returning learner should pick up: the first lesson not yet done. */
const nextLesson = computed(() => allLessons.value.find(lesson => !isDone(lesson.id)) ?? null)

const totalMinutes = computed(() =>
  allLessons.value.reduce((total, lesson) => total + (lesson.duration_minutes ?? 0), 0),
)

</script>

<template>
  <section v-if="course">
    <nav class="crumbs">
      <NuxtLink to="/lms">
        База знаний
      </NuxtLink>
      <span aria-hidden="true">/</span>
      <span class="faint">{{ course.title }}</span>
    </nav>

    <header class="hero card">
      <div class="hero__body">
        <div class="hero__badges">
          <span v-if="course.status !== 'published'" class="badge badge--warning">
            {{ course.status_label }}
          </span>
          <span v-if="enrollment?.is_completed" class="badge badge--success">Курс пройден</span>
        </div>

        <h1 class="hero__title">
          {{ course.title }}
        </h1>

        <p v-if="course.summary" class="hero__summary">
          {{ course.summary }}
        </p>

        <div class="hero__meta">
          <span>{{ course.lessons_count ?? 0 }} {{ pluralise(course.lessons_count ?? 0, 'урок', 'урока', 'уроков') }}</span>
          <span v-if="totalMinutes">≈ {{ totalMinutes }} мин</span>
          <span v-if="course.category" class="badge">{{ course.category.name }}</span>
          <span v-if="course.author">Автор: {{ course.author.name }}</span>
        </div>

        <div class="hero__actions">
          <template v-if="enrollment">
            <NuxtLink
              v-if="nextLesson"
              :to="`/lms/${course.slug}/lessons/${nextLesson.id}`"
              class="button-primary"
            >
              {{ enrollment.progress > 0 ? 'Продолжить' : 'Начать обучение' }}
            </NuxtLink>
            <span v-else class="badge badge--success">Все уроки пройдены</span>
          </template>

          <NuxtLink
            v-else-if="nextLesson"
            :to="`/lms/${course.slug}/lessons/${nextLesson.id}`"
            class="button-primary"
          >
            Начать чтение
          </NuxtLink>

          <NuxtLink v-if="can('courses.update')" :to="`/lms/${course.slug}/edit`" class="button-secondary">
            Редактировать
          </NuxtLink>
        </div>

      </div>

      <aside v-if="enrollment" class="hero__progress">
        <UiProgressRing :value="enrollment.progress" :size="76" />
        <span class="faint">
          {{ completedIds.size }} из {{ allLessons.length }}
        </span>
      </aside>
    </header>

    <div class="layout">
      <div class="outline">
        <h2 class="section-title">
          Программа
        </h2>

        <UiEmptyState
          v-if="!modules.length"
          title="Курс пока пуст"
          :description="can('courses.update')
            ? 'Добавьте модули и уроки в редакторе.'
            : 'Материалы ещё готовятся.'"
        >
          <NuxtLink v-if="can('courses.update')" :to="`/lms/${course.slug}/edit`" class="button-primary">
            Открыть редактор
          </NuxtLink>
        </UiEmptyState>

        <section v-for="(module, index) in modules" :key="module.id" class="module card">
          <header class="module__head">
            <span class="module__index">{{ index + 1 }}</span>
            <div>
              <h3 class="module__title">
                {{ module.title }}
              </h3>
              <p v-if="module.description" class="muted module__desc">
                {{ module.description }}
              </p>
            </div>
          </header>

          <ol class="lessons">
            <li v-for="lesson in module.lessons ?? []" :key="lesson.id">
              <NuxtLink :to="`/lms/${course.slug}/lessons/${lesson.id}`" class="lesson">
                <span class="lesson__check" :class="{ 'lesson__check--done': isDone(lesson.id) }">
                  <template v-if="isDone(lesson.id)">✓</template>
                </span>
                <span class="lesson__title">{{ lesson.title }}</span>
                <span v-if="lesson.has_quiz" class="badge badge--accent">тест</span>
                <span v-if="lesson.duration_minutes" class="faint lesson__time">
                  {{ lesson.duration_minutes }} мин
                </span>
              </NuxtLink>
            </li>
          </ol>

          <p v-if="!(module.lessons ?? []).length" class="muted module__empty">
            В модуле пока нет уроков.
          </p>
        </section>
      </div>

      <aside v-if="course.description" class="about card">
        <h2 class="section-title section-title--tight">
          О курсе
        </h2>
        <p class="about__text">
          {{ course.description }}
        </p>
      </aside>
    </div>
  </section>
</template>

<style scoped>
.crumbs {
  display: flex;
  gap: 0.4rem;
  margin-bottom: 1rem;
  font-size: 0.87rem;
}

.crumbs a {
  text-decoration: none;
}

.hero {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 1.5rem;
  align-items: center;
  padding: 1.75rem 1.9rem;
  margin-bottom: 2rem;
}

.hero__badges {
  display: flex;
  gap: 0.35rem;
  margin-bottom: 0.4rem;
}

.hero__badges:empty {
  display: none;
}

.hero__title {
  margin: 0 0 0.4rem;
  font-size: 1.7rem;
  font-weight: 650;
}

.hero__summary {
  margin: 0 0 0.6rem;
  color: var(--color-text-muted);
}

.hero__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  margin-bottom: 1rem;
  color: var(--color-text-faint);
  font-size: 0.85rem;
}

.hero__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.6rem;
}

.hero__actions a {
  text-decoration: none;
}

.hero__progress {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.4rem;
  padding-left: 1.5rem;
  border-left: 1px solid var(--color-border);
  font-size: 0.82rem;
}

@media (max-width: 52rem) {
  .hero {
    grid-template-columns: 1fr;
  }

  .hero__progress {
    flex-direction: row;
    padding: 1rem 0 0;
    border-left: none;
    border-top: 1px solid var(--color-border);
  }
}

.layout {
  display: grid;
  grid-template-columns: 1fr;
  gap: 1.25rem;
}

@media (min-width: 60rem) {
  .layout {
    grid-template-columns: 1fr 18rem;
    align-items: start;
  }
}

.section-title {
  margin: 0 0 0.8rem;
  font-size: 1.1rem;
  font-weight: 600;
}

.section-title--tight {
  margin-bottom: 0.5rem;
}

.module {
  padding: 1rem 1.15rem;
  margin-bottom: 0.75rem;
}

.module__head {
  display: flex;
  gap: 0.75rem;
  align-items: flex-start;
}

.module__index {
  display: grid;
  place-items: center;
  width: 1.6rem;
  height: 1.6rem;
  flex-shrink: 0;
  border-radius: var(--radius-pill);
  background: var(--color-accent-soft);
  color: var(--color-accent);
  font-size: 0.8rem;
  font-weight: 600;
}

.module__title {
  margin: 0;
  font-size: 1rem;
  font-weight: 600;
}

.module__desc {
  margin: 0.1rem 0 0;
  font-size: 0.86rem;
}

.module__empty {
  margin: 0.75rem 0 0;
  font-size: 0.86rem;
}

.lessons {
  margin: 0.85rem 0 0;
  padding: 0;
  list-style: none;
}

.lesson {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  padding: 0.55rem 0.6rem;
  border-radius: var(--radius);
  color: inherit;
  text-decoration: none;
}

.lesson:hover {
  background: var(--color-surface-sunken);
}

.lesson__check {
  display: grid;
  place-items: center;
  width: 1.3rem;
  height: 1.3rem;
  flex-shrink: 0;
  border: 1.5px solid var(--color-border-strong);
  border-radius: var(--radius-pill);
  font-size: 0.72rem;
}

.lesson__check--done {
  background: var(--color-success);
  border-color: var(--color-success);
  color: #fff;
}

.lesson__title {
  flex: 1;
  font-size: 0.94rem;
}

.lesson__time {
  font-size: 0.8rem;
  font-variant-numeric: tabular-nums;
}

.about {
  padding: 1.1rem 1.2rem;
}

.about__text {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.9rem;
  white-space: pre-wrap;
}
</style>