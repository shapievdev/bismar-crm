<script setup lang="ts">
import type { CoursePerson } from '~/types/lms'

definePageMeta({ middleware: 'auth', permission: 'enrollments.manage' })
useHead({ title: 'Планы обучения' })

const { searchPlanPeople } = useLmsApi()
const { isAdmin } = useAuth()

/**
 * Право «вести обучение» открывает чужие планы, а меняет их должность:
 * администратор или суперадминистратор. Сервер проверяет то же самое — здесь
 * экран просто не предлагает того, чего нельзя.
 */
const mayEdit = computed(() => isAdmin.value)

/**
 * Здесь сотрудника сначала находят — в его карточке он уже известен. Сам план
 * на обоих экранах один и тот же: LearningPlanEditor.
 */
const learner = ref<CoursePerson | null>(null)

const people = useDebouncedSearch<CoursePerson>(
  async term => (await searchPlanPeople(term)).data,
)

function pick(person: CoursePerson) {
  people.clear()
  learner.value = person
}
</script>

<template>
  <section>
    <header class="head">
      <div>
        <h1 class="page-title">
          Планы обучения
        </h1>
        <p class="page-subtitle">
          Что сотруднику пройти и в каком порядке. Порядок — подсказка: открыть он может любой шаг.
        </p>
      </div>
    </header>

    <section class="card panel">
      <header class="panel__head">
        <h2 class="panel__title">
          Сотрудник
        </h2>
        <button v-if="learner" type="button" class="button-ghost button-sm" @click="learner = null">
          Выбрать другого
        </button>
      </header>

      <div v-if="learner" class="person">
        <UserAvatar :name="learner.name" :src="learner.avatar_url" :size="36" />
        <div class="person__body">
          <NuxtLink :to="`/staff/${learner.id}`" class="person__name">
            {{ learner.name }}
          </NuxtLink>
          <span class="faint">{{ learner.email }}</span>
        </div>
      </div>

      <template v-else>
        <div class="field">
          <label class="field-label" for="person-search">Найдите по фамилии или почте</label>
          <input
            id="person-search"
            v-model="people.query.value"
            class="input"
            type="search"
            autocomplete="off"
            placeholder="Например, Ёлкина"
          >
        </div>

        <p v-if="people.isSearching.value" class="faint">
          Ищем…
        </p>
        <p v-else-if="people.query.value.trim() && !people.results.value.length" class="faint">
          Никого не нашли.
        </p>

        <ul v-else-if="people.results.value.length" class="found">
          <li v-for="person in people.results.value" :key="person.id">
            <button type="button" class="found__item" @click="pick(person)">
              <UserAvatar :name="person.name" :src="person.avatar_url" :size="28" />
              <span class="found__body">
                <span class="found__name">{{ person.name }}</span>
                <span class="faint">{{ person.email }}</span>
              </span>
            </button>
          </li>
        </ul>
      </template>
    </section>

    <section v-if="learner" class="card panel">
      <header class="panel__head">
        <h2 class="panel__title">
          План
        </h2>
      </header>

      <p v-if="!mayEdit" class="faint">
        Только просмотр: менять план может администратор или суперадминистратор.
      </p>

      <LearningPlanEditor :learner-id="learner.id" :editable="mayEdit" />
    </section>
  </section>
</template>

<style scoped>
.head {
  margin-bottom: 1.75rem;
}

.panel {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1.4rem 1.5rem;
  margin-bottom: 1rem;
}

.panel__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}

.panel__title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 600;
}

.person {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.person__body,
.found__body {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  min-width: 0;
  text-align: left;
  font-size: 0.9rem;
}

.person__name,
.found__name {
  font-weight: 550;
}

/* Имя выбранного ведёт в его карточку: оттуда видно и остальное о человеке. */
.person__name {
  color: inherit;
  text-decoration: none;
}

.person__name:hover {
  text-decoration: underline;
}

.found {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  margin: 0;
  padding: 0;
  list-style: none;
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
</style>
