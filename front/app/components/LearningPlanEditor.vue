<script setup lang="ts">
import { ApiValidationError } from '~/composables/useAuth'
import type { Course, LearningPlanItem, PlannableKind, Regulation } from '~/types/lms'

/**
 * План обучения одного сотрудника: что ему назначено, как далеко он продвинулся
 * и — тому, кто ведёт обучение, — сам порядок шагов.
 *
 * Один и тот же вид на двух экранах: на «Планах обучения», где сотрудника
 * сначала находят, и в его карточке, где он уже известен. Разница только в
 * том, кто смотрит: читать план дают всякому, кто открыл карточку, а править —
 * тому, кому доверено обучение (`enrollments.manage`; должность администратора
 * это право включает).
 */
const props = withDefaults(defineProps<{
  learnerId: number
  /** Можно ли трогать: без этого план только читают. */
  editable?: boolean
}>(), { editable: false })

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

const { fetchPlan, savePlan, fetchCourses } = useLmsApi()
const { fetchRegulations } = useRegulationsApi()

function asStep(item: Course | Regulation, kind: PlannableKind): Step {
  return { kind, id: item.id, title: item.title, slug: item.slug }
}

/**
 * Черновик — просто порядок шагов: он и есть то, что уходит на сервер.
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

/**
 * Как идут дела — по сохранённому плану, а не по черновику: добавленный, но не
 * сохранённый шаг сотруднику ещё не назначен, и считать его в «не начато»
 * значило бы отчитываться о том, чего нет.
 *
 * Доля — среднее по шагам, а не «пройдено из назначенного»: курс, пройденный
 * наполовину, — это половина шага, и план, где начаты все три курса, честнее
 * показать четвертью, чем нулём.
 */
const stats = computed(() => {
  const items = saved.value
  const total = items.length
  const completed = items.filter(item => item.is_completed).length
  const started = items.filter(item => item.is_started && !item.is_completed).length
  const share = total === 0
    ? 0
    : Math.round(items.reduce((sum, item) => sum + item.progress, 0) / total)

  return { total, completed, started, untouched: total - completed - started, share }
})

function asDraft(items: LearningPlanItem[]): Step[] {
  return items.map(step => ({
    kind: step.kind,
    id: step.item_id,
    title: step.title ?? 'Материал удалён',
    slug: step.slug ?? '',
  }))
}

async function load() {
  isLoading.value = true
  loadError.value = null

  try {
    const { data } = await fetchPlan(props.learnerId)
    saved.value = data
    draft.value = asDraft(data)
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

// Сотрудник может смениться под тем же экраном — на «Планах обучения» выбирают
// другого, не уходя со страницы.
watch(() => props.learnerId, () => {
  saveError.value = null
  savedAt.value = null
  void load()
}, { immediate: true })

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
  isSaving.value = true
  saveError.value = null
  savedAt.value = null

  try {
    const { data } = await savePlan(
      props.learnerId,
      draft.value.map(step => ({ type: step.kind, id: step.id })),
    )

    saved.value = data
    draft.value = asDraft(data)
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

function plan(step: Step) {
  material.clear()
  add(step)
}

function stepLink(step: Step): string {
  return step.kind === 'regulation' ? `/lms/regulations/${step.slug}` : `/lms/${step.slug}`
}
</script>

<template>
  <div class="plan">
    <p v-if="loadError" class="alert alert--danger" role="alert">
      {{ loadError }}
    </p>

    <template v-else>
      <div v-if="isLoading" class="skeleton skeleton-line" />

      <template v-else>
        <!-- Как идут дела — первым: план открывают, чтобы это и узнать. -->
        <div v-if="stats.total" class="stats">
          <UiProgressBar :value="stats.share" :label="`${stats.share}%`" />

          <p class="faint stats__counts">
            Пройдено {{ stats.completed }} из {{ stats.total }}
            {{ pluralise(stats.total, 'шага', 'шагов', 'шагов') }}<template v-if="stats.started">,
              в работе {{ stats.started }}</template><template v-if="stats.untouched">,
              не начато {{ stats.untouched }}</template>
          </p>
        </div>

        <p v-if="!draft.length" class="faint">
          {{ editable
            ? 'План пуст. Найдите курс или регламент ниже и добавьте его первым шагом.'
            : 'План пуст: сотруднику ничего не назначено.' }}
        </p>

        <ol v-else class="steps">
          <li v-for="(step, index) in draft" :key="`${step.kind}:${step.id}`" class="step">
            <span class="step__number">{{ index + 1 }}</span>

            <span class="step__body">
              <NuxtLink :to="stepLink(step)" class="step__title">
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

            <span v-if="editable" class="step__actions">
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

        <template v-if="editable">
          <p v-if="saveError" class="alert alert--danger" role="alert">
            {{ saveError }}
          </p>

          <div class="field">
            <label class="field-label" :for="`material-search-${learnerId}`">Добавить курс или регламент</label>
            <input
              :id="`material-search-${learnerId}`"
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

          <div class="actions">
            <button type="button" class="button-primary" :disabled="isSaving || !isDirty" @click="save">
              Сохранить план
            </button>
            <span v-if="isSaving" class="faint">Сохраняем…</span>
            <span v-else-if="isDirty" class="faint">Есть несохранённые изменения</span>
            <span v-else-if="savedAt" class="faint">Сохранён в {{ savedAt }}</span>
          </div>
        </template>
      </template>
    </template>
  </div>
</template>

<style scoped>
.plan {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.stats {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.stats__counts {
  margin: 0;
}

.actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
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

.found__body {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  min-width: 0;
  text-align: left;
  font-size: 0.9rem;
}

.found__name {
  font-weight: 550;
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
