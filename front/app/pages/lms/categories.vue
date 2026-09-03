<script setup lang="ts">
import type { Category } from '~/types/lms'

definePageMeta({ middleware: 'auth', permission: 'courses.update' })
useHead({ title: 'Категории' })

const { fetchCategories, createCategory, updateCategory, deleteCategory } = useLmsApi()
const { can } = useAuth()

const { data, pending, error, refresh } = await useAsyncData(
  'lms.categories.manage',
  () => fetchCategories(),
)

const categories = computed(() => data.value?.data ?? [])

/** The tree flattened for display; nesting is shown by indentation. */
const flat = computed(() => {
  const rows: { category: Category, depth: number }[] = []

  const walk = (nodes: Category[], depth: number) => {
    for (const node of nodes) {
      rows.push({ category: node, depth })
      walk(node.children ?? [], depth + 1)
    }
  }

  walk(categories.value, 0)

  return rows
})

/** Siblings share a parent, so only they can be reordered against each other. */
function siblingsOf(parentId: number | null): Category[] {
  const rows = flat.value.map(row => row.category)

  return rows.filter(item => item.parent_id === parentId)
}

const editingSlug = ref<string | null>(null)
const isCreating = ref(false)
const draft = reactive<{ name: string, description: string, parent_id: number | null }>({
  name: '',
  description: '',
  parent_id: null,
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
}

function startEdit(category: Category) {
  editingSlug.value = category.slug
  isCreating.value = false
  draft.name = category.name
  draft.description = category.description ?? ''
  draft.parent_id = category.parent_id
}

function save() {
  const body = {
    name: draft.name,
    description: draft.description || null,
    parent_id: draft.parent_id,
  }

  return run(() => editingSlug.value
    ? updateCategory(editingSlug.value, body)
    : createCategory(body))
}

/** Reordering swaps positions, which the API takes directly. */
async function move(siblings: Category[], index: number, delta: number) {
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
          Категории
        </h1>
        <p class="page-subtitle">
          Дерево разделов базы знаний. Категории вкладываются друг в друга;
          удаление поднимает подкатегории на уровень выше, а курсы остаются.
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
      description="Категории помогают разложить курсы по темам."
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
        :style="{ marginLeft: `${row.depth * 1.5}rem` }"
      >
        <div class="row__body">
          <span class="row__name">
            <span v-if="row.depth > 0" class="faint" aria-hidden="true">└ </span>
            {{ row.category.name }}
          </span>
          <span v-if="row.category.description" class="faint">{{ row.category.description }}</span>
        </div>

        <span class="badge">
          {{ row.category.courses_count ?? 0 }}
          {{ pluralise(row.category.courses_count ?? 0, 'курс', 'курса', 'курсов') }}
        </span>

        <div class="row__actions">
          <button
            type="button"
            class="button-ghost button-sm"
            :disabled="busy || siblingsOf(row.category.parent_id).indexOf(row.category) === 0"
            @click="move(siblingsOf(row.category.parent_id), siblingsOf(row.category.parent_id).indexOf(row.category), -1)"
          >
            ↑
          </button>
          <button
            type="button"
            class="button-ghost button-sm"
            :disabled="busy || siblingsOf(row.category.parent_id).indexOf(row.category) === siblingsOf(row.category.parent_id).length - 1"
            @click="move(siblingsOf(row.category.parent_id), siblingsOf(row.category.parent_id).indexOf(row.category), 1)"
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