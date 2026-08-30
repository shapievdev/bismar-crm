<script setup lang="ts">
import type { Department, DepartmentPerson, DepartmentRoleKind } from '~/types/structure'

/**
 * Панель отдела: кто им руководит, кто замещает и кто в нём работает.
 *
 * Открывается по щелчку на карточке и живёт справа, не закрывая дерево: из неё
 * возвращаются к структуре, а не наоборот.
 */
const props = defineProps<{ department: Department, editable: boolean }>()
const emit = defineEmits<{ close: [], changed: [] }>()

const {
  fetchDepartmentPeople,
  addDepartmentPeople,
  changeDepartmentRole,
  removeDepartmentPerson,
  searchDepartmentCandidates,
} = useStructureApi()
const { can } = useAuth()
const structure = useStructure()

const people = ref<DepartmentPerson[]>([])
const isLoading = ref(false)
const errorMessage = ref<string | null>(null)
const search = ref('')

/** Группы читаются сверху вниз: сначала кто отвечает, потом кто работает. */
const groups = computed(() => ([
  { role: 'head' as const, title: 'Руководители' },
  { role: 'deputy' as const, title: 'Заместители' },
  { role: 'member' as const, title: 'Подчинённые' },
].map(group => ({
  ...group,
  people: people.value.filter(person => person.role === group.role),
})).filter(group => group.people.length > 0)))

async function load() {
  isLoading.value = true
  errorMessage.value = null

  try {
    people.value = (await fetchDepartmentPeople(props.department.id, search.value)).data
  }
  catch {
    errorMessage.value = 'Не удалось загрузить состав отдела.'
    people.value = []
  }
  finally {
    isLoading.value = false
  }
}

watch(() => props.department, (next, previous) => {
  // Отдел сменился — искать заново незачем; тот же, но перечитанный (после
  // переноса человека, например) — список обновляем, поиск не трогаем.
  if (next.id !== previous?.id) {
    search.value = ''
  }

  void load()
}, { immediate: true })

/*
 * Поиск идёт по серверу — он умеет искать и по должности, а не только по
 * имени, — и с задержкой: спрашивать на каждую букву незачем.
 */
let searchTimer: ReturnType<typeof setTimeout> | undefined

watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => void load(), 250)
})

onBeforeUnmount(() => clearTimeout(searchTimer))

/* ---------- Правка состава ---------- */

const candidates = useDebouncedSearch<DepartmentPerson>(
  async term => (await searchDepartmentCandidates(term)).data,
)

const newRole = ref<DepartmentRoleKind>('member')

const roleOptions = [
  { value: 'member', label: 'Сотрудник', action: 'Сделать сотрудником' },
  { value: 'deputy', label: 'Заместитель', action: 'Сделать заместителем' },
  { value: 'head', label: 'Руководитель', action: 'Сделать руководителем' },
] as const

/** Чьё меню действий открыто. Одно на список: два разом не открывают. */
const openMenuFor = ref<number | null>(null)

function withMenuClosed(action: () => void) {
  openMenuFor.value = null
  action()
}

async function act(action: () => Promise<{ data: DepartmentPerson[] }>) {
  errorMessage.value = null

  try {
    people.value = (await action()).data
    // Счётчики на карточке сложились из этих же людей — дерево пересчитать.
    emit('changed')
  }
  catch (caught) {
    errorMessage.value = (caught as { data?: { message?: string } }).data?.message
      ?? 'Не удалось изменить состав отдела.'
  }
}

function add(person: DepartmentPerson) {
  candidates.clear()

  return act(() => addDepartmentPeople(props.department.id, {
    user_ids: [person.id],
    role: newRole.value,
  }))
}

function changeRole(person: DepartmentPerson, role: DepartmentRoleKind) {
  return act(() => changeDepartmentRole(props.department.id, person.id, role))
}

function remove(person: DepartmentPerson) {
  return act(() => removeDepartmentPerson(props.department.id, person.id))
}

/**
 * Человека уносят из панели на карточку другого отдела — тем же движением, что
 * и сами карточки. Роль едет вместе с ним.
 */
function onPersonDragStart(event: DragEvent, person: DepartmentPerson) {
  if (!props.editable || person.role === null) {
    return
  }

  event.dataTransfer?.setData('text/plain', String(person.id))
  event.dataTransfer!.effectAllowed = 'move'

  structure.startPersonDrag({
    id: person.id,
    name: person.name,
    role: person.role,
    fromDepartmentId: props.department.id,
  })
}
</script>

<template>
  <aside class="panel" aria-label="Состав отдела">
    <header class="panel__head">
      <h2 class="panel__title">
        {{ department.name }}
      </h2>
      <button type="button" class="panel__close" aria-label="Закрыть" @click="emit('close')">
        ✕
      </button>
    </header>

    <p class="panel__counts">
      <span class="badge">Всего сотрудников {{ people.length }}</span>
      <span class="badge">В кусте {{ department.people_total }}</span>
    </p>

    <input
      v-model="search"
      class="input"
      type="search"
      autocomplete="off"
      placeholder="Найти по имени или должности"
    >

    <p v-if="errorMessage" class="alert alert--danger" role="alert">
      {{ errorMessage }}
    </p>

    <p v-if="isLoading" class="faint">
      Загрузка…
    </p>

    <p v-else-if="!people.length" class="faint">
      {{ search.trim() ? 'Никого не нашли.' : 'В отделе пока никого нет.' }}
    </p>

    <!--
      Подсказка и список идут вместе, но не в одной цепочке с проверками выше:
      стоя между `v-else-if` и `v-else`, подсказка перехватывала ветку списка —
      и при непустом составе рисовалась вместо людей.
    -->
    <template v-else>
      <section v-for="group in groups" :key="group.role" class="group">
        <h3 class="group__title">
          {{ group.title }} <span class="group__count">{{ group.people.length }}</span>
        </h3>

        <ul class="people">
          <li
            v-for="person in group.people"
            :key="person.id"
            class="person"
            :class="{ 'person--movable': editable }"
            :draggable="editable"
            @dragstart="onPersonDragStart($event, person)"
            @dragend="structure.endPersonDrag()"
          >
            <UserAvatar :name="person.name" :src="person.avatar_url" :size="36" />

            <div class="person__body">
              <!-- Имя ведёт в карточку сотрудника — тому, кому список людей
                   открыт; остальным это просто имя. -->
              <NuxtLink v-if="can('users.view')" :to="`/staff/${person.id}`" class="person__name">
                {{ person.name }}
              </NuxtLink>
              <span v-else class="person__name">{{ person.name }}</span>
              <span class="person__title">{{ person.job_title ?? 'Должность не указана' }}</span>
            </div>

            <!--
              Действия — в меню, а не в строке: выпадающий список ролей и кнопка
              рядом с именем не помещались в панель и наезжали на него.
            -->
            <div v-if="editable" class="person__menu">
              <button
                type="button"
                class="person__more"
                :aria-expanded="openMenuFor === person.id"
                :aria-label="`Действия: ${person.name}`"
                @click.stop="openMenuFor = openMenuFor === person.id ? null : person.id"
              >
                ⋯
              </button>

              <ul v-if="openMenuFor === person.id" class="menu" @click.stop>
                <li v-for="option in roleOptions.filter(one => one.value !== person.role)" :key="option.value">
                  <button type="button" @click="withMenuClosed(() => changeRole(person, option.value))">
                    {{ option.action }}
                  </button>
                </li>
                <li>
                  <button type="button" class="menu__danger" @click="withMenuClosed(() => remove(person))">
                    Убрать из отдела
                  </button>
                </li>
              </ul>
            </div>
          </li>
        </ul>
      </section>
    </template>

    <section v-if="editable" class="add">
      <h3 class="group__title">
        Добавить человека
      </h3>

      <div class="add__role">
        <label class="field-label" for="new-role">Кем</label>
        <UiSelect
          id="new-role"
          v-model="newRole"
          :options="roleOptions.map(option => ({ value: option.value, label: option.label }))"
          auto
        />
      </div>

      <input
        v-model="candidates.query.value"
        class="input"
        type="search"
        autocomplete="off"
        placeholder="Найдите сотрудника по фамилии или почте"
      >

      <p v-if="candidates.isSearching.value" class="faint">
        Ищем…
      </p>
      <p v-else-if="candidates.query.value.trim() && !candidates.results.value.length" class="faint">
        Никого не нашли.
      </p>

      <ul v-else-if="candidates.results.value.length" class="people">
        <li v-for="person in candidates.results.value" :key="person.id">
          <button type="button" class="found" @click="add(person)">
            <UserAvatar :name="person.name" :src="person.avatar_url" :size="28" />
            <span class="found__body">
              <span class="person__name">{{ person.name }}</span>
              <span class="faint">{{ person.job_title ?? 'Должность не указана' }}</span>
            </span>
          </button>
        </li>
      </ul>
    </section>
  </aside>
</template>

<style scoped>
/*
 * Лист справа во всю высоту экрана: дерево остаётся видимым слева, и человек
 * не теряет место, к которому вернётся.
 */
.panel {
  position: sticky;
  top: calc(var(--header-height) + 1rem);
  display: flex;
  flex-direction: column;
  gap: 0.8rem;
  width: 22rem;
  max-height: calc(100dvh - var(--header-height) - 2rem);
  padding: 1.1rem 1.2rem;
  overflow-y: auto;
  border-radius: var(--radius);
  background: var(--color-surface);
}

.panel__head {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
}

.panel__title {
  margin: 0;
  font-size: 1.1rem;
  font-weight: 600;
}

.panel__close {
  margin-left: auto;
  border: 0;
  background: transparent;
  color: var(--color-text-muted);
  font-size: 0.95rem;
  cursor: pointer;
}

.panel__counts {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  margin: 0;
}

.group {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.group__title {
  display: flex;
  align-items: baseline;
  gap: 0.35rem;
  margin: 0;
  font-size: 0.78rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-text-muted);
}

/* Сколько их в группе — цифрой рядом с названием, как в образце. */
.group__count {
  font-weight: 500;
  letter-spacing: 0;
  opacity: 0.75;
}

.people {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

/*
 * Три колонки: аватар, имя с должностью и кнопка действий. Именно сеткой, а не
 * потоком, — средняя колонка обязана ужиматься (minmax(0, 1fr)), иначе длинное
 * имя раздвигает строку и кнопка наезжает на текст.
 */
.person {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: center;
  gap: 0.6rem;
}

/* Человека можно унести на карточку другого отдела — курсор об этом говорит. */
.person--movable {
  cursor: grab;
}

.person__body,
.found__body {
  display: flex;
  flex-direction: column;
  gap: 0.05rem;
  min-width: 0;
  text-align: left;
  font-size: 0.88rem;
  line-height: 1.3;
}

.person__name,
.person__title {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.person__name {
  color: inherit;
  font-weight: 550;
  text-decoration: none;
}

a.person__name:hover {
  text-decoration: underline;
}

.person__title {
  color: var(--color-text-muted);
  font-size: 0.82rem;
}

.person__menu {
  position: relative;
}

.person__more {
  padding: 0.1rem 0.35rem;
  border: 0;
  border-radius: var(--radius);
  background: transparent;
  color: var(--color-text-muted);
  font-size: 1rem;
  line-height: 1;
  cursor: pointer;
}

.person__more:hover {
  background: var(--color-surface-sunken);
  color: var(--color-text);
}

/* Меню открывается влево: строка прижата к правому краю панели. */
.menu {
  position: absolute;
  top: 100%;
  right: 0;
  z-index: 5;
  min-width: 12rem;
  margin: 0.25rem 0 0;
  padding: 0.25rem;
  list-style: none;
  border-radius: var(--radius);
  background: var(--color-surface-raised);
  box-shadow: 0 10px 30px rgb(0 0 0 / 18%);
}

.menu button {
  display: block;
  width: 100%;
  padding: 0.45rem 0.6rem;
  border: 0;
  border-radius: calc(var(--radius) - 2px);
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.menu button:hover {
  background: var(--color-surface-sunken);
}

.menu__danger {
  color: var(--color-danger);
}

.add {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding-top: 0.6rem;
  border-top: 1px solid var(--color-border);
}

.add__role {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.found {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  width: 100%;
  padding: 0.4rem 0.5rem;
  border: 0;
  border-radius: var(--radius);
  background: transparent;
  color: inherit;
  font: inherit;
  cursor: pointer;
}

.found:hover {
  background: var(--color-surface-sunken);
}

@media (max-width: 60rem) {
  .panel {
    position: static;
    width: 100%;
    max-height: none;
  }
}
</style>
