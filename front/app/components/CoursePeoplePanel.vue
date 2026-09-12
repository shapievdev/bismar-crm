<script setup lang="ts">
import type { CoursePerson } from '~/types/lms'
import type { Group } from '~/types/structure'

/**
 * Список людей у курса: показать, убрать, найти и добавить.
 *
 * Общая часть двух списков — допущенных к приватному курсу и ответственных за
 * него. Разного в них только надписи и то, куда ходить за данными; всё
 * остальное — поиск с задержкой, порядок, разметка — устроено одинаково, и
 * держать это дважды значит однажды поправить в одном месте.
 *
 * У допуска рядом с людьми стоят группы (2026-09-12); у ответственных их нет —
 * «к кому идти с вопросом» группа не отвечает. Поэтому всё групповое здесь
 * необязательно: не передали `groups` — панель работает ровно как прежде.
 *
 * Панель ничего не решает сама: она показывает то, что дали, и сообщает о
 * нажатиях. Кто и как сохраняет — дело того, кто её поставил.
 */
const props = defineProps<{
  title: string
  /** Пояснение над списком: когда список ни на что не влияет, например. */
  note?: string | null
  people: CoursePerson[]
  /** Впущенные группы. Не передали — панель о группах не знает. */
  groups?: Group[]
  isLoading: boolean
  isSaving: boolean
  errorMessage?: string | null
  /** Строка, которую нельзя убрать, — автор курса в списке доступа. */
  fixedName?: string | null
  fixedBadge?: string | null
  emptyNote: string
  addLabel: string
  searchPlaceholder?: string
  notFoundNote: string
  search: (term: string) => Promise<CoursePerson[]>
  /** Поиск групп тем же словом. Не передали — ищутся одни люди. */
  searchGroups?: (term: string) => Promise<Group[]>
}>()

const emit = defineEmits<{
  add: [person: CoursePerson]
  remove: [person: CoursePerson]
  addGroup: [group: Group]
  removeGroup: [group: Group]
}>()

const groups = computed(() => props.groups ?? [])
const handlesGroups = computed(() => props.groups !== undefined)

function add(person: CoursePerson) {
  clearSearch()

  if (!props.people.some(one => one.id === person.id)) {
    emit('add', person)
  }
}

function addGroup(group: Group) {
  clearSearch()

  if (!groups.value.some(one => one.id === group.id)) {
    emit('addGroup', group)
  }
}

/* ---------- Поиск ---------- */

const query = ref('')
const candidates = ref<CoursePerson[]>([])
const groupCandidates = ref<Group[]>([])
const isSearching = ref(false)

let searchTimer: ReturnType<typeof setTimeout> | undefined
let searchToken = 0

function clearSearch() {
  query.value = ''
  candidates.value = []
  groupCandidates.value = []
}

/**
 * Ищет с задержкой, и показывает только ответ на последний запрос: набранное
 * целиком приходит раньше, чем ответ на первую букву, и без этого список
 * подсказок мигал бы результатами уже стёртого слова.
 *
 * Люди и группы ищутся одним словом и разом: набравший «продаж» видит и отдел
 * продаж группой, и Продажникова поимённо, не выбирая заранее, кого он ищет.
 */
watch(query, (value) => {
  clearTimeout(searchTimer)

  const term = value.trim()

  if (term === '') {
    candidates.value = []
    groupCandidates.value = []
    isSearching.value = false

    return
  }

  isSearching.value = true

  searchTimer = setTimeout(async () => {
    const token = ++searchToken

    try {
      const [people, found] = await Promise.all([
        props.search(term),
        props.searchGroups?.(term) ?? Promise.resolve([]),
      ])

      if (token === searchToken) {
        candidates.value = people
        groupCandidates.value = found
      }
    }
    catch {
      if (token === searchToken) {
        candidates.value = []
        groupCandidates.value = []
      }
    }
    finally {
      if (token === searchToken) {
        isSearching.value = false
      }
    }
  }, 250)
})

onBeforeUnmount(() => clearTimeout(searchTimer))

const hasCandidates = computed(() => candidates.value.length > 0 || groupCandidates.value.length > 0)

/** «Группа · 12 человек» — по числу видно, скольким это открывает материал. */
function groupSize(group: Group): string {
  return `${group.people_count} ${pluralise(group.people_count, 'человек', 'человека', 'человек')}`
}

const inputId = useId()
</script>

<template>
  <section class="panel card">
    <header class="panel__header">
      <h2 class="panel__title">
        {{ title }}
      </h2>
      <span v-if="isSaving" class="panel__status">Сохраняем…</span>
    </header>

    <p v-if="note" class="panel__note">
      {{ note }}
    </p>

    <p v-if="errorMessage" class="alert alert--danger" role="alert">
      {{ errorMessage }}
    </p>

    <ul class="people">
      <li v-if="fixedBadge" class="people__item people__item--author">
        <UserAvatar :name="fixedName" :size="28" />
        <span class="people__name">{{ fixedName ?? 'Автор удалён' }}</span>
        <span class="badge">{{ fixedBadge }}</span>
      </li>

      <!-- Группы впереди людей: группа — мазок шире, и «весь отдел продаж
           плюс Иванов» читается именно в таком порядке. -->
      <li v-for="group in groups" :key="`group-${group.id}`" class="people__item">
        <span class="people__group-mark" aria-hidden="true">Гр</span>
        <span class="people__name">
          {{ group.name }}
          <span class="people__email">Группа · {{ groupSize(group) }}</span>
        </span>
        <button type="button" class="people__remove" :disabled="isSaving" @click="emit('removeGroup', group)">
          Убрать
        </button>
      </li>

      <li v-for="person in people" :key="person.id" class="people__item">
        <UserAvatar :name="person.name" :src="person.avatar_url" :size="28" />
        <span class="people__name">
          {{ person.name }}
          <span v-if="person.email" class="people__email">{{ person.email }}</span>
        </span>
        <button type="button" class="people__remove" :disabled="isSaving" @click="emit('remove', person)">
          Убрать
        </button>
      </li>
    </ul>

    <p v-if="!isLoading && people.length === 0 && groups.length === 0" class="panel__note">
      {{ emptyNote }}
    </p>

    <div class="finder">
      <!-- Подпись и поле в одну строку: порознь они занимают два ряда на
           каждой из трёх панелей подряд, а сказать им нужно одно. -->
      <div class="finder__row">
        <label :for="inputId">{{ addLabel }}</label>
        <input
          :id="inputId"
          v-model="query"
          type="search"
          autocomplete="off"
          :placeholder="searchPlaceholder ?? 'Фамилия или почта'"
        >
      </div>

      <ul v-if="hasCandidates" class="finder__results">
        <li v-for="group in groupCandidates" :key="`group-${group.id}`">
          <button type="button" class="finder__option" @click="addGroup(group)">
            <span class="people__group-mark" aria-hidden="true">Гр</span>
            <span class="people__name">
              {{ group.name }}
              <span class="people__email">Группа · {{ groupSize(group) }}</span>
            </span>
          </button>
        </li>

        <li v-for="person in candidates" :key="person.id">
          <button type="button" class="finder__option" @click="add(person)">
            <UserAvatar :name="person.name" :src="person.avatar_url" :size="28" />
            <span class="people__name">
              {{ person.name }}
              <span v-if="person.email" class="people__email">{{ person.email }}</span>
            </span>
          </button>
        </li>
      </ul>

      <p v-else-if="query.trim() && !isSearching" class="panel__note">
        {{ notFoundNote }}
      </p>
    </div>
  </section>
</template>

<style scoped>
/*
 * Плотнее, чем было. Панель — список из трёх-четырёх строк и поля поиска;
 * прежние отступы отдавали ей высоту целого экрана, и редактор материала, где
 * таких панелей три подряд, приходилось листать мимо пустоты.
 *
 * Отступ сверху остаётся: на странице курса панели идут подряд без общего
 * промежутка, и без него они слиплись бы. Там, где промежуток есть (редактор
 * материала), он складывается с этим — потому и уменьшен до одного шага.
 */
.panel {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  margin-top: 1rem;
  padding: 1rem 1.15rem 1.05rem;
}

.panel__header {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.75rem;
}

.panel__title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 500;
}

.panel__status,
.panel__note {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.people {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.people__item {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.3rem 0.1rem;
  border-bottom: 1px solid var(--color-border-subtle, var(--color-border));
}

.people__item:last-child {
  border-bottom: none;
}

.people__name {
  display: flex;
  flex-direction: column;
  min-width: 0;
  font-size: 0.92rem;
  line-height: 1.25;
}

.people__email {
  color: var(--color-text-faint);
  font-size: 0.8rem;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* Метка группы стоит там же, где у человека лицо, и того же размера: строки
   идут вперемешку, и без общей левой границы список ломается на две колонки.
   Буквы, а не значок: значков в оформлении нет вовсе. */
.people__group-mark {
  display: grid;
  place-items: center;
  width: 28px;
  height: 28px;
  flex-shrink: 0;
  border-radius: var(--radius-pill);
  background: var(--color-accent-soft);
  color: var(--color-accent);
  font-size: 0.72rem;
  font-weight: 600;
}

.people__remove {
  margin-left: auto;
  padding: 0.25rem 0.6rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-pill);
  background: transparent;
  color: var(--color-text-muted);
  font: inherit;
  font-size: 0.82rem;
  cursor: pointer;
}

.people__remove:hover:not(:disabled) {
  border-color: var(--color-danger);
  color: var(--color-danger);
}

.people__item--author .badge {
  margin-left: auto;
}

.finder {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.finder__row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.6rem;
}

.finder label {
  flex-shrink: 0;
  font-size: 0.875rem;
  font-weight: 500;
}

.finder input {
  flex: 1 1 12rem;
  min-width: 0;
  max-width: 22rem;
  padding: 0.45rem 0.65rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface);
  color: var(--color-text);
  font: inherit;
}

.finder__results {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.finder__option {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  width: 100%;
  padding: 0.4rem 0.5rem;
  border: none;
  border-radius: var(--radius);
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.finder__option:hover {
  background: var(--color-surface-sunken);
}
</style>
