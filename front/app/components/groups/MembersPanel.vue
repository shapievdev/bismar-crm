<script setup lang="ts">
import type { Group, Person } from '~/types/structure'

/**
 * Состав группы: кто в ней, кого добавить и кого убрать.
 *
 * Панель ничего не решает сама — показывает то, что дали, и сообщает о
 * нажатиях. Кто и как сохраняет, дело того, кто её поставил: так же устроена
 * панель людей у курса и у отдела.
 */
const props = defineProps<{
  group: Group
  /** Правит состав администратор; остальным панель только показывает. */
  canManage: boolean
  isSaving: boolean
  errorMessage?: string | null
  /** Кого можно добавить: работающие сотрудники, поиском. */
  search: (term: string) => Promise<Person[]>
}>()

const emit = defineEmits<{
  add: [person: Person]
  remove: [person: Person]
  rename: []
  delete: []
}>()

const people = computed<Person[]>(() => props.group.people ?? [])

const candidates = useDebouncedSearch<Person>(term => props.search(term))

/**
 * Уже состоящий второй раз не добавляется: сервер это переживает, но лишний
 * запрос ради «ничего не изменилось» посылать незачем.
 */
function add(person: Person) {
  candidates.clear()

  if (!people.value.some(one => one.id === person.id)) {
    emit('add', person)
  }
}
</script>

<template>
  <section class="card panel">
    <header class="panel__head">
      <div>
        <h2 class="panel__title">
          {{ group.name }}
        </h2>
        <p v-if="group.description" class="faint">
          {{ group.description }}
        </p>
      </div>

      <div v-if="canManage" class="panel__actions">
        <button type="button" class="button-ghost button-sm" @click="emit('rename')">
          Переименовать
        </button>
        <button type="button" class="button-danger button-sm" @click="emit('delete')">
          Удалить группу
        </button>
      </div>
    </header>

    <p v-if="errorMessage" class="auth-alert" role="alert">
      {{ errorMessage }}
    </p>

    <ul v-if="people.length" class="people">
      <li v-for="person in people" :key="person.id" class="people__item">
        <UserAvatar :name="person.name" :src="person.avatar_url" :size="32" />
        <span class="people__body">
          <NuxtLink :to="`/staff/${person.id}`" class="people__name">
            {{ person.name }}
          </NuxtLink>
          <span class="faint">{{ person.job_title ?? 'Должность не указана' }}</span>
        </span>

        <button
          v-if="canManage"
          type="button"
          class="button-ghost button-sm"
          :disabled="isSaving"
          :aria-label="`Убрать ${person.name} из группы`"
          @click="emit('remove', person)"
        >
          Убрать
        </button>
      </li>
    </ul>

    <p v-else class="faint">
      В группе пока никого. Найдите людей и добавьте.
    </p>

    <div v-if="canManage" class="add">
      <div class="field">
        <label class="field-label" :for="`group-person-${group.id}`">Добавить сотрудника</label>
        <input
          :id="`group-person-${group.id}`"
          v-model="candidates.query.value"
          class="input"
          type="search"
          autocomplete="off"
          placeholder="Фамилия или почта"
        >
      </div>

      <p v-if="candidates.isSearching.value" class="faint">
        Ищем…
      </p>
      <p v-else-if="candidates.query.value.trim() && !candidates.results.value.length" class="faint">
        Никого не нашли.
      </p>

      <ul v-else-if="candidates.results.value.length" class="found">
        <li v-for="person in candidates.results.value" :key="person.id">
          <button type="button" class="found__item" :disabled="isSaving" @click="add(person)">
            <UserAvatar :name="person.name" :src="person.avatar_url" :size="28" />
            <span class="found__body">
              <span>{{ person.name }}</span>
              <span class="faint">{{ person.job_title ?? 'Должность не указана' }}</span>
            </span>
          </button>
        </li>
      </ul>
    </div>
  </section>
</template>

<style scoped>
.panel {
  display: flex;
  flex-direction: column;
  gap: 0.9rem;
  /* Карточка сама отступов не держит — их назначает тот, кто её ставит; так же
     устроены панели у курса и у отдела. */
  padding: 1.4rem 1.5rem;
}

.panel__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
}

.panel__title {
  margin: 0;
  font-size: 1.05rem;
}

.panel__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.people {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
}

.people__item {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  padding: 0.5rem 0;
  border-bottom: 1px solid var(--color-border);
}

.people__item:last-child {
  border-bottom: none;
}

.people__body {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1;
}

.people__name {
  color: inherit;
  text-decoration: none;
  font-weight: 500;
}

.people__name:hover {
  text-decoration: underline;
}

/* Набор людей отделён от самого состава: это уже не «кто в группе», а «кого
   добавить», и без черты одно читается продолжением другого. */
.add {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  padding-top: 0.9rem;
  border-top: 1px solid var(--color-border);
}

.found {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.found__item {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  width: 100%;
  padding: 0.5rem 0.6rem;
  border: 0;
  border-radius: var(--radius);
  background: transparent;
  color: inherit;
  font: inherit;
  cursor: pointer;
}

.found__item:hover:not(:disabled) {
  background: var(--color-surface-sunken);
}

.found__body {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

@media (max-width: 40rem) {
  .panel__head {
    flex-direction: column;
  }
}
</style>
