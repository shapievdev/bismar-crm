<script setup lang="ts">
import type { MaterialSection, RegulationCategory } from '~/types/lms'

/**
 * Дерево категорий раздела — общий экран для документов и справочников.
 */
const props = defineProps<{ section: MaterialSection }>()

const copy = useMaterialSection(props.section)

useHead({ title: copy.categoriesTitle })

const { fetchCategories, createCategory, updateCategory, deleteCategory } = useMaterialsApi(props.section)
const { can } = useAuth()

const { data, pending, error, refresh } = await useAsyncData(
  `lms.${props.section}.categories.manage`,
  () => fetchCategories(),
)

const categories = computed(() => data.value?.data ?? [])

/** The tree flattened for display; nesting is shown by indentation. */
const flat = computed(() => {
  const rows: { category: RegulationCategory, depth: number }[] = []

  const walk = (nodes: RegulationCategory[], depth: number) => {
    for (const node of nodes) {
      rows.push({ category: node, depth })
      walk(node.children ?? [], depth + 1)
    }
  }

  walk(categories.value, 0)

  return rows
})

/**
 * Siblings share a parent, so only they can be reordered against each other.
 *
 * И одну отметку: важные стоят впереди всех остальных, и стрелка, переносящая
 * категорию через эту границу, ничего бы не сдвинула — порядок пересилил бы
 * её. Границу переходят галочкой «важная», а не стрелкой.
 */
function siblingsOf(category: RegulationCategory): RegulationCategory[] {
  const rows = flat.value.map(row => row.category)

  return rows.filter(item =>
    item.parent_id === category.parent_id
    && item.is_important === category.is_important)
}

const editingSlug = ref<string | null>(null)
const isCreating = ref(false)
const draft = reactive<{
  name: string
  description: string
  parent_id: number | null
  is_important: boolean
}>({
  name: '',
  description: '',
  parent_id: null,
  is_important: false,
})
const busy = ref(false)
const actionError = ref<string | null>(null)

async function run(operation: () => Promise<unknown>) {
  busy.value = true
  actionError.value = null

  try {
    await operation()
    await refresh()
    isCreating.value = false
    editingSlug.value = null
  }
  catch (caught) {
    const failure = caught as { data?: { message?: string, errors?: Record<string, string[]> } }
    actionError.value = failure.data?.errors?.name?.[0]
      ?? failure.data?.message
      ?? 'Не удалось сохранить категорию.'
  }
  finally {
    busy.value = false
  }
}

function startCreate() {
  isCreating.value = true
  editingSlug.value = null
  draft.name = ''
  draft.description = ''
  draft.parent_id = null
  draft.is_important = false
}

function startEdit(category: RegulationCategory) {
  editingSlug.value = category.slug
  isCreating.value = false
  draft.name = category.name
  draft.description = category.description ?? ''
  draft.parent_id = category.parent_id
  draft.is_important = category.is_important
}

function save() {
  const body = {
    name: draft.name,
    description: draft.description || null,
    parent_id: draft.parent_id,
    is_important: draft.is_important,
  }

  return run(() => editingSlug.value
    ? updateCategory(editingSlug.value, body)
    : createCategory(body))
}

/** Reordering swaps positions, which the API takes directly. */
async function move(siblings: RegulationCategory[], index: number, delta: number) {
  const current = siblings[index]
  const neighbour = siblings[index + delta]

  if (!current || !neighbour) {
    return
  }

  await run(async () => {
    await updateCategory(current.slug, {
      name: current.name,
      description: current.description,
      parent_id: current.parent_id,
      position: neighbour.position,
    })
    await updateCategory(neighbour.slug, {
      name: neighbour.name,
      description: neighbour.description,
      parent_id: neighbour.parent_id,
      position: current.position,
    })
  })
}
</script>

<template>
  <section>
    <header class="head">
      <div>
        <h1 class="page-title">
          {{ copy.categoriesTitle }}
        </h1>
        <p class="page-subtitle">
          {{ copy.categoriesSubtitle }} Категорию можно упразднить — материалы
          при этом остаются.
        </p>
      </div>

      <div class="head__actions">
        <button type="button" class="button-primary" @click="startCreate">
          Новая категория
        </button>
      </div>
    </header>

    <p v-if="error" class="alert alert--danger" role="alert">
      Не удалось загрузить категории.
    </p>

    <p v-if="actionError" class="alert alert--danger" role="alert">
      {{ actionError }}
    </p>

    <form v-if="isCreating || editingSlug" class="card editor" @submit.prevent="save">
      <div class="editor__fields">
        <input v-model.trim="draft.name" class="input" placeholder="Название" required>
        <input v-model.trim="draft.description" class="input" placeholder="Описание (необязательно)">
        <CategoryTreeSelect
          v-model="draft.parent_id"
          :categories="categories"
          :exclude-id="editingSlug ? categories.flatMap(c => [c, ...(c.children ?? [])]).find(c => c.slug === editingSlug)?.id : null"
        />
      </div>

      <label class="choice">
        <input v-model="draft.is_important" type="checkbox">
        Важная категория
      </label>

      <div class="editor__actions">
        <button type="submit" class="button-primary" :disabled="busy || !draft.name">
          Сохранить
        </button>
        <button
          type="button"
          class="button-ghost"
          @click="isCreating = false; editingSlug = null"
        >
          Отмена
        </button>
      </div>
    </form>

    <p v-if="pending" class="muted">
      Загрузка…
    </p>

    <UiEmptyState
      v-else-if="!categories.length"
      title="Категорий пока нет"
      :description="`Категории помогают разложить раздел «${copy.title}» по темам.`"
    >
      <button type="button" class="button-primary" @click="startCreate">
        Создать первую
      </button>
    </UiEmptyState>

    <ul v-else class="list">
      <li
        v-for="row in flat"
        :key="row.category.slug"
        class="card row"
        :class="{ 'row--important': row.category.is_important }"
        :style="{ marginLeft: `${row.depth * 1.5}rem` }"
      >
        <div class="row__body">
          <span class="row__name">
            <span v-if="row.depth > 0" class="faint" aria-hidden="true">└ </span>
            {{ row.category.name }}
          </span>
          <span v-if="row.category.description" class="faint">{{ row.category.description }}</span>
        </div>

        <!-- Словом, а не одним цветом: цвет не читают ни голосом, ни глазом,
             который его не различает. -->
        <span v-if="row.category.is_important" class="badge badge--danger">
          Важная
        </span>

        <span class="badge">
          {{ counted(row.category.regulations_count ?? 0, copy.counted) }}
        </span>

        <div class="row__actions">
          <button
            type="button"
            class="button-ghost button-sm"
            :disabled="busy || siblingsOf(row.category).indexOf(row.category) === 0"
            @click="move(siblingsOf(row.category), siblingsOf(row.category).indexOf(row.category), -1)"
          >
            ↑
          </button>
          <button
            type="button"
            class="button-ghost button-sm"
            :disabled="busy || siblingsOf(row.category).indexOf(row.category) === siblingsOf(row.category).length - 1"
            @click="move(siblingsOf(row.category), siblingsOf(row.category).indexOf(row.category), 1)"
          >
            ↓
          </button>
          <button type="button" class="button-secondary button-sm" :disabled="busy" @click="startEdit(row.category)">
            Изменить
          </button>
          <button
            v-if="can('courses.delete')"
            type="button"
            class="button-danger button-sm"
            :disabled="busy"
            @click="run(() => deleteCategory(row.category.slug))"
          >
            Удалить
          </button>
        </div>
      </li>
    </ul>
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

.head__actions {
  display: flex;
  gap: 0.5rem;
}

.head__actions a {
  text-decoration: none;
}

.editor {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
  padding: 1rem 1.15rem;
  margin-bottom: 1.25rem;
}

.editor__fields {
  display: flex;
  flex: 1;
  gap: 0.6rem;
  min-width: 18rem;
}

.editor__actions {
  display: flex;
  gap: 0.5rem;
}

.choice {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.9rem;
  white-space: nowrap;
}

.list {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.row {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0.85rem 1.1rem;
}

.row__body {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-width: 0;
  gap: 0.1rem;
  font-size: 0.92rem;
}

.row__name {
  font-weight: 550;
}

/* Важную категорию видно строкой целиком: в длинном дереве значок сбоку
   теряется, а подложка с рамкой держит взгляд. Рамка обводкой внутрь, а не
   настоящей: настоящая сдвинула бы строку на пиксель относительно соседних. */
.row--important {
  background: var(--color-danger-soft);
  outline: 1px solid var(--color-danger);
  outline-offset: -1px;
}

.row--important .row__name {
  color: var(--color-danger);
}

.row__actions {
  display: flex;
  gap: 0.35rem;
}

.muted {
  color: var(--color-text-muted);
}

@media (max-width: 48rem) {
  .head {
    flex-direction: column;
    align-items: stretch;
    gap: 0.9rem;
  }

  .head__actions > * {
    flex: 1;
    justify-content: center;
  }

  .editor__fields {
    flex-direction: column;
    min-width: 0;
  }

  /* The row stacks so the action buttons keep their labels instead of being
     squeezed into unreadable stubs. */
  .row {
    flex-wrap: wrap;
    row-gap: 0.6rem;
  }

  .row__body {
    flex-basis: 100%;
  }

  .row__actions {
    flex-wrap: wrap;
  }
}
</style>