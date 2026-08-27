<script setup lang="ts">
import { ApiValidationError } from '~/composables/useAuth'
import type { Course, CoursePerson, LearningPlanItem } from '~/types/lms'

definePageMeta({ middleware: 'auth', permission: 'enrollments.manage' })
useHead({ title: 'Планы обучения' })

const { fetchPlan, savePlan, searchPlanPeople, fetchCourses } = useLmsApi()

/* ---------- Кому составляем ---------- */

const learner = ref<CoursePerson | null>(null)

/* ---------- План ---------- */

/**
 * Черновик — просто порядок курсов: он и есть то, что уходит на сервер.
 *
 * Сохранённые шаги держим отдельно, потому что они несут то, чего у только что
 * добавленного курса ещё нет, — прогресс сотрудника и отметку о том, увидит ли
 * он этот шаг у себя.
 */
const draft = ref<Course[]>([])
const saved = ref<LearningPlanItem[]>([])

const isLoading = ref(false)
const isSaving = ref(false)
const loadError = ref<string | null>(null)
const saveError = ref<string | null>(null)
const savedAt = ref<string | null>(null)

const plannedIds = computed(() => new Set(draft.value.map(course => course.id)))

const isDirty = computed(() => {
  const before = saved.value.map(step => step.course.id)
  const after = draft.value.map(course => course.id)

  return before.length !== after.length || before.some((id, index) => id !== after[index])
})

function stepFor(course: Course): LearningPlanItem | undefined {
  return saved.value.find(step => step.course.id === course.id)
}

async function choose(person: CoursePerson) {
  learner.value = person
  saveError.value = null
  savedAt.value = null
  await load()
}

async function load() {
  if (learner.value === null) {
    return
  }

  isLoading.value = true
  loadError.value = null

  try {
    const { data } = await fetchPlan(learner.value.id)
    saved.value = data
    draft.value = data.map(step => step.course)
  }
  catch {
    loadError.value = 'Не удалось загрузить план.'
    saved.value = []
    draft.value = []
  }
  finally {
    isLoading.value = false
  }
}

function add(course: Course) {
  if (!plannedIds.value.has(course.id)) {
    draft.value = [...draft.value, course]
  }
}

function remove(index: number) {
  draft.value = draft.value.filter((_, at) => at !== index)
}

/** Перестановка соседей: план читают как очередь, и двигают по одному шагу. */
function move(index: number, by: -1 | 1) {
  const target = index + by
  const next = [...draft.value]
  const moved = next[index]
  const displaced = next[target]

  if (moved === undefined || displaced === undefined) {
    return
  }

  next[index] = displaced
  next[target] = moved
  draft.value = next
}

async function save() {
  if (learner.value === null) {
    return
  }

  isSaving.value = true
  saveError.value = null
  savedAt.value = null

  try {
    const { data } = await savePlan(learner.value.id, draft.value.map(course => course.id))
    saved.value = data
    draft.value = data.map(step => step.course)
    savedAt.value = new Date().toLocaleTimeString('ru-RU')
  }
  catch (caught) {
    saveError.value = caught instanceof ApiValidationError
      ? Object.values(caught.errors)[0]?.[0] ?? 'Не удалось сохранить план.'
      : 'Не удалось сохранить план.'
  }
  finally {
    isSaving.value = false
  }
}

/* ---------- Поиск людей и курсов ---------- */

const people = useDebouncedSearch<CoursePerson>(
  async term => (await searchPlanPeople(term)).data,
)

const courses = useDebouncedSearch<Course>(
  async term => (await fetchCourses({ search: term })).data,
)

function pick(person: CoursePerson) {
  people.clear()
  void choose(person)
}

function plan(course: Course) {
  courses.clear()
  add(course)
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
          <span class="person__name">{{ learner.name }}</span>
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

    <template v-if="learner">
      <p v-if="loadError" class="alert alert--danger" role="alert">
        {{ loadError }}
      </p>

      <section v-else class="card panel">
        <header class="panel__head">
          <h2 class="panel__title">
            План
          </h2>
          <span v-if="isSaving" class="faint">Сохраняем…</span>
          <span v-else-if="savedAt" class="faint">Сохранён в {{ savedAt }}</span>
        </header>

        <p v-if="saveError" class="alert alert--danger" role="alert">
          {{ saveError }}
        </p>

        <div v-if="isLoading" class="skeleton skeleton-line" />

        <p v-else-if="!draft.length" class="faint">
          План пуст. Найдите курс ниже и добавьте его первым шагом.
        </p>

        <ol v-else class="steps">
          <li v-for="(course, index) in draft" :key="course.id" class="step">
            <span class="step__number">{{ index + 1 }}</span>

            <span class="step__body">
              <NuxtLink :to="`/lms/${course.slug}`" class="step__title">{{ course.title }}</NuxtLink>

              <span class="faint">
                <template v-if="stepFor(course)?.is_completed">Пройден</template>
                <template v-else-if="stepFor(course)?.is_started">Пройдено {{ stepFor(course)?.progress }}%</template>
                <template v-else-if="stepFor(course)">Ещё не начат</template>
                <template v-else>Новый шаг — сохраните, чтобы назначить</template>
              </span>

              <!-- Курс могли закрыть уже после назначения: у себя сотрудник
                   такой шаг не увидит, и сказать об этом надо здесь. -->
              <span v-if="stepFor(course)?.is_visible_to_learner === false" class="step__warning">
                Сотрудник этот курс не видит — он закрыт от него
              </span>
            </span>

            <span class="step__actions">
              <button
                type="button"
                class="button-ghost button-sm"
                :disabled="index === 0"
                :aria-label="`Выше: ${course.title}`"
                @click="move(index, -1)"
              >
                ↑
              </button>
              <button
                type="button"
                class="button-ghost button-sm"
                :disabled="index === draft.length - 1"
                :aria-label="`Ниже: ${course.title}`"
                @click="move(index, 1)"
              >
                ↓
              </button>
              <button
                type="button"
                class="button-ghost button-sm"
                :aria-label="`Убрать: ${course.title}`"
                @click="remove(index)"
              >
                Убрать
              </button>
            </span>
          </li>
        </ol>

        <div class="field">
          <label class="field-label" for="course-search">Добавить курс</label>
          <input
            id="course-search"
            v-model="courses.query.value"
            class="input"
            type="search"
            autocomplete="off"
            placeholder="Название курса"
          >
        </div>

        <p v-if="courses.isSearching.value" class="faint">
          Ищем…
        </p>
        <p v-else-if="courses.query.value.trim() && !courses.results.value.length" class="faint">
          Ничего не нашли.
        </p>

        <ul v-else-if="courses.results.value.length" class="found">
          <li v-for="course in courses.results.value" :key="course.id">
            <button
              type="button"
              class="found__item"
              :disabled="plannedIds.has(course.id)"
              @click="plan(course)"
            >
              <span class="found__body">
                <span class="found__name">{{ course.title }}</span>
                <span class="faint">
                  {{ course.status_label }}<template v-if="plannedIds.has(course.id)"> — уже в плане</template>
                </span>
              </span>
            </button>
          </li>
        </ul>

        <div class="panel__actions">
          <button type="button" class="button-primary" :disabled="isSaving || !isDirty" @click="save">
            Сохранить план
          </button>
          <span v-if="isDirty" class="faint">Есть несохранённые изменения</span>
        </div>
      </section>
    </template>
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

.panel__actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
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

.found__item:disabled {
  cursor: default;
  opacity: 0.55;
}

.steps {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.step {
  display: flex;
  align-items: center;
  gap: 0.85rem;
  padding: 0.7rem 0.8rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
}

.step__number {
  display: inline-flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  width: 1.85rem;
  height: 1.85rem;
  border-radius: 50%;
  background: var(--color-surface-sunken);
  font-size: 0.85rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.step__body {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-width: 0;
  gap: 0.1rem;
  font-size: 0.9rem;
}

.step__title {
  color: inherit;
  font-weight: 550;
  text-decoration: none;
}

.step__title:hover {
  text-decoration: underline;
}

.step__warning {
  color: var(--color-danger);
  font-size: 0.825rem;
}

.step__actions {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  gap: 0.25rem;
}

.skeleton-line {
  width: 100%;
  height: 3rem;
}

@media (max-width: 40rem) {
  .step {
    flex-wrap: wrap;
  }

  .step__actions {
    width: 100%;
    justify-content: flex-end;
  }
}
</style>
