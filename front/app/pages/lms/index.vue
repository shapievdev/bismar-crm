<script setup lang="ts">
import type { Course } from '~/types/lms'

definePageMeta({ middleware: 'auth', permission: 'courses.view' })
useHead({ title: 'Обучение' })

const { fetchCourses, myCourses } = useLmsApi()
const { can } = useAuth()
const route = useRoute()
const router = useRouter()

type Tab = 'all' | 'mine' | 'drafts'

const search = ref(typeof route.query.search === 'string' ? route.query.search : '')
const tab = ref<Tab>(
  ['all', 'mine', 'drafts'].includes(String(route.query.tab)) ? route.query.tab as Tab : 'all',
)

const { data, pending, error } = await useAsyncData(
  'lms.catalogue',
  async () => {
    const [courses, enrolments] = await Promise.all([
      fetchCourses({
        search: search.value || undefined,
        status: tab.value === 'drafts' ? 'draft' : undefined,
      }),
      myCourses(),
    ])

    // The catalogue endpoint does not know who is enrolled, so progress is
    // stitched in from the learner's own enrolments.
    const progressBySlug = new Map(
      enrolments.data
        .filter(item => item.course)
        .map(item => [item.course!.slug, item]),
    )

    const withProgress: Course[] = courses.data.map(course => ({
      ...course,
      enrollment: progressBySlug.has(course.slug)
        ? {
            id: progressBySlug.get(course.slug)!.id,
            enrolled_at: progressBySlug.get(course.slug)!.enrolled_at,
            completed_at: progressBySlug.get(course.slug)!.completed_at,
            is_completed: progressBySlug.get(course.slug)!.is_completed,
            progress: progressBySlug.get(course.slug)!.progress ?? 0,
            completed_lesson_ids: progressBySlug.get(course.slug)!.completed_lesson_ids ?? [],
          }
        : null,
    }))

    return { courses: withProgress, total: courses.meta.total }
  },
  { watch: [search, tab] },
)

watchEffect(() => {
  router.replace({
    query: {
      ...(search.value ? { search: search.value } : {}),
      ...(tab.value === 'all' ? {} : { tab: tab.value }),
    },
  })
})

const visibleCourses = computed(() => {
  const courses = data.value?.courses ?? []

  return tab.value === 'mine' ? courses.filter(course => course.enrollment) : courses
})

const inProgressCount = computed(
  () => (data.value?.courses ?? []).filter(c => c.enrollment && !c.enrollment.is_completed).length,
)
const completedCount = computed(
  () => (data.value?.courses ?? []).filter(c => c.enrollment?.is_completed).length,
)

const tabs: { id: Tab, label: string, visible: boolean }[] = [
  { id: 'all', label: 'Все курсы', visible: true },
  { id: 'mine', label: 'Мои', visible: true },
  { id: 'drafts', label: 'Черновики', visible: can('courses.update') },
]
</script>

<template>
  <section>
    <header class="head">
      <div>
        <h1 class="page-title">
          Обучение
        </h1>
        <p class="page-subtitle">
          Курсы для команды — проходите последовательно, прогресс сохраняется автоматически.
        </p>
      </div>

      <NuxtLink v-if="can('courses.create')" to="/lms/new" class="button-primary">
        Новый курс
      </NuxtLink>
    </header>

    <div v-if="inProgressCount || completedCount" class="stats">
      <div class="stat">
        <span class="stat__value">{{ inProgressCount }}</span>
        <span class="stat__label">в процессе</span>
      </div>
      <div class="stat">
        <span class="stat__value">{{ completedCount }}</span>
        <span class="stat__label">пройдено</span>
      </div>
      <div class="stat">
        <span class="stat__value">{{ data?.total ?? 0 }}</span>
        <span class="stat__label">всего доступно</span>
      </div>
    </div>

    <div class="toolbar">
      <div class="tabs" role="tablist">
        <button
          v-for="item in tabs.filter(t => t.visible)"
          :key="item.id"
          type="button"
          role="tab"
          class="tab"
          :class="{ 'tab--active': tab === item.id }"
          :aria-selected="tab === item.id"
          @click="tab = item.id"
        >
          {{ item.label }}
        </button>
      </div>

      <input
        v-model.trim="search"
        type="search"
        class="input search"
        placeholder="Поиск по курсам…"
        aria-label="Поиск по курсам"
      >
    </div>

    <p v-if="error" class="alert alert--danger" role="alert">
      Не удалось загрузить курсы.
    </p>

    <div v-else-if="pending" class="grid">
      <div v-for="n in 3" :key="n" class="card skeleton-card">
        <div class="skeleton skeleton-card__cover" />
        <div class="skeleton-card__body">
          <div class="skeleton skeleton-line skeleton-line--short" />
          <div class="skeleton skeleton-line" />
          <div class="skeleton skeleton-line skeleton-line--half" />
        </div>
      </div>
    </div>

    <UiEmptyState
      v-else-if="!visibleCourses.length"
      :title="tab === 'mine' ? 'Вы пока не записаны ни на один курс' : 'Курсов пока нет'"
      :description="tab === 'mine'
        ? 'Откройте вкладку «Все курсы» и запишитесь на подходящий.'
        : (search ? 'Попробуйте изменить запрос.' : 'Как только появятся курсы, они будут здесь.')"
    >
      <button v-if="tab === 'mine'" type="button" class="button-secondary" @click="tab = 'all'">
        Ко всем курсам
      </button>
      <NuxtLink v-else-if="can('courses.create')" to="/lms/new" class="button-primary">
        Создать первый курс
      </NuxtLink>
    </UiEmptyState>

    <div v-else class="grid">
      <CourseCard v-for="course in visibleCourses" :key="course.slug" :course="course" />
    </div>
  </section>
</template>

<style scoped>
.head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1.5rem;
}

.stats {
  display: flex;
  gap: 0.75rem;
  margin-bottom: 1.5rem;
}

.stat {
  flex: 1;
  max-width: 11rem;
  padding: 0.8rem 1rem;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
}

.stat__value {
  display: block;
  font-size: 1.5rem;
  font-weight: 650;
  line-height: 1.1;
  font-variant-numeric: tabular-nums;
}

.stat__label {
  color: var(--color-text-muted);
  font-size: 0.82rem;
}

.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 1.25rem;
}

.tabs {
  display: flex;
  gap: 0.2rem;
  padding: 0.2rem;
  background: var(--color-surface-sunken);
  border-radius: var(--radius);
}

.tab {
  padding: 0.4rem 0.85rem;
  border: none;
  border-radius: var(--radius-sm);
  background: transparent;
  color: var(--color-text-muted);
  font: inherit;
  font-size: 0.9rem;
  font-weight: 500;
  cursor: pointer;
}

.tab--active {
  background: var(--color-surface);
  color: var(--color-text);
  box-shadow: var(--shadow-sm);
}

.search {
  width: auto;
  min-width: 15rem;
  flex: 0 1 20rem;
}

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(17rem, 1fr));
  gap: 1rem;
}

.skeleton-card {
  overflow: hidden;
}

.skeleton-card__cover {
  aspect-ratio: 16 / 9;
  border-radius: 0;
}

.skeleton-card__body {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding: 1rem;
}

.skeleton-line {
  height: 0.7rem;
}

.skeleton-line--short { width: 35%; }
.skeleton-line--half { width: 60%; }
</style>