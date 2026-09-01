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

/**
 * Крошки: раздел, категории по дороге сюда и сам материал.
 *
 * Категория у материала одна, а крошкам нужен весь путь наверх — поэтому здесь
 * дерево категорий. Отдельным запросом и с общим ключом: дерево одно на весь
 * раздел, и второй раз за него не ходят.
 */
const { fetchCategories } = useLmsApi()

const { data: categoryData } = await useAsyncData('lms.categories', () => fetchCategories())

const trail = computed(() => categoryTrail(categoryData.value?.data ?? [], course.value?.category?.slug))

</script>

<template>
  <section v-if="course">
    <nav class="crumbs" aria-label="Где я">
      <NuxtLink to="/lms">
        База знаний
      </NuxtLink>

      <template v-for="node in trail" :key="node.slug">
        <span class="crumbs__separator" aria-hidden="true">/</span>
        <NuxtLink :to="{ path: '/lms', query: { category: node.slug } }">
          {{ node.name }}
        </NuxtLink>
      </template>

      <span class="crumbs__separator" aria-hidden="true">/</span>
      <span class="faint" aria-current="page">{{ course.title }}</span>
    </nav>

    <header class="hero card">
      <div class="hero__body">
        <div class="hero__badges">
          <span v-if="course.status !== 'published'" class="badge badge--warning">
            {{ course.status_label }}
          </span>
          <!-- Читателю тоже важно: приватный курс не стоит пересказывать
               коллеге, которого в него не добавили. -->
          <span v-if="course.is_private" class="badge">Приватный</span>
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

      <div class="side">
        <aside v-if="course.description" class="about card">
          <h2 class="section-title section-title--tight">
            О курсе
          </h2>
          <p class="about__text">
            {{ course.description }}
          </p>
        </aside>

        <!-- К кому идти, если в материале ответа не нашлось. Здесь же, а не
             только в чате: человек, открывший курс, спрашивает по нему. -->
        <aside v-if="course.experts?.length" class="about card">
          <h2 class="section-title section-title--tight">
            Ответственные
          </h2>
          <p class="about__text">
            Напишите им, если в материалах курса не нашлось ответа.
          </p>
          <ul class="experts">
            <li v-for="person in course.experts" :key="person.id" class="experts__item">
              <UserAvatar :name="person.name" :src="person.avatar_url" :size="32" />
              <span class="experts__body">
                <span class="experts__name">{{ person.name }}</span>
                <!-- В мессенджер, а не на почту: разговор остаётся в системе,
                     рядом с материалом, о котором он идёт. -->
                <NuxtLink :to="`/messenger?write=${person.id}`" class="experts__write">
                  Написать
                </NuxtLink>
              </span>
            </li>
          </ul>
        </aside>
      </div>
    </div>
  </section>
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

.crumbs__separator {
  color: var(--color-text-faint);
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

/* Правая колонка держит несколько врезок подряд — «о курсе» и ответственных. */
.side {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.about {
  padding: 1.1rem 1.2rem;
}

.experts {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  margin: 0.85rem 0 0;
  padding: 0;
  list-style: none;
}

.experts__item {
  display: flex;
  align-items: center;
  gap: 0.6rem;
}

.experts__body {
  display: flex;
  flex-direction: column;
  min-width: 0;
  font-size: 0.88rem;
}

.experts__name {
  font-weight: 550;
}

/* Ссылка «Написать» — с воздухом вокруг: на телефоне это цель для пальца, а
   не подпись под именем. */
.experts__write {
  align-self: flex-start;
  padding: 0.15rem 0;
  font-size: 0.82rem;
}

.about__text {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.9rem;
  white-space: pre-wrap;
}

@media (max-width: 48rem) {
  .hero {
    grid-template-columns: minmax(0, 1fr);
    padding: 1.25rem;
  }

  .hero__title {
    font-size: 1.45rem;
  }

  /* The ring moves below the text and lies down beside its caption. */
  .hero__progress {
    flex-direction: row;
    justify-content: flex-start;
    gap: 0.75rem;
    padding: 1rem 0 0;
    border-left: 0;
    border-top: 1px solid var(--color-border);
  }

  .hero__actions a,
  .hero__actions button {
    flex: 1;
    justify-content: center;
  }

  .module {
    padding: 0.9rem 1rem;
  }

  .lesson {
    padding: 0.55rem 0.4rem;
  }

  .lesson__time {
    display: none;
  }
}
</style>