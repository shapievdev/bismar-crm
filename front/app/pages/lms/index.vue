<script setup lang="ts">
import type { Category, Course } from '~/types/lms'

definePageMeta({ middleware: 'auth', permission: 'courses.view' })
useHead({ title: 'База знаний' })

const { fetchCourses, myCourses, fetchCategories } = useLmsApi()
const { can } = useAuth()
const route = useRoute()
const router = useRouter()

/**
 * A status filter. Each tab shows exactly one status, so its label is always
 * true — an editor's default view used to be labelled "Опубликованные" while
 * quietly including drafts and archived material.
 */
type Tab = 'published' | 'drafts' | 'archived'

const STATUS_BY_TAB: Record<Tab, string> = {
  published: 'published',
  drafts: 'draft',
  archived: 'archived',
}

const search = ref(typeof route.query.search === 'string' ? route.query.search : '')
const category = ref(typeof route.query.category === 'string' ? route.query.category : '')
const tab = ref<Tab>(
  ['published', 'drafts', 'archived'].includes(String(route.query.tab))
    ? route.query.tab as Tab
    : 'published',
)

const page = ref(pageFromQuery(route.query.page))

/**
 * Reference data, fetched once.
 *
 * Neither the category tree nor the learner's own enrolments depend on which
 * filter or page the catalogue is showing, so refetching them alongside every
 * page turn would be three requests where one will do.
 *
 * Половины независимы, и падать вместе им незачем. Через Promise.all отказ
 * одного запроса обнулял оба: сломанные записи на курсы уносили с собой дерево
 * категорий, которое к ним отношения не имеет.
 */
const { data: reference } = await useAsyncData('lms.catalogue.reference', async () => {
  const [enrolments, categories] = await Promise.allSettled([myCourses(), fetchCategories()])

  return {
    enrolments: enrolments.status === 'fulfilled' ? enrolments.value.data : [],
    categories: categories.status === 'fulfilled' ? categories.value.data : [],
  }
})

const { data, pending, error } = await useAsyncData(
  'lms.catalogue.courses',
  () => fetchCourses({
    search: search.value || undefined,
    category: category.value || undefined,
    status: STATUS_BY_TAB[tab.value],
    page: page.value > 1 ? page.value : undefined,
  }),
  { watch: [search, tab, category, page] },
)

// Narrowing the results moves the ground under the current page: page 4 of the
// whole catalogue is rarely page 4 of one category, and is often past its end.
watch([search, tab, category], () => {
  page.value = 1
})

watchEffect(() => {
  router.replace({
    query: {
      ...(search.value ? { search: search.value } : {}),
      ...(category.value ? { category: category.value } : {}),
      ...(tab.value === 'published' ? {} : { tab: tab.value }),
      ...(page.value > 1 ? { page: String(page.value) } : {}),
    },
  })
})

/** The learner's enrolment for a course, by slug. */
const enrolmentBySlug = computed(() => new Map(
  (reference.value?.enrolments ?? [])
    .filter(item => item.course)
    .map(item => [item.course!.slug, item]),
))

/**
 * The catalogue endpoint does not know who is enrolled, so progress is
 * stitched in from the learner's own enrolments.
 */
const visibleCourses = computed<Course[]>(() => (data.value?.data ?? []).map((course) => {
  const enrolment = enrolmentBySlug.value.get(course.slug)

  return {
    ...course,
    enrollment: enrolment
      ? {
          id: enrolment.id,
          enrolled_at: enrolment.enrolled_at,
          completed_at: enrolment.completed_at,
          is_completed: enrolment.is_completed,
          progress: enrolment.progress ?? 0,
          completed_lesson_ids: enrolment.completed_lesson_ids ?? [],
        }
      : null,
  }
}))

const total = computed(() => data.value?.meta.total ?? 0)
const currentPage = computed(() => data.value?.meta.current_page ?? 1)
const lastPage = computed(() => data.value?.meta.last_page ?? 1)

const grid = useTemplateRef<HTMLElement>('grid')

function goToPage(next: number) {
  const target = Math.min(Math.max(1, next), lastPage.value)

  if (target === page.value) {
    return
  }

  page.value = target

  // Otherwise the next page opens halfway down, wherever the last one was left.
  grid.value?.scrollIntoView({ block: 'start' })
}

function pageFromQuery(value: unknown): number {
  const parsed = Number(value)

  return Number.isInteger(parsed) && parsed > 1 ? parsed : 1
}

const categoryTree = computed<Category[]>(() => reference.value?.categories ?? [])

/**
 * Дорога от корня до выбранной категории: в адресе лежит один slug, а крошкам
 * нужен весь путь. Считает общая утилита — той же дорогой ходят крошки на
 * карточке материала и в документах.
 */
const currentPath = computed(() => categoryTrail(categoryTree.value, category.value))

const currentCategory = computed(() => currentPath.value.at(-1) ?? null)

/** What is on offer here: the top level, or the sections of the current one. */
const sections = computed(() => currentCategory.value?.children ?? categoryTree.value)

/**
 * Everything under a category, itself included.
 *
 * Choosing a category lists its nested material too, so the tile has to
 * promise the same number the click delivers.
 */
function branchCount(node: Category): number {
  return (node.courses_count ?? 0)
    + (node.children ?? []).reduce((total, child) => total + branchCount(child), 0)
}

function materialsLabel(count: number): string {
  return `${count} ${pluralise(count, 'курс', 'курса', 'курсов')}`
}

function sectionsLabel(count: number): string {
  return `${count} ${pluralise(count, 'раздел', 'раздела', 'разделов')}`
}

/**
 * Status, not navigation: "Мои материалы" is its own page in the module bar,
 * so repeating it here would put the same view in two places.
 *
 * A learner only ever sees published material — the API refuses the rest — so
 * for them the row is a single tab and the filter is not really a choice.
 */
const tabs: { id: Tab, label: string, visible: boolean }[] = [
  { id: 'published', label: 'Опубликованные', visible: true },
  { id: 'drafts', label: 'Черновики', visible: can('courses.update') },
  // Kept reachable: archived material is out of circulation, not deleted, and
  // filtering it out of every tab would leave no way back to it.
  { id: 'archived', label: 'В архиве', visible: can('courses.update') },
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
          Курсы команды по категориям. Прогресс сохраняется сам, записываться не нужно.
        </p>

        <!-- Число найденного — строкой, а не плиткой: оно уточняет список, а не
             спорит с ним за внимание. Свои «в процессе» и «пройдено» человек
             смотрит у себя, на «Моих курсах», — там они и живут. -->
        <p v-if="total" class="faint counted">
          {{ total }} {{ pluralise(total, 'курс', 'курса', 'курсов') }}
          <template v-if="currentCategory"> в этом разделе</template>
        </p>
      </div>

      <div class="head__actions">
        <NuxtLink v-if="can('courses.create')" to="/lms/new" class="button-primary">
          Новый курс
        </NuxtLink>
      </div>
    </header>

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

    <nav v-if="categoryTree.length" class="crumbs" aria-label="Категории">
      <span v-if="!category" class="crumbs__current">Все категории</span>
      <button v-else type="button" class="crumbs__link" @click="category = ''">
        Все категории
      </button>

      <template v-for="(node, index) in currentPath" :key="node.slug">
        <span class="crumbs__separator" aria-hidden="true">/</span>
        <span v-if="index === currentPath.length - 1" class="crumbs__current" aria-current="page">
          {{ node.name }}
        </span>
        <button v-else type="button" class="crumbs__link" @click="category = node.slug">
          {{ node.name }}
        </button>
      </template>
    </nav>

    <div v-if="sections.length" class="tiles">
      <button
        v-for="node in sections"
        :key="node.slug"
        type="button"
        class="card card--raised tile"
        @click="category = node.slug"
      >
        <span class="tile__name">{{ node.name }}</span>

        <span v-if="node.description" class="tile__description">{{ node.description }}</span>

        <span class="tile__meta">
          {{ materialsLabel(branchCount(node)) }}
          <template v-if="node.children?.length">
            · {{ sectionsLabel(node.children.length) }}
          </template>
        </span>
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
      title="Курсов пока нет"
      :description="search || category
        ? 'Попробуйте изменить запрос или категорию.'
        : 'Как только появятся курсы, они будут здесь.'"
    >
      <NuxtLink v-if="can('courses.create')" to="/lms/new" class="button-primary">
        Создать первый курс
      </NuxtLink>
    </UiEmptyState>

    <div v-else ref="grid" class="grid">
      <CourseCard v-for="course in visibleCourses" :key="course.slug" :course="course" />
    </div>

    <nav v-if="lastPage > 1" class="pager" aria-label="Страницы каталога">
      <button
        type="button"
        class="button-secondary button-sm"
        :disabled="currentPage <= 1 || pending"
        @click="goToPage(currentPage - 1)"
      >
        ← Назад
      </button>

      <span class="pager__position" aria-live="polite">
        Страница {{ currentPage }} из {{ lastPage }}
      </span>

      <button
        type="button"
        class="button-secondary button-sm"
        :disabled="currentPage >= lastPage || pending"
        @click="goToPage(currentPage + 1)"
      >
        Вперёд →
      </button>
    </nav>
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


.counted {
  margin: 0.4rem 0 0;
}

.head__actions {
  display: flex;
  gap: 0.5rem;
}

.head__actions a {
  text-decoration: none;
}

.pager {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 1rem;
  margin-top: 1.75rem;
}

.pager__position {
  color: var(--color-text-muted);
  font-size: 0.88rem;
  font-variant-numeric: tabular-nums;
  /* Fixed enough not to shuffle the buttons as the numbers grow. */
  min-width: 9rem;
  text-align: center;
}

/* Where you are in the tree, and the way back up. */
.crumbs {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem;
  margin-bottom: 0.9rem;
  font-size: 0.92rem;
}

.crumbs__link {
  padding: 0;
  border: 0;
  background: none;
  color: var(--color-text-muted);
  font: inherit;
  cursor: pointer;
  transition: color 0.15s ease;
}

.crumbs__link:hover {
  color: var(--color-text);
}

.crumbs__current {
  font-weight: 550;
}

.crumbs__separator {
  color: var(--color-text-faint);
}

/*
 * Categories are the way into the material, so they get room to be read and
 * aimed at rather than a row of pills competing with the filters above them.
 */
.tiles {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(13.5rem, 1fr));
  gap: 0.75rem;
  margin-bottom: 1.5rem;
}

.tile {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.3rem;
  padding: 1rem 1.15rem;
  border: 0;
  color: var(--color-text);
  font: inherit;
  text-align: left;
  cursor: pointer;
  /* Only the shadow moves: repainting the surface on hover is what makes a
     label collide with its own background. */
  transition: box-shadow 0.15s ease;
}

.tile:hover {
  box-shadow: var(--shadow-md);
}

.tile__name {
  font-size: 1rem;
  font-weight: 550;
}

.tile__description {
  color: var(--color-text-muted);
  font-size: 0.85rem;
  /* Two lines, so a long description cannot make one tile tower over its
     neighbours. */
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  overflow: hidden;
}

.tile__meta {
  margin-top: auto;
  padding-top: 0.35rem;
  color: var(--color-text-faint);
  font-size: 0.82rem;
  font-variant-numeric: tabular-nums;
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

/* Same reason as the chips: the accent-filled tab needs its own hover, or the
   base rule repaints its label in the page's text colour and it vanishes. */
.tab:hover:not(.tab--active) {
  color: var(--color-text);
}

.tab--active {
  background: var(--color-accent);
  color: var(--color-accent-text);
}

.tab--active:hover {
  background: var(--color-accent-hover);
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

/*
 * Narrow screens: the header, its actions and the toolbar each get their own
 * row. Side by side they either overflow the viewport or squeeze the title
 * into two words per line.
 */
@media (max-width: 48rem) {
  .head {
    flex-direction: column;
    align-items: stretch;
    gap: 0.9rem;
  }

  .head__actions a {
    flex: 1;
    justify-content: center;
  }

  .toolbar {
    flex-direction: column;
    align-items: stretch;
  }

  .tabs {
    overflow-x: auto;
    scrollbar-width: none;
  }

  .tabs::-webkit-scrollbar {
    display: none;
  }

  .tab {
    flex-shrink: 0;
  }

  .search {
    flex: 1 1 auto;
    width: 100%;
    min-width: 0;
  }

  /* Two per row on a phone: one full-width tile per line would push the
     material itself off the screen. */
  .tiles {
    grid-template-columns: repeat(auto-fill, minmax(9rem, 1fr));
    gap: 0.5rem;
  }

  .tile {
    padding: 0.8rem 0.9rem;
  }

  .grid {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>