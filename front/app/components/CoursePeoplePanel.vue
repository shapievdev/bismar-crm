<script setup lang="ts">
import type { CoursePerson } from '~/types/lms'

/**
 * Список людей у курса: показать, убрать, найти и добавить.
 *
 * Общая часть двух списков — допущенных к приватному курсу и ответственных за
 * него. Разного в них только надписи и то, куда ходить за данными; всё
 * остальное — поиск с задержкой, порядок, разметка — устроено одинаково, и
 * держать это дважды значит однажды поправить в одном месте.
 *
 * Панель ничего не решает сама: она показывает то, что дали, и сообщает о
 * нажатиях. Кто и как сохраняет — дело того, кто её поставил.
 */
const props = defineProps<{
  title: string
  /** Пояснение над списком: когда список ни на что не влияет, например. */
  note?: string | null
  people: CoursePerson[]
  isLoading: boolean
  isSaving: boolean
  errorMessage?: string | null
  /** Строка, которую нельзя убрать, — автор курса в списке доступа. */
  fixedName?: string | null
  fixedBadge?: string | null
  emptyNote: string
  addLabel: string
  notFoundNote: string
  search: (term: string) => Promise<CoursePerson[]>
}>()

const emit = defineEmits<{
  add: [person: CoursePerson]
  remove: [person: CoursePerson]
}>()

function add(person: CoursePerson) {
  query.value = ''
  candidates.value = []

  if (!props.people.some(one => one.id === person.id)) {
    emit('add', person)
  }
}

/* ---------- Поиск ---------- */

const query = ref('')
const candidates = ref<CoursePerson[]>([])
const isSearching = ref(false)

let searchTimer: ReturnType<typeof setTimeout> | undefined
let searchToken = 0

/**
 * Ищет с задержкой, и показывает только ответ на последний запрос: набранное
 * целиком приходит раньше, чем ответ на первую букву, и без этого список
 * подсказок мигал бы результатами уже стёртого слова.
 */
watch(query, (value) => {
  clearTimeout(searchTimer)

  const term = value.trim()

  if (term === '') {
    candidates.value = []
    isSearching.value = false

    return
  }

  isSearching.value = true

  searchTimer = setTimeout(async () => {
    const token = ++searchToken

    try {
      const found = await props.search(term)

      if (token === searchToken) {
        candidates.value = found
      }
    }
    catch {
      if (token === searchToken) {
        candidates.value = []
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
        <UserAvatar :name="fixedName" :size="32" />
        <span class="people__name">{{ fixedName ?? 'Автор удалён' }}</span>
        <span class="badge">{{ fixedBadge }}</span>
      </li>

      <li v-for="person in people" :key="person.id" class="people__item">
        <UserAvatar :name="person.name" :src="person.avatar_url" :size="32" />
        <span class="people__name">
          {{ person.name }}
          <span class="people__email">{{ person.email }}</span>
        </span>
        <button type="button" class="people__remove" :disabled="isSaving" @click="emit('remove', person)">
          Убрать
        </button>
      </li>
    </ul>

    <p v-if="!isLoading && people.length === 0" class="panel__note">
      {{ emptyNote }}
    </p>

    <div class="finder">
      <label :for="inputId">{{ addLabel }}</label>
      <input
        :id="inputId"
        v-model="query"
        type="search"
        autocomplete="off"
        placeholder="Фамилия или почта"
      >

      <ul v-if="candidates.length" class="finder__results">
        <li v-for="person in candidates" :key="person.id">
          <button type="button" class="finder__option" @click="add(person)">
            <UserAvatar :name="person.name" :src="person.avatar_url" :size="28" />
            <span class="people__name">
              {{ person.name }}
              <span class="people__email">{{ person.email }}</span>
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
.panel {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
  margin-top: 1.75rem;
  padding: 1.25rem 1.35rem 1.4rem;
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
  gap: 0.65rem;
  padding: 0.4rem 0.15rem;
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

.finder label {
  font-size: 0.875rem;
  font-weight: 500;
}

.finder input {
  padding: 0.55rem 0.7rem;
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
