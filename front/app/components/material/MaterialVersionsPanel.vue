<script setup lang="ts">
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'
import type { MaterialSection, MaterialVersionSummary } from '~/types/lms'
import type { Group } from '~/types/structure'
import { type FlatDepartment, flattenDepartments } from '~/utils/departments'

/**
 * Версии материала — то же правило, написанное для своих людей.
 *
 * Здесь их заводят, расставляют по порядку и убирают; текст, файлы и проверку
 * версии правят на её собственном экране — это полноценное тело материала, и
 * втискивать второй редактор в эту панель было бы теснотой ради тесноты.
 *
 * Порядок здесь не украшение: человек, попавший в две версии сразу, получает
 * первую по этому списку (решение пользователя 2026-09-12).
 */
const props = defineProps<{
  section: MaterialSection
  slug: string
}>()

const copy = useMaterialSection(props.section)
const { createVersion, fetchVersions, reorderVersions, deleteVersion } = useMaterialsApi(props.section)
const { fetchGroups } = useGroupsApi()
const { fetchStructure } = useStructureApi()

const versions = ref<MaterialVersionSummary[]>([])
const groups = ref<Group[]>([])
const departments = ref<FlatDepartment[]>([])
const isLoading = ref(true)
const isSaving = ref(false)
const errorMessage = ref<string | null>(null)

onMounted(async () => {
  try {
    // Половины независимы, и падать вместе им незачем: сломанный справочник
    // групп не должен уносить с собой отделы.
    const [own, all, structure] = await Promise.all([
      fetchVersions(props.slug),
      fetchGroups(),
      fetchStructure(),
    ])

    versions.value = own.data
    groups.value = all.data
    departments.value = flattenDepartments(structure.data)
  }
  catch {
    errorMessage.value = 'Не удалось загрузить версии.'
  }
  finally {
    isLoading.value = false
  }
})

/* ---------- Новая версия ---------- */

const isAdding = ref(false)
const form = ref({ name: '', is_private: false, groups: [] as number[], departments: [] as number[] })
const errors = ref<ValidationErrors>({})

function startAdding() {
  isAdding.value = true
  form.value = { name: '', is_private: false, groups: [], departments: [] }
  errors.value = {}
}

async function add() {
  isSaving.value = true
  errors.value = {}
  errorMessage.value = null

  try {
    const { data: created } = await createVersion(props.slug, { ...form.value })

    versions.value = [...versions.value, created]
    isAdding.value = false
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      errors.value = caught.errors
    }
    else {
      errorMessage.value = 'Не удалось завести версию.'
    }
  }
  finally {
    isSaving.value = false
  }
}

/* ---------- Порядок и удаление ---------- */

async function move(index: number, step: -1 | 1) {
  const next = [...versions.value]
  const moved = next[index]
  const neighbour = next[index + step]

  if (!moved || !neighbour) {
    return
  }

  next[index] = neighbour
  next[index + step] = moved

  // Показываем сразу, откатываем при отказе: список короткий, и ожидание
  // ответа на каждую стрелку читалось бы как «не сработало».
  const previous = versions.value
  versions.value = next
  isSaving.value = true

  try {
    versions.value = (await reorderVersions(props.slug, next.map(one => one.id))).data
  }
  catch {
    versions.value = previous
    errorMessage.value = 'Не удалось изменить порядок.'
  }
  finally {
    isSaving.value = false
  }
}

const { confirm } = useAppDialog()

async function remove(version: MaterialVersionSummary) {
  const agreed = await confirm({
    title: `Убрать версию «${version.name}»?`,
    message: 'Её текст, файлы и проверка уйдут вместе с ней. Отметки об ознакомлении останутся.',
    confirmLabel: 'Убрать',
    danger: true,
  })

  if (!agreed) {
    return
  }

  isSaving.value = true

  try {
    await deleteVersion(props.slug, version.id)
    versions.value = versions.value.filter(one => one.id !== version.id)
  }
  catch {
    errorMessage.value = 'Не удалось убрать версию.'
  }
  finally {
    isSaving.value = false
  }
}

const nameFieldId = useId()

function toggleGroup(id: number) {
  form.value.groups = form.value.groups.includes(id)
    ? form.value.groups.filter(one => one !== id)
    : [...form.value.groups, id]
}

function toggleDepartment(id: number) {
  form.value.departments = form.value.departments.includes(id)
    ? form.value.departments.filter(one => one !== id)
    : [...form.value.departments, id]
}

/** Кому версия адресована — одной строкой: сперва отделы, потом группы. */
function namesOf(version: MaterialVersionSummary): string {
  return [
    ...(version.departments ?? []).map(unit => `${unit.name} (отдел)`),
    ...(version.groups ?? []).map(group => group.name),
  ].join(', ')
}
</script>

<template>
  <section class="card editor-panel versions">
    <header class="versions__header">
      <h2 class="editor-panel__title">
        Версии
      </h2>
      <span v-if="isSaving" class="faint">Сохраняем…</span>
    </header>

    <p class="faint versions__note">
      Одно правило, написанное для разных людей по-разному. У кого группы ни с
      одной версией не совпали — читает общую, то есть сам
      {{ copy.materialLabel.toLowerCase() }}. Совпало несколько — открывается
      первая по этому списку.
    </p>

    <p v-if="errorMessage" class="alert alert--danger" role="alert">
      {{ errorMessage }}
    </p>

    <p v-if="isLoading" class="faint">
      Читаем…
    </p>

    <ul v-else-if="versions.length" class="versions__list">
      <li v-for="(version, index) in versions" :key="version.id" class="version">
        <span class="version__body">
          <span class="version__name">
            {{ version.name }}
            <span v-if="version.is_private" class="badge">закрытая</span>
          </span>
          <span class="faint version__groups">{{ namesOf(version) || 'без адресатов' }}</span>
        </span>

        <span class="version__actions">
          <button
            type="button"
            class="version__move"
            :disabled="index === 0 || isSaving"
            aria-label="Выше"
            @click="move(index, -1)"
          >↑</button>
          <button
            type="button"
            class="version__move"
            :disabled="index === versions.length - 1 || isSaving"
            aria-label="Ниже"
            @click="move(index, 1)"
          >↓</button>

          <NuxtLink
            :to="`/lms/${section}/${slug}/versions/${version.id}`"
            class="button-secondary button-sm"
          >
            Править
          </NuxtLink>

          <button type="button" class="button-ghost button-sm" :disabled="isSaving" @click="remove(version)">
            Убрать
          </button>
        </span>
      </li>
    </ul>

    <p v-else class="faint">
      Версий нет — {{ copy.materialLabel.toLowerCase() }} читают все одинаково.
    </p>

    <form v-if="isAdding" class="adding" @submit.prevent="add">
      <div class="field">
        <label class="field-label" :for="nameFieldId">Название версии</label>
        <input
          :id="nameFieldId"
          v-model.trim="form.name"
          class="input"
          maxlength="120"
          placeholder="Для розницы"
          autocomplete="off"
        >
        <p v-if="errors.name?.length" class="field-error">
          {{ errors.name[0] }}
        </p>
      </div>

      <div class="field">
        <span class="field-label">Для кого — отделы</span>
        <p class="faint versions__note">
          Отмеченный отдел охватывает и всё, что под ним.
        </p>
        <ul v-if="departments.length" class="picker picker--tree">
          <li v-for="unit in departments" :key="unit.id" :style="{ paddingLeft: `${unit.depth}rem` }">
            <label class="choice">
              <input
                type="checkbox"
                :checked="form.departments.includes(unit.id)"
                @change="toggleDepartment(unit.id)"
              >
              {{ unit.name }}
            </label>
          </li>
        </ul>
        <p v-else class="faint">
          Структура компании пока не заведена.
        </p>
      </div>

      <div class="field">
        <span class="field-label">Для кого — группы</span>
        <ul v-if="groups.length" class="picker">
          <li v-for="group in groups" :key="group.id">
            <label class="choice">
              <input
                type="checkbox"
                :checked="form.groups.includes(group.id)"
                @change="toggleGroup(group.id)"
              >
              {{ group.name }}
              <span class="faint">· {{ group.people_count }}</span>
            </label>
          </li>
        </ul>
        <p v-else class="faint">
          Групп пока нет — заведите их в разделе «Сотрудники».
        </p>
        <p v-if="errors.groups?.length" class="field-error">
          {{ errors.groups[0] }}
        </p>
      </div>

      <!-- Закрытая версия видна только своим группам: у открытой группы решают
           лишь то, кому она откроется первой. -->
      <label class="choice">
        <input v-model="form.is_private" type="checkbox">
        Закрытая — видна только выбранным отделам и группам
      </label>

      <div class="adding__actions">
        <button type="submit" class="button-primary button-sm" :disabled="isSaving">
          Завести
        </button>
        <button type="button" class="button-ghost button-sm" @click="isAdding = false">
          Отмена
        </button>
      </div>
    </form>

    <button v-else type="button" class="button-secondary button-sm versions__add" @click="startAdding">
      Добавить версию
    </button>
  </section>
</template>

<style scoped>
.versions {
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
}

.versions__header {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.75rem;
}

.versions__note {
  margin: 0;
  font-size: 0.85rem;
}

.versions__list {
  display: flex;
  flex-direction: column;
  margin: 0;
  padding: 0;
  list-style: none;
}

.version {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.5rem 0;
  border-top: 1px solid var(--color-border-subtle, var(--color-border));
}

.version:first-child {
  border-top: none;
}

.version__body {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-width: 0;
}

.version__name {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.93rem;
}

.version__groups {
  font-size: 0.8rem;
}

.version__actions {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  flex-shrink: 0;
}

.version__actions a {
  text-decoration: none;
}

/* Стрелки — узкие цели рядом с кнопками, поэтому им свой размер и своя
   граница: иначе они читаются продолжением «Править». */
.version__move {
  width: 1.7rem;
  height: 1.7rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: transparent;
  color: var(--color-text-muted);
  font: inherit;
  font-size: 0.8rem;
  cursor: pointer;
}

.version__move:disabled {
  opacity: 0.4;
  cursor: default;
}

.adding {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  padding-top: 0.6rem;
  border-top: 1px solid var(--color-border);
}

.picker {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem 1rem;
  margin: 0.25rem 0 0;
  padding: 0;
  list-style: none;
}

/* Отделы — столбцом: отступ показывает, чей это отдел, а в строку вперемешку
   вложенность не прочитать. Без переноса: с ограниченной высотой колоночный
   flex сворачивает список во вторую колонку, и отступы начинают врать. */
.picker--tree {
  flex-direction: column;
  flex-wrap: nowrap;
  gap: 0.2rem;
  max-height: 12rem;
  overflow-y: auto;
}

.adding__actions {
  display: flex;
  gap: 0.5rem;
}

.versions__add {
  align-self: flex-start;
}
</style>
