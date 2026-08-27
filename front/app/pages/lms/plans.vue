<script setup lang="ts">
import { ApiValidationError } from '~/composables/useAuth'
import type { Course, CoursePerson, LearningPlanItem, PlannableKind, Regulation } from '~/types/lms'

/**
 * Шаг черновика: то немногое, что нужно, чтобы показать строку и отправить её.
 *
 * Курс и регламент приводятся к одному виду сразу — экран рисует список
 * одинаковых строк, и разбирать в каждой, чем именно она оказалась, незачем.
 */
interface Step {
  kind: PlannableKind
  id: number
  title: string
  slug: string
}

definePageMeta({ middleware: 'auth', permission: 'enrollments.manage' })
useHead({ title: 'Планы обучения' })

const { fetchPlan, savePlan, searchPlanPeople, fetchCourses } = useLmsApi()
const { fetchRegulations } = useRegulationsApi()

function asStep(item: Course | Regulation, kind: PlannableKind): Step {
  return { kind, id: item.id, title: item.title, slug: item.slug }
}

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
const draft = ref<Step[]>([])
const saved = ref<LearningPlanItem[]>([])

const isLoading = ref(false)
const isSaving = ref(false)
const loadError = ref<string | null>(null)
const saveError = ref<string | null>(null)
const savedAt = ref<string | null>(null)

/** Ключ шага — вид и номер вместе: курс №3 и регламент №3 разные вещи. */
function keyOf(step: { kind: string, id?: number, item_id?: number }): string {
  return `${step.kind}:${step.id ?? step.item_id}`
}

const plannedKeys = computed(() => new Set(draft.value.map(keyOf)))

const isDirty = computed(() => {
  const before = saved.value.map(keyOf)
  const after = draft.value.map(keyOf)

  return before.length !== after.length || before.some((key, index) => key !== after[index])
})

function stepFor(step: Step): LearningPlanItem | undefined {
  return saved.value.find(one => keyOf(one) === keyOf(step))
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
    draft.value = data.map(step => ({
      kind: step.kind,
      id: step.item_id,
      title: step.title ?? 'Материал удалён',
      slug: step.slug ?? '',
    }))
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

function add(step: Step) {
  if (!plannedKeys.value.has(keyOf(step))) {
    draft.value = [...draft.value, step]
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
    const { data } = await savePlan(
      learner.value.id,
      draft.value.map(step => ({ type: step.kind, id: step.id })),
    )

    saved.value = data
    draft.value = data.map(step => ({
      kind: step.kind,
      id: step.item_id,
      title: step.title ?? 'Материал удалён',
      slug: step.slug ?? '',
    }))
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

/**
 * Поиск сразу по обоим видам: составителю всё равно, курс это или регламент, —
 * ему нужно то, что называется так, как он набрал.
 */
const material = useDebouncedSearch<Step>(async (term) => {
  const [courses, regulations] = await Promise.all([
    fetchCourses({ search: term }),
    fetchRegulations({ search: term }),
  ])

  return [
    ...courses.data.map(course => asStep(course, 'course')),
    ...regulations.data.map(regulation => asStep(regulation, 'regulation')),
  ]
})

function pick(person: CoursePerson) {
  people.clear()
  void choose(person)
}

function plan(step: Step) {
  material.clear()
  add(step)
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
          План пуст. Найдите курс или регламент ниже и добавьте его первым шагом.
        </p>

        <ol v-else class="steps">
          <li v-for="(step, index) in draft" :key="`${step.kind}:${step.id}`" class="step">
            <span class="step__number">{{ index + 1 }}</span>

            <span class="step__body">
              <NuxtLink
                :to="step.kind === 'regulation' ? `/lms/regulations/${step.slug}` : `/lms/${step.slug}`"
                class="step__title"
              >
                {{ step.title }}
              </NuxtLink>

              <span class="faint">
                <!-- Вид называем прямо: в одном списке курс и регламент, и по
                     названию их не различить. -->
                {{ step.kind === 'regulation' ? 'Регламент' : 'Курс' }} ·
                <template v-if="stepFor(step)?.is_completed">пройден</template>
                <template v-else-if="stepFor(step)?.is_started">пройдено {{ stepFor(step)?.progress }}%</template>
                <template v-else-if="stepFor(step)">ещё не начат</template>
                <template v-else>новый шаг, сохраните, чтобы назначить</template>
              </span>

              <!-- Материал могли закрыть уже после назначения: у себя сотрудник
                   такой шаг не увидит, и сказать об этом надо здесь. -->
              <span v-if="stepFor(step)?.is_visible_to_learner === false" class="step__warning">
                Сотрудник это не видит — закрыто от него
              </span>
            </span>

            <span class="step__actions">
              <button
                type="button"
                class="button-ghost button-sm"
                :disabled="index === 0"
                :aria-label="`Выше: ${step.title}`"
                @click="move(index, -1)"
              >
                ↑
              </button>
              <button
                type="button"
                class="button-ghost button-sm"
                :disabled="index === draft.length - 1"
                :aria-label="`Ниже: ${step.title}`"
                @click="move(index, 1)"
              >
                ↓
              </button>
              <button
                type="button"
                class="button-ghost button-sm"
                :aria-label="`Убрать: ${step.title}`"
                @click="remove(index)"
              >
                Убрать
              </button>
            </span>
          </li>
        </ol>

        <div class="field">
          <label class="field-label" for="material-search">Добавить курс или регламент</label>
          <input
            id="material-search"
            v-model="material.query.value"
            class="input"
            type="search"
            autocomplete="off"
            placeholder="Название"
          >
        </div>

        <p v-if="material.isSearching.value" class="faint">
          Ищем…
        </p>
        <p v-else-if="material.query.value.trim() && !material.results.value.length" class="faint">
          Ничего не нашли.
        </p>

        <ul v-else-if="material.results.value.length" class="found">
          <li v-for="found in material.results.value" :key="`${found.kind}:${found.id}`">
            <button
              type="button"
              class="found__item"
              :disabled="plannedKeys.has(`${found.kind}:${found.id}`)"
              @click="plan(found)"
            >
              <span class="found__body">
                <span class="found__name">{{ found.title }}</span>
                <span class="faint">
                  {{ found.kind === 'regulation' ? 'Регламент' : 'Курс' }}<template
                    v-if="plannedKeys.has(`${found.kind}:${found.id}`)"
                  > — уже в плане</template>
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
