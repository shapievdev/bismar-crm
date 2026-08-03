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

const editingSlug = ref<string | null>(null)
const isCreating = ref(false)
const draft = reactive({ name: '', description: '' })
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
}

function startEdit(category: Category) {
  editingSlug.value = category.slug
  isCreating.value = false
  draft.name = category.name
  draft.description = category.description ?? ''
}

function save() {
  const body = { name: draft.name, description: draft.description || null }

  return run(() => editingSlug.value
    ? updateCategory(editingSlug.value, body)
    : createCategory(body))
}

/** Reordering swaps positions, which the API takes directly. */
async function move(index: number, delta: number) {
  const current = categories.value[index]
  const neighbour = categories.value[index + delta]

  if (!current || !neighbour) {
    return
  }

  await run(async () => {
    await updateCategory(current.slug, {
      name: current.name,
      description: current.description,
      position: neighbour.position,
    })
    await updateCategory(neighbour.slug, {
      name: neighbour.name,
      description: neighbour.description,
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
          Верхний уровень базы знаний. Удаление категории не удаляет материалы — они просто теряют привязку.
        </p>
      </div>

      <div class="head__actions">
        <NuxtLink to="/lms" class="button-secondary">
          К базе знаний
        </NuxtLink>
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
      description="Категории помогают разложить материалы по темам."
    >
      <button type="button" class="button-primary" @click="startCreate">
        Создать первую
      </button>
    </UiEmptyState>

    <ul v-else class="list">
      <li v-for="(item, index) in categories" :key="item.slug" class="card row">
        <div class="row__body">
          <span class="row__name">{{ item.name }}</span>
          <span v-if="item.description" class="faint">{{ item.description }}</span>
        </div>

        <span class="badge">
          {{ item.courses_count ?? 0 }}
          {{ pluralise(item.courses_count ?? 0, 'материал', 'материала', 'материалов') }}
        </span>

        <div class="row__actions">
          <button type="button" class="button-ghost button-sm" :disabled="busy || index === 0" @click="move(index, -1)">
            ↑
          </button>
          <button
            type="button"
            class="button-ghost button-sm"
            :disabled="busy || index === categories.length - 1"
            @click="move(index, 1)"
          >
            ↓
          </button>
          <button type="button" class="button-secondary button-sm" :disabled="busy" @click="startEdit(item)">
            Изменить
          </button>
          <button
            v-if="can('courses.delete')"
            type="button"
            class="button-danger button-sm"
            :disabled="busy"
            @click="run(() => deleteCategory(item.slug))"
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
</style>