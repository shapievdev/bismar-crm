<script setup lang="ts">
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'
import type { RegulationCategory } from '~/types/lms'

definePageMeta({ middleware: 'auth', permission: 'courses.update' })
useHead({ title: 'Категории документов' })

const { fetchCategories, createCategory, updateCategory, deleteCategory } = useRegulationsApi()

const { data, pending, error, refresh } = await useAsyncData(
  'lms.regulation-categories.manage',
  () => fetchCategories(),
)

const categories = computed(() => data.value?.data ?? [])

/** Плоский список для правки: дерево рисуется отступом, а не вложенностью. */
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

const errors = ref<ValidationErrors>({})
const generalError = ref<string | null>(null)
const isSaving = ref(false)

/* ---------- Новая ---------- */

const draft = reactive({ name: '', description: '', parent_id: null as number | null })

async function add() {
  isSaving.value = true
  errors.value = {}
  generalError.value = null

  try {
    await createCategory({
      name: draft.name,
      description: draft.description || null,
      parent_id: draft.parent_id,
    })

    draft.name = ''
    draft.description = ''
    draft.parent_id = null
    await refresh()
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      errors.value = caught.errors
    }
    else {
      generalError.value = 'Не удалось создать категорию.'
    }
  }
  finally {
    isSaving.value = false
  }
}

/* ---------- Правка ---------- */

const editingSlug = ref<string | null>(null)
const editing = reactive({ name: '', description: '', parent_id: null as number | null })

function startEditing(category: RegulationCategory) {
  editingSlug.value = category.slug
  editing.name = category.name
  editing.description = category.description ?? ''
  editing.parent_id = category.parent_id
  errors.value = {}
}

async function saveEditing() {
  if (editingSlug.value === null) {
    return
  }

  isSaving.value = true
  errors.value = {}

  try {
    await updateCategory(editingSlug.value, {
      name: editing.name,
      description: editing.description || null,
      parent_id: editing.parent_id,
    })

    editingSlug.value = null
    await refresh()
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      errors.value = caught.errors
    }
    else {
      generalError.value = 'Не удалось сохранить категорию.'
    }
  }
  finally {
    isSaving.value = false
  }
}

async function remove(category: RegulationCategory) {
  generalError.value = null

  try {
    await deleteCategory(category.slug)
    await refresh()
  }
  catch {
    generalError.value = 'Не удалось удалить категорию.'
  }
}
</script>

<template>
  <section>
    <header class="head">
      <h1 class="page-title">
        Категории документов
      </h1>
      <p class="page-subtitle">
        Своё дерево, не общее с учебными категориями: здесь ищут, по какому правилу работать.
      </p>
    </header>

    <p v-if="generalError" class="alert alert--danger" role="alert">
      {{ generalError }}
    </p>

    <p v-if="error" class="alert alert--danger" role="alert">
      Не удалось загрузить категории.
    </p>

    <div v-else-if="pending" class="skeleton skeleton-line" />

    <UiEmptyState
      v-else-if="!flat.length"
      title="Категорий пока нет"
      description="Заведите первую — документ без категории тоже живёт, но искать его труднее."
    />

    <ul v-else class="tree">
      <li v-for="row in flat" :key="row.category.id" class="card node">
        <template v-if="editingSlug === row.category.slug">
          <form class="node__form" novalidate @submit.prevent="saveEditing">
            <input v-model.trim="editing.name" class="input" maxlength="120" aria-label="Название">
            <input v-model.trim="editing.description" class="input" maxlength="1000" placeholder="Описание — необязательно">
            <CategoryTreeSelect
              v-model="editing.parent_id"
              :categories="categories"
              :exclude-id="row.category.id"
            />
            <p v-if="errors.name?.length || errors.parent_id?.length" class="field-error">
              {{ errors.name?.[0] ?? errors.parent_id?.[0] }}
            </p>
            <div class="node__actions">
              <button type="submit" class="button-primary button-sm" :disabled="isSaving">
                Сохранить
              </button>
              <button type="button" class="button-ghost button-sm" @click="editingSlug = null">
                Отмена
              </button>
            </div>
          </form>
        </template>

        <template v-else>
          <span class="node__body" :style="{ paddingLeft: `${row.depth * 1.25}rem` }">
            <span class="node__name">{{ row.category.name }}</span>
            <span v-if="row.category.description" class="faint">{{ row.category.description }}</span>
          </span>

          <span class="faint node__count">{{ row.category.regulations_count ?? 0 }}</span>

          <span class="node__actions">
            <button type="button" class="button-ghost button-sm" @click="startEditing(row.category)">
              Переименовать
            </button>
            <button type="button" class="button-danger button-sm" @click="remove(row.category)">
              Удалить
            </button>
          </span>
        </template>
      </li>
    </ul>

    <section class="card add">
      <h2 class="add__title">
        Новая категория
      </h2>

      <form class="add__form" novalidate @submit.prevent="add">
        <div class="field">
          <label class="field-label" for="name">Название</label>
          <input id="name" v-model.trim="draft.name" class="input" maxlength="120">
          <p v-if="errors.name?.length" class="field-error">
            {{ errors.name[0] }}
          </p>
        </div>

        <div class="field">
          <label class="field-label" for="description">
            Описание <span class="field-optional">— необязательно</span>
          </label>
          <input id="description" v-model.trim="draft.description" class="input" maxlength="1000">
        </div>

        <div class="field">
          <label class="field-label" for="parent">
            Внутри <span class="field-optional">— если это подкатегория</span>
          </label>
          <CategoryTreeSelect id="parent" v-model="draft.parent_id" :categories="categories" />
        </div>

        <button type="submit" class="button-primary" :disabled="isSaving || !draft.name">
          Добавить
        </button>
      </form>
    </section>
  </section>
</template>

<style scoped>
.head {
  margin-bottom: 1.5rem;
}

.tree {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  margin: 0 0 1.5rem;
  padding: 0;
  list-style: none;
}

.node {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem 1rem;
}

.node__body {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-width: 0;
  gap: 0.1rem;
  font-size: 0.9rem;
}

.node__name {
  font-weight: 550;
}

.node__count {
  font-variant-numeric: tabular-nums;
}

.node__actions {
  display: flex;
  flex-shrink: 0;
  gap: 0.35rem;
}

.node__form {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  width: 100%;
}

.add {
  padding: 1.4rem 1.5rem;
  max-width: 34rem;
}

.add__title {
  margin: 0 0 1rem;
  font-size: 1.05rem;
  font-weight: 600;
}

.add__form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  align-items: flex-start;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  width: 100%;
}

.field-optional {
  color: var(--color-text-faint);
  font-weight: 400;
}

.skeleton-line {
  width: 100%;
  height: 3rem;
}

@media (max-width: 40rem) {
  .node {
    flex-wrap: wrap;
  }

  .node__actions {
    width: 100%;
    justify-content: flex-end;
  }

  .add {
    padding: 1.15rem 1.15rem 1.25rem;
  }
}
</style>
