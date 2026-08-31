<script setup lang="ts">
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'
import type { Group, Person } from '~/types/structure'

/**
 * Группы сотрудников — списки людей, собранные вручную.
 *
 * Отдел говорит, где человек работает; группа — кого зовут вместе: наставники,
 * кассиры всех магазинов, участники запуска. Прав она не даёт — это адресат
 * рассылки и новости.
 *
 * Открыта всякому, кто вошёл, как и структура: название группы не тайна, а без
 * него не выбрать адресата новости тому, кто её ведёт. Заводит и правит
 * администратор — сервер проверяет это сам, а экран лишь не предлагает того,
 * чего нельзя.
 */
definePageMeta({ middleware: 'auth' })
useHead({ title: 'Группы' })

const {
  fetchGroups,
  fetchGroup,
  createGroup,
  updateGroup,
  deleteGroup,
  addGroupPeople,
  removeGroupPerson,
} = useGroupsApi()
const { searchDepartmentCandidates } = useStructureApi()
const { isAdmin } = useAuth()
const { confirm } = useAppDialog()

const canManage = computed(() => isAdmin.value)

/* ---------- Список ---------- */

const search = ref('')

/**
 * Запрос отстаёт от набора на четверть секунды: групп немного, но спрашивать
 * сервер на каждую букву незачем.
 */
const term = ref('')
let debounce: ReturnType<typeof setTimeout> | undefined

watch(search, (value) => {
  clearTimeout(debounce)
  debounce = setTimeout(() => {
    term.value = value.trim()
  }, 250)
})

onBeforeUnmount(() => clearTimeout(debounce))

const { data, pending, error, refresh } = await useAsyncData(
  'groups.list',
  () => fetchGroups(term.value),
  { watch: [term] },
)

const groups = computed<Group[]>(() => data.value?.data ?? [])

/* ---------- Открытая группа ---------- */

/**
 * Состав приходит отдельным запросом: в списке из тридцати групп он был бы
 * половиной штата ради одной строки. Поэтому открытая группа живёт своим
 * состоянием, а не ссылкой в список.
 */
const opened = ref<Group | null>(null)
const isSaving = ref(false)
const panelError = ref<string | null>(null)

async function open(group: Group) {
  if (opened.value?.id === group.id) {
    opened.value = null

    return
  }

  panelError.value = null

  try {
    opened.value = (await fetchGroup(group.id)).data
  }
  catch {
    panelError.value = 'Не удалось открыть группу.'
  }
}

/** Ответ на правку состава — карточка группы целиком, вместе с числом людей. */
function applied(group: Group) {
  opened.value = group

  return refresh()
}

async function addPerson(person: Person) {
  const group = opened.value

  if (!group) {
    return
  }

  isSaving.value = true
  panelError.value = null

  try {
    await applied((await addGroupPeople(group.id, [person.id])).data)
  }
  catch {
    panelError.value = 'Не удалось добавить человека в группу.'
  }
  finally {
    isSaving.value = false
  }
}

async function removePerson(person: Person) {
  const group = opened.value

  if (!group) {
    return
  }

  isSaving.value = true
  panelError.value = null

  try {
    await applied((await removeGroupPerson(group.id, person.id)).data)
  }
  catch {
    panelError.value = 'Не удалось убрать человека из группы.'
  }
  finally {
    isSaving.value = false
  }
}

/* ---------- Название и описание ---------- */

/** `null` — форма закрыта, `0` — заводят новую, иначе правят эту. */
const editing = ref<number | null>(null)
const draft = reactive({ name: '', description: '' })
const errors = ref<ValidationErrors>({})
const formError = ref<string | null>(null)

function startCreating() {
  editing.value = 0
  draft.name = ''
  draft.description = ''
  errors.value = {}
  formError.value = null
}

function startEditing(group: Group) {
  editing.value = group.id
  draft.name = group.name
  draft.description = group.description ?? ''
  errors.value = {}
  formError.value = null
}

function stopEditing() {
  editing.value = null
  errors.value = {}
  formError.value = null
}

async function save() {
  if (editing.value === null) {
    return
  }

  isSaving.value = true
  errors.value = {}
  formError.value = null

  const body = {
    name: draft.name.trim(),
    description: draft.description.trim() || null,
  }

  try {
    const saved = editing.value === 0
      ? (await createGroup(body)).data
      : (await updateGroup(editing.value, body)).data

    // Заведённая группа сразу открывается: за названием идут люди, и второе
    // нажатие ради этого — лишнее.
    opened.value = saved
    stopEditing()
    await refresh()
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      errors.value = caught.errors
    }
    else {
      formError.value = 'Не удалось сохранить группу.'
    }
  }
  finally {
    isSaving.value = false
  }
}

async function remove(group: Group) {
  const agreed = await confirm({
    title: `Удалить группу «${group.name}»?`,
    message: 'Люди останутся на месте — исчезнет только список. '
      + 'Отправленные ей рассылки в истории сохранятся.',
    confirmLabel: 'Удалить',
    danger: true,
  })

  if (!agreed) {
    return
  }

  isSaving.value = true
  panelError.value = null

  try {
    await deleteGroup(group.id)

    opened.value = null
    await refresh()
  }
  catch {
    panelError.value = 'Не удалось удалить группу.'
  }
  finally {
    isSaving.value = false
  }
}

/** Кого можно добавить: работающие сотрудники — те же, что и в отделы. */
async function findPeople(query: string): Promise<Person[]> {
  return (await searchDepartmentCandidates(query)).data
}

function peopleLabel(count: number): string {
  return `${count} ${pluralise(count, 'человек', 'человека', 'человек')}`
}
</script>

<template>
  <section>
    <header class="page-header">
      <div class="page-header__row">
        <div>
          <h1>Группы</h1>
          <p class="muted">
            Списки людей, собранные вручную: наставники, кассиры, участники запуска.
            Ими адресуют новости и рассылки — прав группа не даёт.
          </p>
        </div>

        <button v-if="canManage" type="button" class="button-primary" @click="startCreating">
          Новая группа
        </button>
      </div>

      <div class="page-header__tools">
        <input
          v-model.trim="search"
          type="search"
          class="input search"
          autocomplete="off"
          placeholder="Поиск по названию…"
          aria-label="Поиск по группам"
        >
      </div>
    </header>

    <!-- Форма заведения и правки одна: разница только в заголовке и в том,
         куда уйдёт сохранение. -->
    <section v-if="editing !== null" class="card panel">
      <h2 class="panel__title">
        {{ editing === 0 ? 'Новая группа' : 'Название и описание' }}
      </h2>

      <p v-if="formError" class="auth-alert" role="alert">
        {{ formError }}
      </p>

      <div class="field">
        <label class="field-label" for="group-name">Название</label>
        <input id="group-name" v-model="draft.name" class="input" type="text" placeholder="Наставники">
        <p v-if="errors.name?.length" class="field-error">
          {{ errors.name[0] }}
        </p>
      </div>

      <div class="field">
        <label class="field-label" for="group-description">Зачем она собрана</label>
        <input
          id="group-description"
          v-model="draft.description"
          class="input"
          type="text"
          placeholder="Необязательно — например, «ведут новичков первый месяц»"
        >
        <p v-if="errors.description?.length" class="field-error">
          {{ errors.description[0] }}
        </p>
      </div>

      <div class="panel__actions">
        <button type="button" class="button-primary" :disabled="isSaving || !draft.name.trim()" @click="save">
          Сохранить
        </button>
        <button type="button" class="button-ghost" :disabled="isSaving" @click="stopEditing">
          Отмена
        </button>
      </div>
    </section>

    <p v-if="pending && !data" class="muted">
      Загрузка…
    </p>

    <p v-else-if="error" class="auth-alert" role="alert">
      Не удалось загрузить группы.
    </p>

    <UiEmptyState
      v-else-if="!groups.length"
      :title="term ? 'Ничего не нашли' : 'Групп пока нет'"
      :description="term
        ? 'Проверьте название — поиск идёт по нему и по описанию.'
        : 'Группа — список людей, которых зовут вместе. Заведите первую.'"
    >
      <button v-if="canManage && !term" type="button" class="button-primary" @click="startCreating">
        Новая группа
      </button>
    </UiEmptyState>

    <div v-else class="layout">
      <ul class="list">
        <li v-for="group in groups" :key="group.id">
          <button
            type="button"
            class="list__item"
            :class="{ 'list__item--open': opened?.id === group.id }"
            :aria-expanded="opened?.id === group.id"
            @click="open(group)"
          >
            <span class="list__body">
              <span class="list__name">{{ group.name }}</span>
              <span v-if="group.description" class="muted">{{ group.description }}</span>
            </span>
            <span class="badge">{{ peopleLabel(group.people_count) }}</span>
          </button>
        </li>
      </ul>

      <GroupsMembersPanel
        v-if="opened"
        :group="opened"
        :can-manage="canManage"
        :is-saving="isSaving"
        :error-message="panelError"
        :search="findPeople"
        @add="addPerson"
        @remove="removePerson"
        @rename="startEditing(opened)"
        @delete="remove(opened)"
      />

      <p v-else class="muted panel-hint">
        Выберите группу, чтобы посмотреть и поправить её состав.
      </p>
    </div>
  </section>
</template>

<style scoped>
.page-header {
  margin-bottom: 1.5rem;
}

.page-header__row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
}

.page-header__tools {
  margin-top: 1rem;
}

.page-header h1 {
  margin: 0 0 0.25rem;
  font-size: 1.5rem;
}

.muted {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.search {
  /* Поле поиска не растягивается на всю ширину: строка в двадцать сантиметров
     под слово «наставники» — не поиск, а полоса. */
  width: min(24rem, 100%);
}

/* Список слева, состав справа: выбранная группа не уводит со списка, и
   переключаться между ними можно, не листая страницу. */
.layout {
  display: grid;
  grid-template-columns: minmax(0, 20rem) minmax(0, 1fr);
  align-items: start;
  gap: 1.25rem;
}

.list {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.list__item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.6rem;
  width: 100%;
  padding: 0.75rem 0.9rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface);
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.list__item:hover {
  border-color: var(--color-border-strong);
}

/* Открытая группа не заливается акцентом: рядом её же состав, и чёрная плашка
   с белым текстом спорила бы с ним за внимание. Хватает рамки и подъёма. */
.list__item--open {
  border-color: var(--color-accent);
  background: var(--color-surface-raised);
}

.list__body {
  display: flex;
  flex-direction: column;
  min-width: 0;
  gap: 0.15rem;
}

.list__name {
  font-weight: 500;
}

.panel {
  display: flex;
  flex-direction: column;
  gap: 0.9rem;
  padding: 1.4rem 1.5rem;
  margin-bottom: 1.25rem;
}

.panel__title {
  margin: 0;
  font-size: 1.05rem;
}

.panel__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.panel-hint {
  padding: 1rem 0;
}

@media (max-width: 60rem) {
  .layout {
    grid-template-columns: minmax(0, 1fr);
  }

  /* На узком экране подсказка «выберите группу» ничего не подсказывает:
     состав встаёт под списком, и до него надо ещё долистать. */
  .panel-hint {
    display: none;
  }
}

@media (max-width: 48rem) {
  .page-header__row {
    flex-direction: column;
  }

  .search {
    width: 100%;
  }
}
</style>
