<script setup lang="ts">
import type { FetchError } from 'ofetch'
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'
import type { Achievement } from '~/types/achievements'
import type { QuizPayload } from '~/types/lms'
import type {
  Journey,
  JourneyStage,
  JourneyStagePayload,
  PlannableRow,
  ScheduleAnchor,
  StageItem,
} from '~/types/onboarding'

/**
 * Личный маршрут сотрудника — ведущему: карта, правка этапов и рычаги.
 *
 * Назначения нет: карта у человека своя и заводится открытием этой страницы.
 * Наполняют её двумя путями — руками (этап со своими материалами, встречами,
 * заданиями и тестом) и наложением шаблона, который дописывает свои этапы в
 * конец. Скопированное дальше живёт само: правка шаблона его не достаёт.
 */
definePageMeta({ middleware: 'auth', permission: 'onboarding.manage' })

const route = useRoute()
const api = useOnboardingApi()
const { confirm, prompt } = useAppDialog()

const employeeId = computed(() => Number(route.params.id))

const { data, pending, error } = await useAsyncData(
  `onboarding.employee.${employeeId.value}`,
  () => api.fetchEmployeeJourney(employeeId.value),
)

const journey = computed<Journey | null>(() => data.value?.data ?? null)

useHead({ title: () => journey.value ? `Маршрут — ${journey.value.employee.name}` : 'Маршрут' })

const actionError = ref<string | null>(null)
const stageSection = ref('main')
const rulesOpen = ref(false)
const isSavingRules = ref(false)
const applyOpen = ref(false)
const routeRules = ref({ settings: onboardingSettings(), allow_ahead: false, meeting_reminder_minutes: null as number | null })
watch(journey, value => { if (value) routeRules.value = { settings: JSON.parse(JSON.stringify(value.settings)), allow_ahead: value.allow_ahead, meeting_reminder_minutes: value.meeting_reminder_minutes } }, { immediate: true })

/** Каждое действие присылает маршрут целиком — положим его на место. */
async function run(action: () => Promise<{ data: Journey }>) {
  actionError.value = null

  try {
    data.value = await action()
  }
  catch (caught) {
    actionError.value = (caught as FetchError<{ message?: string }>).data?.message
      ?? 'Не получилось — попробуйте ещё раз.'
  }
}

async function saveRules() {
  isSavingRules.value = true
  try {
    await run(() => api.updateJourneySettings(employeeId.value, routeRules.value))
    if (!actionError.value) rulesOpen.value = false
  }
  finally { isSavingRules.value = false }
}

/* ---------- Выбранный этап ---------- */

const selectedId = ref<number | null>(Number(route.query.stage) || null)

const selectedStage = computed(() => {
  const stages = journey.value?.stages ?? []

  return stages.find(stage => stage.id === selectedId.value)
    ?? stages.find(stage => ['available', 'in_progress', 'quiz_failed'].includes(stage.status))
    ?? stages[0]
    ?? null
})

const detailElement = ref<HTMLElement | null>(null)
function selectStage(id: number) {
  selectedId.value = id
  if (window.matchMedia('(max-width: 959px)').matches) {
    nextTick(() => detailElement.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
  }
}

/* ---------- Наложение шаблона ---------- */

const { data: templatesData } = await useAsyncData('onboarding.templates', () => api.fetchTemplates())

const templateOptions = computed(() => (templatesData.value?.data ?? [])
  .filter(template => template.status === 'published')
  .map(template => ({ value: template.slug, label: `${template.title} · v${template.version}${template.store ? ` · ${template.store}` : ''}` })))

const templateSlug = ref('')
const anchor = ref<ScheduleAnchor>('today')
const anchorDate = ref('')
const isApplying = ref(false)

const anchorOptions: { value: ScheduleAnchor, label: string }[] = [
  { value: 'today', label: 'С сегодняшнего дня' },
  { value: 'hired', label: 'От даты приёма сотрудника' },
  { value: 'date', label: 'От выбранной даты' },
  { value: 'none', label: 'Без дат — расставлю сам' },
]

/** Что из шаблона человеку закрыто — предупреждаем до наложения. */
const hiddenMaterials = ref<{ stage_title: string, day_offset: number | null, title: string, is_required: boolean }[]>([])

watch(templateSlug, async (slug) => {
  hiddenMaterials.value = []

  if (!slug) {
    return
  }

  try {
    hiddenMaterials.value = (await api.checkTemplateVisibility(employeeId.value, slug)).data.hidden
  }
  catch {
    // Проверка — подсказка, а не замок: не вышла — наложению не мешаем.
  }
})

async function applyTemplate() {
  if (!templateSlug.value) {
    return
  }

  isApplying.value = true

  await run(() => api.applyTemplate(employeeId.value, templateSlug.value, {
    anchor: anchor.value,
    date: anchor.value === 'date' ? (anchorDate.value || null) : null,
  }))

  isApplying.value = false

  if (actionError.value === null) {
    templateSlug.value = ''
    hiddenMaterials.value = []
    applyOpen.value = false
  }
}

/* ---------- Этап: черновик формы ---------- */

interface StageDraft {
  is_final_exam: boolean
  responsible_ids: number[]
  due_at: string
  id: number | null
  title: string
  description: string
  day_offset: string | number
  /** Значок за весь этап и значок за его тест. */
  achievement_id: number | null
  quiz_achievement_id: number | null
  links: { type: string, id: number, title: string, is_required: boolean, day_offset: string | number, achievement_id: number | null }[]
  meetings: {
    id: number | null
    topic: string
    format: string
    place: string
    /** Значение поля «дата и время», в поясе браузера. Пусто — время уточняется. */
    scheduled_at: string
    participant_ids: number[]
    with_user_id: number | null
    with_name: string
    achievement_id: number | null
  }[]
  tasks: { id: number | null, text: string, responsible_ids: number[], confirm_by: string, is_required: boolean, achievement_id: number | null }[]
}

const stageDraft = ref<StageDraft | null>(null)
const stageErrors = ref<ValidationErrors>({})
const isSavingStage = ref(false)

watch(stageDraft, () => { stageSection.value = 'main' })

function blankStage(): StageDraft {
  const nextDay = Math.max(0, ...(journey.value?.stages ?? []).map(stage => stage.day_offset ?? 0)) + 1

  return {
    is_final_exam: false, responsible_ids: [], due_at: '',
    id: null,
    title: '',
    description: '',
    day_offset: journey.value?.stages.some(stage => stage.day_offset !== null) === false && journey.value?.stages.length
      ? ''
      : nextDay,
    achievement_id: null,
    quiz_achievement_id: null,
    links: [],
    meetings: [],
    tasks: [],
  }
}

function editStage(stage: JourneyStage) {
  stageDraft.value = {
    is_final_exam: stage.is_final_exam, responsible_ids: [...stage.responsible_ids], due_at: stage.due_at ? stage.due_at.slice(0, 10) : '',
    id: stage.id,
    title: stage.title,
    description: stage.description ?? '',
    day_offset: stage.day_offset ?? '',
    achievement_id: stage.achievement_id,
    quiz_achievement_id: stage.quiz_achievement_id,
    links: stage.items.filter(item => item.kind === 'material').map(item => ({
      // Материал опознаётся по ключу «вид:номер», как и приходит с сервера.
      type: String(item.meta.material_type ?? 'course'),
      id: Number(item.meta.material_id ?? 0),
      title: item.title,
      is_required: item.is_required,
      day_offset: item.meta.day_offset ?? '',
      achievement_id: item.meta.achievement?.id ?? null,
    })),
    meetings: stage.items.filter(item => item.kind === 'meeting').map(item => ({
      id: Number(item.meta.id),
      topic: item.title,
      format: String(item.meta.format ?? 'offline'),
      place: String(item.meta.place ?? ''),
      scheduled_at: toLocalInput(item.meta.scheduled_at),
      participant_ids: item.meta.participant_ids ?? (item.meta.with ? [item.meta.with.id] : []),
      with_user_id: item.meta.with?.id ?? null,
      with_name: item.meta.with?.name ?? '',
      achievement_id: item.meta.achievement?.id ?? null,
    })),
    tasks: stage.items.filter(item => item.kind === 'task').map(item => ({
      id: Number(item.meta.id),
      text: item.title,
      confirm_by: String(item.meta.confirm_by ?? 'self'),
      responsible_ids: [...(item.meta.responsible_ids ?? [])],
      is_required: item.is_required,
      achievement_id: item.meta.achievement?.id ?? null,
    })),
  }
}

/* ---------- Каталог материалов и люди ---------- */

const { data: materialsData } = await useAsyncData('onboarding.materials', () => api.fetchMaterials())

/** Каталог значков — один на редактор: строки этапа берут его отсюда. */
const { data: achievementsData } = await useAsyncData(
  'onboarding.achievements',
  () => useAchievementsApi().fetchAchievements(),
  { default: () => ({ data: [] }) },
)

const achievements = computed<Achievement[]>(() => achievementsData.value?.data ?? [])

const catalogue = computed<PlannableRow[]>(() => materialsData.value?.data ?? [])

const kindLabels: Record<string, string> = { course: 'Курс', document: 'Документ', handbook: 'Справочник' }

const materialOptions = computed(() => catalogue.value.map(row => ({
  value: `${row.kind}:${row.id}`,
  label: `${kindLabels[row.kind] ?? row.kind}: ${row.title}`,
})))

const materialToAdd = ref('')

watch(materialToAdd, (value) => {
  if (!value || !stageDraft.value) {
    return
  }

  const [kind, id] = value.split(':')
  const row = catalogue.value.find(one => one.kind === kind && one.id === Number(id))

  if (row && !stageDraft.value.links.some(link => link.type === kind && link.id === row.id)) {
    stageDraft.value.links.push({ type: row.kind, id: row.id, title: row.title, is_required: true, day_offset: '', achievement_id: null })
  }

  materialToAdd.value = ''
})





async function saveStage() {
  if (!stageDraft.value) {
    return
  }

  isSavingStage.value = true
  stageErrors.value = {}

  const payload: JourneyStagePayload = {
    id: stageDraft.value.id,
    is_final_exam: stageDraft.value.is_final_exam, responsible_ids: stageDraft.value.responsible_ids, due_at: stageDraft.value.due_at ? `${stageDraft.value.due_at}T23:59:59+03:00` : null,
    title: stageDraft.value.title,
    description: stageDraft.value.description || null,
    day_offset: stageDraft.value.day_offset === '' ? null : Number(stageDraft.value.day_offset),
    achievement_id: stageDraft.value.achievement_id,
    quiz_achievement_id: stageDraft.value.quiz_achievement_id,
    links: stageDraft.value.links.map(link => ({
      type: link.type,
      id: link.id,
      is_required: link.is_required,
      day_offset: link.day_offset === '' ? null : Number(link.day_offset),
      achievement_id: link.achievement_id,
    })),
    meetings: stageDraft.value.meetings.map(meeting => ({
      id: meeting.id,
      topic: meeting.topic,
      format: meeting.format,
      place: meeting.place || null,
      scheduled_at: fromLocalInput(meeting.scheduled_at),
      participant_ids: meeting.participant_ids,
      with_user_id: meeting.participant_ids[0] ?? null,
      achievement_id: meeting.achievement_id,
    })),
    tasks: stageDraft.value.tasks.map(task => ({
      id: task.id,
      text: task.text,
      confirm_by: task.confirm_by,
      responsible_ids: task.confirm_by === 'responsible' ? [...task.responsible_ids] : [],
      is_required: task.is_required,
      achievement_id: task.achievement_id,
    })),
  }

  try {
    data.value = await api.saveJourneyStage(employeeId.value, payload)
    stageDraft.value = null
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      stageErrors.value = caught.errors
      const firstError = Object.keys(caught.errors)[0] ?? ''
      stageSection.value = firstError.startsWith('links') ? 'materials' : firstError.startsWith('tasks') ? 'tasks' : firstError.startsWith('meetings') ? 'meetings' : 'main'
    }
    else {
      actionError.value = 'Не удалось сохранить этап.'
    }
  }
  finally {
    isSavingStage.value = false
  }
}

async function removeStage(stage: JourneyStage) {
  if (!await confirm({
    title: 'Убрать этап?',
    message: `«${stage.title}» исчезнет из маршрута вместе со своими заданиями и тестом.`,
    confirmLabel: 'Убрать',
    danger: true,
  })) {
    return
  }

  await run(() => api.deleteJourneyStage(employeeId.value, stage.id))
}

/* ---------- Тест этапа ---------- */

const quizErrors = ref<ValidationErrors>({})
const isSavingQuiz = ref(false)
const quizStageId = ref<number | null>(null)

async function saveQuiz(stageId: number, payload: QuizPayload) {
  isSavingQuiz.value = true
  quizErrors.value = {}

  try {
    await api.saveJourneyStageQuiz(employeeId.value, stageId, payload)
    data.value = await api.fetchEmployeeJourney(employeeId.value)
    quizStageId.value = null
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      quizErrors.value = caught.errors
    }
    else {
      actionError.value = 'Не удалось сохранить тест.'
    }
  }
  finally {
    isSavingQuiz.value = false
  }
}

async function removeQuiz(stageId: number) {
  if (!await confirm({
    title: 'Удалить тест этапа?',
    message: 'Вместе с ним уйдут попытки сотрудника и их разбор.',
    confirmLabel: 'Удалить',
    danger: true,
  })) {
    return
  }

  await api.deleteJourneyStageQuiz(employeeId.value, stageId)
  data.value = await api.fetchEmployeeJourney(employeeId.value)
}

/* ---------- Рычаги ---------- */

async function openNow(stage: JourneyStage) {
  await run(() => api.moveStage(employeeId.value, stage.id, null))
}

async function move(stage: JourneyStage) {
  const date = await prompt({
    title: stage.opens_at ? 'Перенести открытие' : 'Назначить дату открытия',
    message: `Этап «${stage.title}».`,
    label: 'Дата',
    type: 'date',
    value: stage.opens_at ? stage.opens_at.slice(0, 10) : '',
    confirmLabel: stage.opens_at ? 'Перенести' : 'Назначить',
  })

  if (date) {
    await run(() => api.moveStage(employeeId.value, stage.id, date))
  }
}

/**
 * Быстрый перенос встречи, не открывая редактор этапа: то же самое, что поле
 * «Когда» в форме, — и та же дорога на сервере (ScheduleMeeting).
 */
async function schedule(item: StageItem) {
  const at = await prompt({
    title: item.meta.scheduled_at ? 'Перенести встречу' : 'Назначить время встречи',
    message: `«${item.title}».`,
    label: 'Дата и время',
    type: 'datetime-local',
    value: toLocalInput(item.meta.scheduled_at),
    confirmLabel: item.meta.scheduled_at ? 'Перенести' : 'Назначить',
  })

  const scheduledAt = at === null ? null : fromLocalInput(at)

  if (scheduledAt && item.meta.id) {
    await run(() => api.scheduleMeeting(employeeId.value, item.meta.id!, { scheduled_at: scheduledAt }))
  }
}

function day(value: string | null): string {
  return value ? new Date(value).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' }) : '—'
}
</script>

<template>
  <section>
    <p v-if="error" class="alert alert--danger" role="alert">
      Сотрудник не найден.
    </p>

    <div v-else-if="pending" class="card">
      <div class="skeleton skeleton-line" />
    </div>

    <template v-else-if="journey">
      <header class="head">
        <NuxtLink to="/onboarding/manage/people" class="faint back">
          ← Сотрудники
        </NuxtLink>

        <div class="head__row">
          <h1 class="page-title">
            {{ journey.employee.name }}
          </h1>
          <span
            v-if="journey.stages.length"
            class="badge"
            :class="{
              'badge--success': journey.status === 'completed',
              'badge--warning': journey.status === 'paused',
            }"
          >
            {{ journey.status_label }}
          </span>
        </div>

        <p class="page-subtitle">
          <template v-if="!journey.stages.length">
            Маршрут пуст — соберите его руками или наложите шаблон.
          </template>
          <template v-else>
            <template v-if="journey.started_at">с {{ day(journey.started_at) }} · </template>
            <template v-if="journey.current_day">день {{ journey.current_day }}<template v-if="journey.total_days"> из {{ journey.total_days }}</template> · </template>
            пройдено {{ journey.progress.completed }} из {{ journey.progress.total }} ({{ journey.progress.percent }}%)
          </template>
        </p>

        <div v-if="journey.stages.length" class="head__actions">
          <button
            v-if="journey.status === 'active'"
            type="button"
            class="button-secondary"
            @click="run(() => api.pauseJourney(employeeId))"
          >
            Приостановить
          </button>
          <button
            v-if="journey.status === 'paused'"
            type="button"
            class="button-secondary"
            @click="run(() => api.resumeJourney(employeeId))"
          >
            Возобновить
          </button>
        </div>
      </header>

      <p v-if="actionError" class="alert alert--danger" role="alert">
        {{ actionError }}
      </p>

      <!-- Наложить шаблон: дописывает свои этапы в конец. -->
      <div class="overview-actions journey-toolbar">
        <button type="button" class="button-secondary" @click="applyOpen = true">Добавить этапы из шаблона</button>
        <button type="button" class="button-ghost" @click="rulesOpen = true">Настройки маршрута</button>
      </div>
      <AppSheet :open="applyOpen" title="Добавить этапы из шаблона" wide @close="!isApplying && (applyOpen = false)">
      <div class="editor-section">
        <p v-if="actionError" class="alert alert--danger" role="alert">{{ actionError }}</p>
        <p class="faint">Этапы выбранной программы добавятся в личный маршрут сотрудника.</p>

        <div v-if="hiddenMaterials.length" class="alert alert--warning" role="alert">
          <p class="hidden__lead">
            Сотруднику закрыты материалы этого шаблона — откройте доступ, иначе
            он упрётся в «не найдено»:
          </p>
          <ul class="hidden__list">
            <li v-for="(material, index) in hiddenMaterials" :key="index">
              «{{ material.stage_title }}»: {{ material.title }}
              <strong v-if="material.is_required">— обязательный, этап не закроется</strong>
            </li>
          </ul>
        </div>

        <div class="apply__row">
          <div class="field">
            <label class="field-label" for="a-template">Шаблон</label>
            <UiSelect id="a-template" v-model="templateSlug" :options="templateOptions" placeholder="Опубликованные шаблоны" />
          </div>

          <div class="field">
            <span class="field-label">Считать дни</span>
            <label v-for="option in anchorOptions" :key="option.value" class="choice">
              <input v-model="anchor" type="radio" :value="option.value">
              {{ option.label }}
            </label>
          </div>

          <div v-if="anchor === 'date'" class="field">
            <label class="field-label" for="a-date">Дата</label>
            <input id="a-date" v-model="anchorDate" type="date" class="input">
          </div>

          <button
            type="button"
            class="button-primary apply__go"
            :disabled="isApplying || !templateSlug"
            @click="applyTemplate"
          >
            {{ isApplying ? 'Накладываем…' : 'Добавить этапы' }}
          </button>
        </div>
      </div>
      </AppSheet>

      <div class="layout">
        <div class="layout__map">
          <OnboardingJourneyMap
            title="Маршрут адаптации"
            v-if="journey.stages.length"
            :stages="journey.stages"
            :selected-id="selectedStage?.id ?? null"
            @select="selectStage"
          />
          <p v-else class="faint">
            Этапов пока нет.
          </p>

          <button type="button" class="button-secondary add-stage" @click="stageDraft = blankStage()">
            + Этап вручную
          </button>
        </div>

        <div ref="detailElement" class="detail">
          <!-- Форма этапа: заведение и правка. -->


          <template v-if="selectedStage">
            <OnboardingStageCard :stage="selectedStage" :interactive="false" :load-review="async id => (await api.fetchEmployeeAttempt(employeeId, id)).data.review ?? null" />

            <!-- Рычаги ведущего при выбранном этапе. -->
            <div class="card levers">
              <h3 class="levers__title">
                Управление этапом
              </h3>

              <div class="levers__row">
                <button type="button" class="button-secondary" @click="editStage(selectedStage)">
                  Править этап
                </button>
                <button
                  v-if="selectedStage.status === 'locked' && selectedStage.opens_at"
                  type="button"
                  class="button-secondary"
                  @click="openNow(selectedStage)"
                >
                  Открыть досрочно
                </button>
                <button
                  v-if="!selectedStage.completed_at"
                  type="button"
                  class="button-secondary"
                  @click="move(selectedStage)"
                >
                  {{ selectedStage.opens_at ? 'Перенести открытие' : 'Назначить дату' }}
                </button>
                <button
                  v-if="selectedStage.status === 'quiz_failed'"
                  type="button"
                  class="button-primary"
                  @click="run(() => api.grantAttempt(employeeId, selectedStage!.id))"
                >
                  Дать ещё попытку
                </button>
                <button
                  v-if="!selectedStage.completed_at"
                  type="button"
                  class="button-ghost danger"
                  @click="removeStage(selectedStage)"
                >
                  Убрать этап
                </button>
              </div>

              <ul class="levers__list" role="list">
                <template v-for="item in selectedStage.items" :key="item.key">
                  <li v-if="item.kind === 'meeting' && !item.done" class="levers__item">
                    <span class="levers__what">
                      <OnboardingItemIcon kind="meeting" class="levers__icon" />
                      {{ item.title }}
                    </span>
                    <button type="button" class="button-ghost" @click="schedule(item)">
                      {{ item.meta.scheduled_at ? 'Перенести' : 'Назначить время' }}
                    </button>
                    <button
                      type="button"
                      class="button-secondary"
                      @click="run(() => api.confirmJourneyMeeting(employeeId, item.meta.id!))"
                    >
                      Подтвердить
                    </button>
                  </li>
                  <li v-else-if="item.kind === 'task' && !item.done" class="levers__item">
                    <span class="levers__what">
                      <OnboardingItemIcon kind="task" class="levers__icon" />
                      {{ item.title }}
                    </span>
                    <span v-if="item.meta.awaiting_confirmation" class="badge badge--warning">сотрудник отметил</span>
                    <button
                      type="button"
                      class="button-secondary"
                      @click="run(() => api.confirmJourneyTask(employeeId, item.meta.id!))"
                    >
                      Подтвердить
                    </button>
                  </li>
                </template>
              </ul>

              <!-- Тест этапа: у каждого сотрудника свой. -->
              <div class="levers__row">
                <button
                  type="button"
                  class="button-ghost"
                  @click="quizStageId = quizStageId === selectedStage.id ? null : selectedStage.id"
                >
                  {{ selectedStage.quiz ? 'Редактировать тест' : 'Добавить тест' }}
                </button>
              </div>


            </div>
          </template>
        </div>
      </div>

      <AppSheet :open="rulesOpen" title="Настройки личного маршрута" @close="!isSavingRules && (rulesOpen = false)">
      <form class="editor-form" @submit.prevent="saveRules">
        <p v-if="actionError" class="alert alert--danger" role="alert">{{ actionError }}</p>
        <label class="choice"><input v-model="routeRules.allow_ahead" type="checkbox">Разрешить переход вперёд</label>
        <label class="field"><span class="field-label">Напомнить перед встречей, минут</span><input v-model.number="routeRules.meeting_reminder_minutes" type="number" min="1" max="1440" class="input"></label>
        <OnboardingSettings v-model="routeRules.settings" />
        <button class="button-primary" type="submit" :disabled="isSavingRules">{{ isSavingRules ? 'Сохраняем…' : 'Сохранить правила' }}</button>
      </form>
      </AppSheet>
      <AppSheet :open="stageDraft !== null" :title="stageDraft?.id == null ? 'Новый этап' : 'Редактирование этапа'" wide @close="!isSavingStage && (stageDraft = null)">
          <form v-if="stageDraft" class="editor-form" @submit.prevent="saveStage" @invalid.capture="stageSection = 'main'">
            <OnboardingEditorTabs v-model="stageSection" :counts="{ materials: stageDraft.links.length, tasks: stageDraft.tasks.length, meetings: stageDraft.meetings.length }" />
            <p v-if="actionError" class="alert alert--danger" role="alert">{{ actionError }}</p>
            <p v-if="Object.keys(stageErrors).length" class="alert alert--danger" role="alert">{{ Object.values(stageErrors).flat().join(' ') }}</p>
            <div v-show="stageSection === 'main'" class="editor-section">
            <div class="field-pair">
              <div class="field">
                <label class="field-label" for="s-title">Название</label>
                <input id="s-title" v-model.trim="stageDraft.title" class="input" maxlength="255" required>
                <p v-if="stageErrors.title?.length" class="field-error">
                  {{ stageErrors.title[0] }}
                </p>
              </div>
              <div class="field">
                <label class="field-label" for="s-day">День маршрута <span class="field-optional">— пусто: без даты</span></label>
                <input id="s-day" v-model="stageDraft.day_offset" type="number" min="1" max="365" class="input input--short">
              </div>
            </div>

            <div class="field">
              <label class="field-label">Завершить до<input v-model="stageDraft.due_at" type="date" class="input"></label>
              <details class="editor-disclosure"><summary>Ответственные за этап · {{ stageDraft.responsible_ids.length }}</summary><OnboardingResponsibles v-model="stageDraft.responsible_ids" /></details>
              <label class="field-label" for="s-desc">Описание</label>
              <textarea id="s-desc" v-model.trim="stageDraft.description" class="input" rows="2" maxlength="2000" />
            </div>

            <!--
              Значки этапа. Правятся у этого человека: этап маршрута владеет
              своим содержимым, и поменять значок одному не значит поменять
              его шаблону и всем остальным.
            -->
            <details v-if="achievements.length" class="editor-disclosure"><summary>Достижения за этап и тест</summary>
            <div v-if="achievements.length" class="field-pair">
              <AchievementsSelect
                id="s-award"
                v-model="stageDraft.achievement_id"
                :achievements="achievements"
                label="Достижение за этап"
              />
              <AchievementsSelect
                id="s-quiz-award"
                v-model="stageDraft.quiz_achievement_id"
                :achievements="achievements"
                label="Достижение за тест этапа"
              />
            </div>            </details>

            </div>
            <div v-show="stageSection === 'materials'" class="editor-section">
            <div class="field">
              <label class="field-label" for="s-material">Материалы</label>
              <UiSelect id="s-material" v-model="materialToAdd" :options="materialOptions" placeholder="Добавить материал" searchable />

              <ul v-if="stageDraft.links.length" class="rows">
                <li v-for="(link, index) in stageDraft.links" :key="`${link.type}:${link.id}`" class="rows__item">
                  <span class="rows__name">{{ link.title }}</span>
                  <label class="rows__day">
                    <span class="rows__day-label">к дню</span>
                    <input v-model="link.day_offset" type="number" min="1" max="365" class="input input--day" placeholder="—">
                  </label>
                  <label class="rows__toggle">
                    <input v-model="link.is_required" type="checkbox">
                    обязателен
                  </label>
                  <AchievementsSelect
                    v-if="achievements.length"
                    v-model="link.achievement_id"
                    :achievements="achievements"
                    compact
                  />
                  <button type="button" class="rows__drop" aria-label="Убрать материал" @click="stageDraft.links.splice(index, 1)">
                    ✕
                  </button>
                </li>
              </ul>
            </div>

            </div>
            <div v-show="stageSection === 'meetings'" class="editor-section">
            <div class="field">
              <span class="field-label">Встречи</span>

              <div v-for="(meeting, index) in stageDraft.meetings" :key="index" class="card-sub">
                <header class="card-sub__head">
                  <span class="card-sub__title">Встреча {{ index + 1 }}</span>
                  <button type="button" class="rows__drop" aria-label="Убрать встречу" @click="stageDraft.meetings.splice(index, 1)">
                    ✕
                  </button>
                </header>

                <div class="field">
                  <label class="field-label" :for="`m-topic-${index}`">Тема</label>
                  <input :id="`m-topic-${index}`" v-model.trim="meeting.topic" class="input" placeholder="О чём встреча" maxlength="255">
                </div>

                <div class="field-pair">
                  <div class="field">
                    <span class="field-label">Формат</span>
                    <div class="segmented">
                      <label class="segmented__option" :class="{ 'segmented__option--on': meeting.format === 'offline' }">
                        <input v-model="meeting.format" type="radio" value="offline" :name="`fmt-${index}`">
                        Очно
                      </label>
                      <label class="segmented__option" :class="{ 'segmented__option--on': meeting.format === 'online' }">
                        <input v-model="meeting.format" type="radio" value="online" :name="`fmt-${index}`">
                        Онлайн
                      </label>
                    </div>
                  </div>

                  <div class="field">
                    <label class="field-label" :for="`m-place-${index}`">
                      {{ meeting.format === 'online' ? 'Ссылка' : 'Место' }}
                    </label>
                    <input
                      :id="`m-place-${index}`"
                      v-model.trim="meeting.place"
                      class="input"
                      :placeholder="meeting.format === 'online' ? 'https://…' : 'Торговый зал, кабинет'"
                      maxlength="255"
                    >
                  </div>
                </div>

                <div class="field-pair">
                  <div class="field">
                    <label class="field-label" :for="`m-when-${index}`">
                      Дата и время <span class="field-optional">— можно назначить позже</span>
                    </label>
                    <input :id="`m-when-${index}`" v-model="meeting.scheduled_at" type="datetime-local" class="input">
                  </div>

                  <OnboardingResponsibles v-model="meeting.participant_ids" label="Участники встречи" hint="Можно выбрать нескольких сотрудников. Стажёр уже участвует в своей встрече." />
                </div>

                <AchievementsSelect
                  v-if="achievements.length"
                  :id="`m-award-${index}`"
                  v-model="meeting.achievement_id"
                  :achievements="achievements"
                  label="Достижение за встречу"
                />
              </div>

              <button
                type="button"
                class="add-row"
                @click="stageDraft.meetings.push({ id: null, topic: '', format: 'offline', place: '', scheduled_at: '', participant_ids: [], with_user_id: null, with_name: '', achievement_id: null })"
              >
                + Добавить встречу
              </button>
            </div>

            </div>
            <div v-show="stageSection === 'tasks'" class="editor-section">
            <div class="field">
              <span class="field-label">Задания</span>

              <ul v-if="stageDraft.tasks.length" class="rows">
                <li v-for="(task, index) in stageDraft.tasks" :key="index" class="rows__item">
                  <input v-model.trim="task.text" class="input rows__input" placeholder="Что сделать" maxlength="1000">
                  <label class="rows__toggle">
                    <input v-model="task.confirm_by" type="checkbox" true-value="responsible" false-value="self">
                    подтверждает ответственный
                  </label>
                  <details v-if="task.confirm_by === 'responsible'" class="editor-disclosure task-responsibles">
                    <summary>Ответственные · {{ task.responsible_ids.length || 'по этапу' }}</summary>
                  <OnboardingResponsibles v-model="task.responsible_ids"
                    class="task-responsibles" label="Ответственные за задание"
                    hint="Подтвердить может любой выбранный сотрудник. Если список пуст — ответственные этапа. Наставник и администратор также могут подтвердить." />
                  </details>
                  <AchievementsSelect
                    v-if="achievements.length"
                    v-model="task.achievement_id"
                    :achievements="achievements"
                    compact
                  />
                  <button type="button" class="rows__drop" aria-label="Убрать задание" @click="stageDraft.tasks.splice(index, 1)">
                    ✕
                  </button>
                </li>
              </ul>

              <button
                type="button"
                class="add-row"
                @click="stageDraft.tasks.push({ id: null, text: '', responsible_ids: [], confirm_by: 'self', is_required: true, achievement_id: null })"
              >
                + Добавить задание
              </button>
            </div>

            </div>
            <div class="panel__actions editor-footer">
              <button type="submit" class="button-primary" :disabled="isSavingStage">
                {{ isSavingStage ? 'Сохраняем…' : 'Сохранить этап' }}
              </button>
              <button type="button" class="button-ghost" @click="stageDraft = null">
                Отмена
              </button>
            </div>
          </form>
      </AppSheet>
      <AppSheet :open="quizStageId !== null" title="Тест этапа" wide @close="!isSavingQuiz && (quizStageId = null)">
        <p v-if="actionError" class="alert alert--danger" role="alert">{{ actionError }}</p>
              <QuizBuilder
                v-if="selectedStage && quizStageId === selectedStage.id"
                :quiz="selectedStage.quiz ?? null"
                :default-max-attempts="2"
                :errors="quizErrors"
                :is-submitting="isSavingQuiz"
                note="Тест принадлежит маршруту этого сотрудника: правка не заденет остальных."
                @save="payload => saveQuiz(selectedStage!.id, payload)"
                @remove="removeQuiz(selectedStage!.id)"
              />
      </AppSheet>
      <OnboardingHistory :entries="journey.history" />
    </template>
  </section>
</template>

<style scoped>
.journey-toolbar { margin-bottom: 1rem; }
.editor-form, .editor-section { display: grid; gap: 1rem; min-width: 0; }
.editor-disclosure { padding: .85rem 1rem; border: 1px solid var(--color-border); border-radius: var(--radius-sm); }
.editor-disclosure > summary { cursor: pointer; font-weight: 600; font-size: .9rem; }
.editor-disclosure[open] > summary { margin-bottom: 1rem; }
.editor-footer { position: sticky; bottom: -1.4rem; background: var(--color-surface-raised); padding: 1rem 0; border-top: 1px solid var(--color-border); z-index: 2; }
.route-overview { padding: 1.25rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.route-overview__description { margin: 0 0 .6rem; max-width: 52rem; overflow-wrap: anywhere; }
.route-overview__meta { display: flex; gap: .5rem 1rem; flex-wrap: wrap; color: var(--color-text-muted); font-size: .82rem; }
.overview-actions, .stage-row-actions { display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; }
.stage-row-actions { padding: .6rem .85rem; border-top: 1px solid var(--color-border); }
.more-actions { position: relative; }
.more-actions > summary { cursor: pointer; padding: .5rem .75rem; font-size: .85rem; }
.more-actions > div { position: absolute; right: 0; z-index: 3; display: grid; min-width: 12rem; padding: .5rem; border: 1px solid var(--color-border); border-radius: var(--radius-sm); background: var(--color-surface-raised); box-shadow: 0 8px 24px rgb(0 0 0 / 12%); }
@media (max-width: 600px) { .stage-row-actions { gap: .25rem; } .stage-row-actions button { font-size: .78rem; } .editor-footer { bottom: -1rem; } }

.task-responsibles { flex: 1 0 100%; min-width: 0; }
.head {
  margin-bottom: 1.25rem;
}

.back {
  display: inline-block;
  margin-bottom: 0.75rem;
  text-decoration: none;
}

.head__row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.head__actions {
  display: flex;
  gap: 0.6rem;
  margin-top: 0.75rem;
  flex-wrap: wrap;
}

.apply {
  display: flex;
  flex-direction: column;
  gap: 0.9rem;
  padding: 1.1rem 1.25rem;
  margin-bottom: 1.25rem;
}

.apply__title {
  margin: 0;
  font-size: 1rem;
}

/* Подписи колонок на одной линии: выравниваем по верху, кнопку опускаем сама. */
.apply__row {
  display: flex;
  align-items: flex-start;
  gap: 1.75rem;
  flex-wrap: wrap;
}

.apply__row .field {
  min-width: 13rem;
}

.apply__row .choice {
  color: var(--color-text-muted);
}

.apply__go {
  margin-left: auto;
  align-self: flex-end;
}

.alert--warning {
  background: var(--color-warning-soft);
  color: var(--color-warning);
}

.hidden__lead {
  margin: 0 0 0.4rem;
}

.hidden__list {
  margin: 0;
  padding-left: 1.1rem;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

/* Одна карта для сотрудника и администратора; выбранный этап рядом. */
.layout {
  display: grid;
  grid-template-columns: minmax(0, 1.15fr) minmax(24rem, 1fr);
  gap: 1.25rem;
  align-items: start;
  margin-bottom: 1.25rem;
}

.layout__map {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.add-stage {
  align-self: flex-start;
}

.detail {
  position: sticky;
  top: 100px;
  max-height: calc(100dvh - 120px);
  overflow-y: auto;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.panel {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1.4rem 1.5rem;
}

.panel__title {
  margin: 0;
  font-size: 1.1rem;
}

.panel__actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  flex-wrap: wrap;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.field-pair {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
}

.field-optional {
  color: var(--color-text-faint);
  font-weight: 400;
}

.input--short {
  max-width: 10rem;
}

.choice {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  font-size: 0.9rem;
}

/* Строка содержимого этапа: название тянется, «к дню» и флажок — по месту. */
.rows {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  margin: 0.5rem 0 0;
  padding: 0;
  list-style: none;
}

.rows__item {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.5rem 0.75rem;
  background: var(--control-surface);
  border-radius: var(--radius-sm);
  flex-wrap: wrap;
}

.rows__name {
  flex: 1;
  min-width: 10rem;
}

.rows__input {
  flex: 1;
  min-width: 12rem;
}

.rows__day {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.85rem;
  color: var(--color-text-muted);
}

.rows__day-label {
  white-space: nowrap;
}

.input--day {
  width: 4.25rem;
  text-align: center;
  padding-inline: 0.5rem;
}

.rows__toggle {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.85rem;
  color: var(--color-text-muted);
  white-space: nowrap;
}

.rows__drop {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.75rem;
  height: 1.75rem;
  flex: none;
  padding: 0;
  border: 0;
  border-radius: var(--radius-pill);
  background: none;
  color: var(--color-text-faint);
  font: inherit;
  font-size: 0.8rem;
  cursor: pointer;
}

.rows__drop:hover {
  background: var(--color-surface-sunken);
  color: var(--color-danger);
}

.card-sub {
  display: flex;
  flex-direction: column;
  gap: 0.9rem;
  padding: 1rem 1.1rem;
  margin-top: 0.5rem;
  background: var(--control-surface);
  border-radius: var(--radius);
}

.card-sub__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
}

.card-sub__title {
  font-size: 0.78rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-text-faint);
}

.segmented {
  display: inline-flex;
  gap: 0.25rem;
  padding: 0.25rem;
  background: var(--color-surface);
  border-radius: var(--radius-pill);
}

.segmented__option {
  padding: 0.35rem 0.9rem;
  border-radius: var(--radius-pill);
  font-size: 0.9rem;
  color: var(--color-text-muted);
  cursor: pointer;
}

.segmented__option input {
  position: absolute;
  opacity: 0;
  width: 0;
  height: 0;
}

.segmented__option--on {
  background: var(--color-accent);
  color: var(--color-accent-text);
}

.search {
  position: relative;
}

.search__note {
  margin: 0.35rem 0 0;
  font-size: 0.82rem;
  color: var(--color-text-faint);
}

.add-row {
  width: 100%;
  margin-top: 0.5rem;
  padding: 0.6rem;
  border: 1px dashed var(--color-border-strong);
  border-radius: var(--radius-sm);
  background: none;
  color: var(--color-text-muted);
  font: inherit;
  font-size: 0.9rem;
  cursor: pointer;
}

.add-row:hover {
  border-color: var(--color-accent);
  color: var(--color-accent);
}

.levers {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 1.1rem 1.25rem;
}

.levers__title {
  margin: 0;
  font-size: 0.95rem;
}

.levers__row {
  display: flex;
  gap: 0.6rem;
  flex-wrap: wrap;
}

.levers__list {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.levers__item {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  flex-wrap: wrap;
}

.levers__what {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex: 1;
  min-width: 10rem;
}

.levers__icon {
  color: var(--color-text-faint);
}

.chip {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.25rem 0.6rem;
  border: 1px solid var(--color-border);
  border-radius: 999px;
  font-size: 0.85rem;
}

.chip--person {
  background: var(--color-surface-raised);
}

.chip__drop {
  border: 0;
  background: none;
  cursor: pointer;
  font-size: 1rem;
  line-height: 1;
  color: var(--color-text-faint);
}

/*
 * Подсказка «с кем встреча». Глобальное правило для button красит текст
 * белым — без color/font имя найденного человека оказывалось белым по
 * белому и мелким шрифтом браузера, то есть пустой рамкой.
 */
.picker {
  position: absolute;
  z-index: 20;
  left: 0;
  right: 0;
  margin: 0.35rem 0 0;
  padding: 0.25rem;
  list-style: none;
  background: var(--color-surface-raised);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  box-shadow: var(--shadow-md);
  max-height: 14rem;
  overflow-y: auto;
}

.picker__option {
  display: block;
  width: 100%;
  padding: 0.5rem 0.65rem;
  text-align: left;
  background: none;
  border: 0;
  border-radius: var(--radius-sm);
  color: inherit;
  font: inherit;
  cursor: pointer;
}

.picker__option:hover {
  background: var(--color-surface);
}

.route-rules {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 1.1rem 1.25rem;
}

.route-rules__row {
  display: flex;
  gap: 0.6rem;
  flex-wrap: wrap;
}

@media (max-width: 959px) {
  .detail { position: static; max-height: none; overflow: visible; scroll-margin-top: 90px; }
  .layout {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 34rem) {
  .field-pair {
    grid-template-columns: 1fr;
  }
}
</style>
