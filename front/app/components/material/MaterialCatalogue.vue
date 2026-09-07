<script setup lang="ts">
import type { MaterialSection, RegulationCategory } from '~/types/lms'

/**
 * Каталог документов или справочников — один экран на оба раздела.
 *
 * Устроены они одинаково до последней кнопки, и разное в них — слова: их
 * держит useMaterialSection, а не два почти одинаковых экрана, разошедшихся бы
 * на первой же правке.
 */
const props = defineProps<{ section: MaterialSection }>()

const copy = useMaterialSection(props.section)

useHead({ title: copy.title })

const { can } = useAuth()
const { fetchRegulations, fetchCategories } = useMaterialsApi(props.section)

const route = useRoute()
const router = useRouter()

/**
 * Отбор по состоянию.
 *
 * Одна вкладка — одно состояние, и её подпись всегда правдива. Устроено так же,
 * как в каталоге курсов: разделы базы знаний должны читаться одинаково, иначе
 * человек, перешедший из курсов, ищет знакомые кнопки и не находит.
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

const { data: categoryData } = await useAsyncData(
  `lms.${props.section}.categories`,
  () => fetchCategories(),
)

const categoryTree = computed<RegulationCategory[]>(() => categoryData.value?.data ?? [])

/**
 * Показывать ли сами материалы.
 *
 * Раздел открывается списком категорий и ничего кроме них не показывает
 * (решение пользователя 2026-09-07): материалов десятки, и вываливать их все
 * на первый экран значит просить читателя листать вместо того, чтобы выбрать
 * категорию. Материалы появляются, когда человек в неё вошёл.
 *
 * Два исключения. Поиск отвечает по всему разделу — он на то и поиск, а не
 * отбор внутри категории. И пока категорий нет вовсе, каталог показывает
 * материалы: иначе экран был бы пуст, а материалы бы в нём были.
 */
const showsMaterials = computed(() =>
  Boolean(category.value) || search.value.trim() !== '' || categoryTree.value.length === 0,
)

const { data, pending, error } = await useAsyncData(
  `lms.${props.section}`,
  () => showsMaterials.value
    ? fetchRegulations({
        search: search.value || undefined,
        category: category.value || undefined,
        status: STATUS_BY_TAB[tab.value],
        page: page.value > 1 ? page.value : undefined,
      })
    // Спрашивать нечего: на корне показаны одни категории.
    : Promise.resolve({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }),
  { watch: [search, tab, category, page, showsMaterials] },
)

// Сузили список — прежняя страница ушла из-под ног: четвёртая страница всего
// раздела редко бывает четвёртой страницей одной категории.
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

const documents = computed(() => data.value?.data ?? [])

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

  // Иначе следующая страница открывается посередине — там, где бросили прошлую.
  grid.value?.scrollIntoView({ block: 'start' })
}

function pageFromQuery(value: unknown): number {
  const parsed = Number(value)

  return Number.isInteger(parsed) && parsed > 1 ? parsed : 1
}

/** Дорога от корня до выбранной категории — считает общая утилита. */
const currentPath = computed(() => categoryTrail(categoryTree.value, category.value))

const currentCategory = computed(() => currentPath.value.at(-1) ?? null)

/** Что предлагается здесь: верхний уровень или разделы текущего. */
const sections = computed(() => currentCategory.value?.children ?? categoryTree.value)

/**
 * Всё, что лежит под категорией, вместе с ней самой.
 *
 * Выбор категории показывает и вложенное в неё, поэтому плитка обязана обещать
 * то же число, которое даст нажатие.
 */
function branchCount(node: RegulationCategory): number {
  return (node.regulations_count ?? 0)
    + (node.children ?? []).reduce((sum, child) => sum + branchCount(child), 0)
}

function documentsLabel(count: number): string {
  return counted(count, copy.counted)
}

function sectionsLabel(count: number): string {
  return `${count} ${pluralise(count, 'раздел', 'раздела', 'разделов')}`
}

/**
 * Черновики и архив — только тем, кто правит документы: читателю сервер их всё
 * равно не отдаёт, и вкладка обещала бы пустоту.
 */
const tabs: { id: Tab, label: string, visible: boolean }[] = [
  { id: 'published', label: 'Опубликованные', visible: true },
  { id: 'drafts', label: 'Черновики', visible: can('courses.update') },
  { id: 'archived', label: 'В архиве', visible: can('courses.update') },
]
</script>

<template>
  <section>
    <header class="head">
      <div>
        <h1 class="page-title">
          {{ copy.title }}
        </h1>
        <p class="page-subtitle">
          {{ copy.subtitle }}
        </p>

        <p v-if="total" class="faint counted">
          {{ documentsLabel(total) }}
          <template v-if="currentCategory"> в этом разделе</template>
        </p>
      </div>

      <div class="head__actions">
        <!-- Дерево правят там же, где смотрят его содержимое: в полосе разделов
             трём спискам категорий не место — см. ModuleNav. -->
        <NuxtLink
          v-if="can('courses.update')"
          :to="`/lms/${copy.section}/categories`"
          class="button-secondary"
        >
          Категории
        </NuxtLink>
        <NuxtLink v-if="can('courses.create')" :to="`/lms/${copy.section}/new`" class="button-primary">
          {{ copy.createLabel }}
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
        :placeholder="`Поиск по разделу «${copy.title}»…`"
        :aria-label="`Поиск по разделу «${copy.title}»`"
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
          {{ documentsLabel(branchCount(node)) }}
          <template v-if="node.children?.length">
            · {{ sectionsLabel(node.children.length) }}
          </template>
        </span>
      </button>
    </div>

    <!-- Материалы — только внутри категории и в ответ на поиск: раздел
         открывается категориями, а не списком всего, что в нём есть. -->
    <template v-if="showsMaterials">
      <p v-if="error" class="alert alert--danger" role="alert">
        {{ `Не удалось загрузить раздел «${copy.title}».` }}
      </p>

      <div v-else-if="pending" class="grid">
        <div v-for="n in 3" :key="n" class="card card--raised skeleton-card">
          <div class="skeleton skeleton-line skeleton-line--short" />
          <div class="skeleton skeleton-line skeleton-line--title" />
          <div class="skeleton skeleton-line skeleton-line--half" />
        </div>
      </div>

      <UiEmptyState
        v-else-if="!documents.length"
        :title="copy.emptyCatalogue"
        :description="search || category
          ? 'Попробуйте изменить запрос или категорию.'
          : 'Заведите первый — он будет виден всем, кто читает базу знаний.'"
      >
        <NuxtLink v-if="can('courses.create')" :to="`/lms/${copy.section}/new`" class="button-primary">
          {{ copy.createLabel }}
        </NuxtLink>
      </UiEmptyState>

      <div v-else ref="grid" class="grid">
        <NuxtLink
          v-for="item in documents"
          :key="item.id"
          :to="`/lms/${copy.section}/${item.slug}`"
          class="card card--raised document"
        >
          <!-- Состояние сверху, как на карточке курса: сперва видно, что это
               за документ, потом уже как он называется. -->
          <div class="document__badges">
            <span v-if="!item.is_published" class="badge badge--warning">{{ item.status_label }}</span>
            <span v-if="item.is_private" class="badge" title="Виден только допущенным">Закрыт</span>
            <span v-if="item.is_acknowledged" class="badge badge--success">Ознакомлен</span>
            <span v-if="item.category" class="badge">{{ item.category.name }}</span>
          </div>

          <h2 class="document__title">
            {{ item.title }}
          </h2>

          <p v-if="item.summary" class="document__summary">
            {{ item.summary }}
          </p>
        </NuxtLink>
      </div>
    </template>

    <nav v-if="lastPage > 1" class="pager" :aria-label="`Страницы раздела «${copy.title}»`">
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

/* Где я в дереве и дорога обратно наверх. */
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

/* Категории — вход в материал, поэтому им дано место, а не строчка списка. */
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
  /* Двигается только тень: перекрашивать поверхность на наведении — верный
     способ столкнуть подпись с её же фоном. */
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

/* Выбранной вкладке нужен свой ховер: базовое правило перекрасило бы её
   подпись в цвет текста страницы, и на заливке она бы исчезла. */
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

.document {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding: 1.25rem 1.35rem 1.4rem;
  color: inherit;
  text-decoration: none;
  transition: box-shadow 0.15s ease;
}

.document:hover {
  box-shadow: var(--shadow-md);
}

.document__badges {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
}

.document__title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 550;
}

.document__summary {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.88rem;
  line-height: 1.45;
  /* Три строки: длинное описание не должно поднимать карточку над соседями. */
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 3;
  line-clamp: 3;
  overflow: hidden;
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
  min-width: 9rem;
  text-align: center;
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

  /* По две в ряд на телефоне: одна плитка во всю ширину вытолкнула бы сам
     материал за пределы экрана. */
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
