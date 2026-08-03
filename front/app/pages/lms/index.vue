<script setup lang="ts">
import type { Category, Course } from '~/types/lms'

definePageMeta({ middleware: 'auth', permission: 'courses.view' })
useHead({ title: 'База знаний' })

const { fetchCourses, myCourses, fetchCategories } = useLmsApi()
const { can } = useAuth()
const route = useRoute()
const router = useRouter()

type Tab = 'all' | 'mine' | 'drafts'

const search = ref(typeof route.query.search === 'string' ? route.query.search : '')
const category = ref(typeof route.query.category === 'string' ? route.query.category : '')
const tab = ref<Tab>(
  ['all', 'mine', 'drafts'].includes(String(route.query.tab)) ? route.query.tab as Tab : 'all',
)

const { data, pending, error } = await useAsyncData(
  'lms.catalogue',
  async () => {
    const [courses, enrolments, categories] = await Promise.all([
      fetchCourses({
        search: search.value || undefined,
        category: category.value || undefined,
        status: tab.value === 'drafts' ? 'draft' : undefined,
      }),
      myCourses(),
      fetchCategories(),
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

    return { courses: withProgress, total: courses.meta.total, categories: categories.data }
  },
  { watch: [search, tab, category] },
)

watchEffect(() => {
  router.replace({
    query: {
      ...(search.value ? { search: search.value } : {}),
      ...(category.value ? { category: category.value } : {}),
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

/**
 * Categories arrive as a tree; the chip row is flat, so nesting is shown by
 * depth rather than by structure.
 */
const flatCategories = computed(() => {
  const flat: {
    slug: string
    name: string
    parentName: string | null
    courses_count?: number
  }[] = []

  const walk = (nodes: Category[], parentName: string | null) => {
    for (const node of nodes) {
      flat.push({
        slug: node.slug,
        name: node.name,
        parentName,
        courses_count: node.courses_count,
      })
      walk(node.children ?? [], node.name)
    }
  }

  walk(data.value?.categories ?? [], null)

  return flat
})

const tabs: { id: Tab, label: string, visible: boolean }[] = [
  { id: 'all', label: 'Всё', visible: true },
  { id: 'mine', label: 'Открытое мной', visible: true },
  { id: 'drafts', label: 'Черновики', visible: can('courses.update') },
]
</script>

<template>
  <section>
    <header class="head">
      <div>
        <h1 class="page-title">
          База знаний
        </h1>
        <p class="page-subtitle">
          Материалы команды по категориям. Прогресс сохраняется сам, записываться не нужно.
        </p>
      </div>

      <div class="head__actions">
        <NuxtLink v-if="can('courses.update')" to="/lms/categories" class="button-secondary">
          Категории
        </NuxtLink>
        <NuxtLink v-if="can('courses.create')" to="/lms/new" class="button-primary">
          Новый материал
        </NuxtLink>
      </div>
    </header>

    <div class="stats">
      <div class="card card--raised stat">
        <span class="metric">{{ inProgressCount }}</span>
        <span class="metric-label">в процессе</span>
      </div>
      <div class="card card--raised stat">
        <span class="metric">{{ completedCount }}</span>
        <span class="metric-label">пройдено</span>
      </div>
      <div class="card stat stat--muted">
        <span class="metric">{{ data?.total ?? 0 }}</span>
        <span class="metric-label">материалов всего</span>
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
        placeholder="Поиск по базе знаний…"
        aria-label="Поиск по базе знаний"
      >
    </div>

    <div v-if="(data?.categories.length ?? 0) > 0" class="chips">
      <button
        type="button"
        class="chip"
        :class="{ 'chip--active': category === '' }"
        @click="category = ''"
      >
        Все категории
      </button>
      <button
        v-for="item in flatCategories"
        :key="item.slug"
        type="button"
        class="chip"
        :class="{ 'chip--active': category === item.slug }"
        @click="category = item.slug"
      >
        <span v-if="item.parentName" class="chip__parent">{{ item.parentName }} /</span>
        {{ item.name }}
        <span class="chip__count">{{ item.courses_count ?? 0 }}</span>
      </button>
    </div>

    <p v-if="error" class="alert alert--danger" role="alert">
      Не удалось загрузить курсы.
    </p>

    <div v-else-if="pending" class="grid">
      <div v-for="n in 3" :key="n" class="card card--raised skeleton-card">
        <div class="skeleton skeleton-line skeleton-line--short" />
        <div class="skeleton skeleton-line skeleton-line--title" />
        <div class="skeleton skeleton-line" />
        <div class="skeleton skeleton-line skeleton-line--half" />
      </div>
    </div>

    <UiEmptyState
      v-else-if="!visibleCourses.length"
      :title="tab === 'mine' ? 'Вы ещё ничего не открывали' : 'Материалов пока нет'"
      :description="tab === 'mine'
        ? 'Откройте любой материал — он появится здесь вместе с прогрессом.'
        : (search || category ? 'Попробуйте изменить запрос или категорию.' : 'Как только появятся материалы, они будут здесь.')"
    >
      <button v-if="tab === 'mine'" type="button" class="button-secondary" @click="tab = 'all'">
        Ко всем материалам
      </button>
      <NuxtLink v-else-if="can('courses.create')" to="/lms/new" class="button-primary">
        Создать первый материал
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
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr));
  gap: 0.75rem;
  max-width: 34rem;
  margin-bottom: 1.75rem;
}

.stat {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  padding: 1.15rem 1.35rem 1.25rem;
}

.stat--muted .metric {
  color: var(--color-text-muted);
}

.head__actions {
  display: flex;
  gap: 0.5rem;
}

.head__actions a {
  text-decoration: none;
}

.chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin-bottom: 1.25rem;
}

.chip {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.35rem 0.85rem;
  border: 0;
  border-radius: var(--radius-pill);
  background: var(--color-surface);
  color: var(--color-text-muted);
  font: inherit;
  font-size: 0.85rem;
  cursor: pointer;
  transition: background-color 0.15s ease, color 0.15s ease;
}

.chip:hover {
  background: var(--color-surface-raised);
  color: var(--color-text);
}

.chip--active {
  background: var(--color-highlight);
  color: var(--color-highlight-text);
  font-weight: 500;
}

/* A chip row is flat, so nesting is named rather than drawn: the parent reads
   as a prefix, where an indent or a corner glyph would have nothing to line up
   against. */
.chip__parent {
  opacity: 0.55;
}

.chip__count {
  font-size: 0.78rem;
  opacity: 0.7;
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
  gap: 0.4rem;
}

.tab {
  padding: 0.5rem 1.05rem;
  border: none;
  border-radius: var(--radius-pill);
  background: var(--color-surface-raised);
  color: var(--color-text-muted);
  font: inherit;
  font-size: 0.92rem;
  cursor: pointer;
  transition: background-color 0.15s ease, color 0.15s ease;
}

.tab:hover {
  color: var(--color-text);
}

.tab--active {
  background: var(--color-accent);
  color: var(--color-accent-text);
}

.search {
  width: auto;
  min-width: 15rem;
  flex: 0 1 22rem;
}

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(18rem, 1fr));
  gap: 1rem;
}

.skeleton-card {
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
  padding: 1.25rem 1.35rem 1.4rem;
}

.skeleton-line {
  height: 0.7rem;
}

.skeleton-line--short { width: 35%; }
.skeleton-line--title { width: 75%; height: 1.1rem; }
.skeleton-line--half { width: 60%; }
</style>