<script setup lang="ts">
import type { FetchError } from 'ofetch'
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'
import type { QuizPayload } from '~/types/lms'
import type { Achievement } from '~/types/achievements'
import type { OnboardingTemplate, PlannableRow, StagePayload, TemplateStage } from '~/types/onboarding'

/**
 * Редактор шаблона: шапка с правилами, этапы по дням и их содержимое.
 *
 * Шаблон — трафарет: его накладывают на личный маршрут сотрудника, и с этой
 * минуты копия живёт сама. Правка шаблона уже наложенное не достаёт.
 *
 * Этап сохраняется целиком, как тест в QuizBuilder: экран присылает всё, и
 * «сохранить» значит «пусть будет вот так». Тест этапа ведёт QuizBuilder —
 * редактор тестов в приложении один.
 */
definePageMeta({ middleware: 'auth', permission: 'onboarding.manage' })

const route = useRoute()
const router = useRouter()
const slug = computed(() => String(route.params.slug))

const api = useOnboardingApi()
const { confirm } = useAppDialog()

const { data, pending, error, refresh } = await useAsyncData(
  `onboarding.template.${slug.value}`,
  () => api.fetchTemplate(slug.value),
)

const template = computed(() => data.value?.data ?? null)
const { data: jobTitles } = await useAsyncData('onboarding.job-titles', () => api.fetchJobTitles())

/**
 * Каталог значков — один на весь редактор: его спрашивают строки этапа, а
 * спрашивать по разу на материал значило бы десяток запросов на этап.
 *
 * Молча пустой при неудаче: без каталога этап собирается как раньше, просто
 * без значков, — и ронять ради них весь редактор незачем.
 */
const { data: achievementsData } = await useAsyncData(
  'onboarding.achievements',
  () => useAchievementsApi().fetchAchievements(),
  { default: () => ({ data: [] }) },
)

const achievements = computed<Achievement[]>(() => achievementsData.value?.data ?? [])

useHead({ title: () => template.value?.title ?? 'Шаблон' })

const generalError = ref<string | null>(null)
const settingsOpen = ref(false)
const quizStageId = ref<number | null>(null)
const quizStage = computed(() => template.value?.stages?.find(stage => stage.id === quizStageId.value) ?? null)
const stageSection = ref('main')

/* ---------- Шапка ---------- */

const form = reactive({
  job_title: '', store: '', settings: onboardingSettings(),
  title: '',
  description: '',
  duration_days: 7,
  day_mode: 'calendar' as string,
  allow_ahead: false,
  meeting_reminder_minutes: '' as string | number,
})

function resetHeader(value: OnboardingTemplate | null) {
  if (!value) {
    return
  }

  form.job_title = value.job_title ?? ''
  form.store = value.store ?? ''
  form.settings = JSON.parse(JSON.stringify(value.settings))
  form.title = value.title
  form.description = value.description ?? ''
  form.duration_days = value.duration_days
  form.day_mode = value.day_mode
  form.allow_ahead = value.allow_ahead
  form.meeting_reminder_minutes = value.meeting_reminder_minutes ?? ''
}
watch(template, resetHeader, { immediate: true })
watch(settingsOpen, open => {
  if (open) {
    resetHeader(template.value)
    headerErrors.value = {}
    generalError.value = null
  }
})

const headerErrors = ref<ValidationErrors>({})
const isSavingHeader = ref(false)

async function saveHeader() {
  isSavingHeader.value = true
  headerErrors.value = {}
  generalError.value = null

  try {
    await api.updateTemplate(slug.value, {
      job_title: form.job_title || null, store: form.store || null, settings: form.settings,
      title: form.title,
      description: form.description || null,
      duration_days: Number(form.duration_days),
      day_mode: form.day_mode,
      allow_ahead: form.allow_ahead,
      meeting_reminder_minutes: form.meeting_reminder_minutes === '' ? null : Number(form.meeting_reminder_minutes),
    })
    settingsOpen.value = false
    await refresh()
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      headerErrors.value = caught.errors
    }
    else {
      generalError.value = 'Не удалось сохранить.'
    }
  }
  finally {
    isSavingHeader.value = false
  }
}

async function changeStatus(status: 'published' | 'archived' | 'draft') {
  generalError.value = null

  try {
    await api.changeTemplateStatus(slug.value, status)
    await refresh()
  }
  catch (caught) {
    generalError.value = (caught as FetchError<{ message?: string }>).data?.message
      ?? 'Не удалось сменить статус.'
  }
}

async function destroy() {
  if (!await confirm({
    title: 'Удалить шаблон?',
    message: 'Маршруты, собранные по нему, останутся: они живут сами.',
    confirmLabel: 'Удалить',
    danger: true,
  })) {
    return
  }

  try {
    await api.deleteTemplate(slug.value)
    await router.push('/onboarding/manage')
  }
  catch (caught) {
    generalError.value = (caught as FetchError<{ message?: string }>).data?.message
      ?? 'Не удалось удалить.'
  }
}

/* ---------- Каталог материалов ---------- */

const { data: materialsData } = await useAsyncData('onboarding.materials', () => api.fetchMaterials())

const catalogue = computed<PlannableRow[]>(() => materialsData.value?.data ?? [])

const kindLabels: Record<string, string> = { course: 'Курс', document: 'Документ', handbook: 'Справочник' }

const materialOptions = computed(() => catalogue.value.map(row => ({
  value: `${row.kind}:${row.id}`,
  label: `${kindLabels[row.kind] ?? row.kind}: ${row.title}`,
})))

/* ---------- Этап: черновик формы ---------- */

interface StageDraft {
  is_final_exam: boolean
  responsible_ids: number[]
  id: number | null
  title: string
  description: string
  day_offset: number
  /** Значок за весь этап и значок за его тест. */
  achievement_id: number | null
  quiz_achievement_id: number | null
  links: { type: string, id: number, title: string, is_required: boolean, day_offset: string | number, achievement_id: number | null }[]
  meetings: { id: number | null, topic: string, format: string, place: string, with_user_id: number | null, with_name: string, with_role: string | null, participant_ids: number[], scheduled_time: string, achievement_id: number | null }[]
  tasks: { id: number | null, text: string, responsible_ids: number[], confirm_by: string, is_required: boolean, achievement_id: number | null }[]
}

const stageDraft = ref<StageDraft | null>(null)
const stageErrors = ref<ValidationErrors>({})
const isSavingStage = ref(false)

/** Раскрытый этап: тест и кнопки живут внутри него. */
const openStageId = ref<number | null>(null)

watch(stageDraft, () => { stageSection.value = 'main' })

function blankStage(): StageDraft {
  const nextDay = Math.max(0, ...(template.value?.stages ?? []).map(stage => stage.day_offset)) + 1

  return {
    is_final_exam: false, responsible_ids: [],
    id: null,
    title: '',
    description: '',
    day_offset: nextDay,
    achievement_id: null,
    quiz_achievement_id: null,
    links: [],
    meetings: [],
    tasks: [],
  }
}

function editStage(stage: TemplateStage) {
  stageDraft.value = {
    is_final_exam: stage.is_final_exam, responsible_ids: [...stage.responsible_ids],
    id: stage.id,
    title: stage.title,
    description: stage.description ?? '',
    day_offset: stage.day_offset,
    achievement_id: stage.achievement_id,
    quiz_achievement_id: stage.quiz_achievement_id,
    links: stage.links.map(link => ({
      type: link.type,
      id: link.material_id,
      title: link.title,
      is_required: link.is_required,
      day_offset: link.day_offset ?? '',
      achievement_id: link.achievement_id,
    })),
    meetings: stage.meetings.map(meeting => ({
      id: meeting.id,
      topic: meeting.topic,
      format: meeting.format,
      place: meeting.place ?? '',
      participant_ids: meeting.participant_ids ?? (meeting.with_user_id ? [meeting.with_user_id] : []),
      scheduled_time: meeting.scheduled_time ?? '',
      with_user_id: meeting.with_user_id,
      with_name: meeting.with_name ?? '',
      with_role: meeting.with_role,
      achievement_id: meeting.achievement_id,
    })),
    tasks: stage.tasks.map(task => ({
      id: task.id,
      text: task.text,
      confirm_by: task.confirm_by,
      responsible_ids: task.confirm_by === 'responsible' ? [...task.responsible_ids] : [],
      is_required: task.is_required,
      achievement_id: task.achievement_id,
    })),
  }
}

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
  generalError.value = null

  const payload: StagePayload = {
    id: stageDraft.value.id,
    is_final_exam: stageDraft.value.is_final_exam, responsible_ids: stageDraft.value.responsible_ids,
    title: stageDraft.value.title,
    description: stageDraft.value.description || null,
    day_offset: Number(stageDraft.value.day_offset),
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
      participant_ids: meeting.participant_ids,
      scheduled_time: meeting.scheduled_time || null,
      with_user_id: meeting.with_role ? null : meeting.participant_ids[0] ?? null,
      with_role: meeting.with_role,
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
    await api.saveStage(slug.value, payload)
    stageDraft.value = null
    await refresh()
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      stageErrors.value = caught.errors
      const firstError = Object.keys(caught.errors)[0] ?? ''
      stageSection.value = firstError.startsWith('links') ? 'materials' : firstError.startsWith('tasks') ? 'tasks' : firstError.startsWith('meetings') ? 'meetings' : 'main'
    }
    else {
      generalError.value = 'Не удалось сохранить этап.'
    }
  }
  finally {
    isSavingStage.value = false
  }
}

async function removeStage(stageId: number) {
  if (!await confirm({
    title: 'Убрать этап?',
    message: 'Новые назначения его не получат; идущие дочитают своё.',
    confirmLabel: 'Убрать',
    danger: true,
  })) {
    return
  }

  await api.deleteStage(slug.value, stageId)
  await refresh()
}

/* ---------- Тест этапа ---------- */

const quizErrors = ref<ValidationErrors>({})
const isSavingQuiz = ref(false)

async function saveQuiz(stageId: number, payload: QuizPayload) {
  isSavingQuiz.value = true
  quizErrors.value = {}

  try {
    await api.saveStageQuiz(slug.value, stageId, payload)
    quizStageId.value = null
    await refresh()
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      quizErrors.value = caught.errors
    }
    else {
      generalError.value = 'Не удалось сохранить тест.'
    }
  }
  finally {
    isSavingQuiz.value = false
  }
}

async function removeQuiz(stageId: number) {
  if (!await confirm({
    title: 'Удалить тест этапа?',
    message: 'Вместе с ним уйдут попытки и их разбор.',
    confirmLabel: 'Удалить',
    danger: true,
  })) {
    return
  }

  await api.deleteStageQuiz(slug.value, stageId)
  quizStageId.value = null
  await refresh()
}
</script>

<template>
  <section>
    <p v-if="error" class="alert alert--danger" role="alert">
      Шаблон не найден.
    </p>

    <div v-else-if="pending" class="card">
      <div class="skeleton skeleton-line" />
    </div>

    <template v-else-if="template">
      <header class="head">
        <NuxtLink to="/onboarding/manage" class="faint back">
          ← Шаблоны
        </NuxtLink>
        <div class="head__row">
          <h1 class="page-title">
            {{ template.title }}
          </h1>
          <span
            class="badge"
            :class="{ 'badge--success': template.status === 'published', 'badge--warning': template.status === 'draft' }"
          >
            {{ template.status_label }}
          </span>
        </div>
      </header>

      <p v-if="generalError" class="alert alert--danger" role="alert">
        {{ generalError }}
      </p>

      <div class="stack">
        <!-- Шапка: правила шаблона. -->
        <div class="card route-overview">
          <div>
            <p v-if="template.description" class="route-overview__description">{{ template.description }}</p>
            <div class="route-overview__meta">
              <span>Версия {{ template.version }}</span><span>{{ template.duration_days }} {{ template.day_mode === 'working' ? 'рабочих дней' : 'календарных дней' }}</span>
              <span v-if="template.job_title">{{ template.job_title }}</span><span v-if="template.store">{{ template.store }}</span>
            </div>
          </div>
          <div class="overview-actions">
            <button type="button" class="button-secondary" @click="settingsOpen = true">Настройки маршрута</button>
            <button v-if="template.status !== 'published'" type="button" class="button-primary" @click="changeStatus('published')">Опубликовать</button>
            <details class="more-actions"><summary>Ещё</summary><div>
              <button v-if="template.status === 'published'" type="button" class="button-ghost" @click="changeStatus('archived')">В архив</button>
              <button type="button" class="button-ghost danger" @click="destroy">Удалить шаблон</button>
            </div></details>
          </div>
        </div>
        <AppSheet :open="settingsOpen" title="Настройки маршрута" wide @close="!isSavingHeader && (settingsOpen = false)">
        <form class="editor-form" @submit.prevent="saveHeader">
          <p v-if="Object.keys(headerErrors).length" class="alert alert--danger" role="alert">{{ Object.values(headerErrors).flat().join(' ') }}</p>
          <p v-if="generalError" class="alert alert--danger" role="alert">{{ generalError }}</p>

          <div class="field">
            <label class="field-label" for="t-title">Название</label>
            <input id="t-title" v-model.trim="form.title" class="input" maxlength="255" required>
            <p v-if="headerErrors.title?.length" class="field-error">
              {{ headerErrors.title[0] }}
            </p>
          </div>

          <div class="field">
            <label class="field-label" for="t-desc">Описание <span class="field-optional">— видно сотруднику</span></label>
            <textarea id="t-desc" v-model.trim="form.description" class="input" rows="2" maxlength="2000" />
          </div>

          <div class="field">
            <label class="field-label" for="template-job-title">Должность для автоназначения <span class="field-optional">— необязательно</span></label>
            <input id="template-job-title" v-model="form.job_title" class="input" maxlength="255" list="onboarding-job-titles" placeholder="Выберите должность или введите новую" aria-describedby="template-job-title-help">
            <datalist id="onboarding-job-titles"><option v-for="title in jobTitles?.data ?? []" :key="title" :value="title" /></datalist>
            <p id="template-job-title-help" class="faint">При создании сотрудника с этой должностью опубликованный шаблон назначится автоматически, если у сотрудника ещё нет этапов. Пустое поле — только ручное назначение. Если подходят несколько шаблонов, назначится самый новый опубликованный.</p>
            <p v-if="headerErrors.job_title?.length" class="field__error">{{ headerErrors.job_title[0] }}</p>
          </div>

          <details class="editor-disclosure"><summary>Расписание и правила</summary><div class="editor-section">
          <p class="faint">Версия {{ template.version }}<template v-if="template.parent_id"> · связана с предыдущей программой</template></p>
          <div class="field-pair">
            <label class="field"><span class="field-label">Магазин</span><input v-model="form.store" class="input" maxlength="255"></label>
          </div>

          <div class="field-pair">
            <div class="field">
              <label class="field-label" for="t-days">Длительность, дней</label>
              <input id="t-days" v-model="form.duration_days" type="number" min="1" max="60" class="input input--short">
            </div>
            <div class="field">
              <span class="field-label">Счёт дней</span>
              <label class="choice">
                <input v-model="form.day_mode" type="radio" value="calendar">
                Календарные
              </label>
              <label class="choice">
                <input v-model="form.day_mode" type="radio" value="working">
                Рабочие — без сб и вс
              </label>
            </div>
          </div>

          <label class="choice">
            <input v-model="form.allow_ahead" type="checkbox">
            Разрешить идти дальше при незавершённом предыдущем этапе
          </label>

          <div class="field">
            <label class="field-label" for="t-remind">Напоминать о встрече за N минут <span class="field-optional">— пусто, если не напоминать</span></label>
            <input id="t-remind" v-model="form.meeting_reminder_minutes" type="number" min="1" max="1440" class="input input--short">
          </div>

          </div></details>
          <OnboardingSettings v-model="form.settings" />
          <div class="panel__actions">
            <button type="submit" class="button-primary" :disabled="isSavingHeader">
              {{ isSavingHeader ? 'Сохраняем…' : 'Сохранить' }}
            </button>
            <button type="button" class="button-secondary" @click="settingsOpen = false">Отмена</button>
          </div>
        </form>
        </AppSheet>

        <!-- Этапы. -->
        <div class="card panel">
          <div class="panel__head">
            <h2 class="panel__title">
              Этапы
            </h2>
            <button type="button" class="button-secondary" @click="stageDraft = blankStage()">
              Добавить этап
            </button>
          </div>

          <p v-if="!template.stages?.length && !stageDraft" class="faint">
            Пока пусто. Программа складывается из этапов: день, материалы, встречи, задания и тест.
          </p>

          <ol class="stages" role="list">
            <li v-for="stage in template.stages" :key="stage.id" class="stages__row">
              <button
                type="button"
                class="stages__header" :aria-expanded="openStageId === stage.id"
                @click="openStageId = openStageId === stage.id ? null : stage.id"
              >
                <span class="stages__day">День {{ stage.day_offset }}</span>
                <span class="stages__title">{{ stage.title }}</span>
                <span class="faint">
                  {{ stage.links.length }} мат. · {{ stage.meetings.length }} встр. · {{ stage.tasks.length }} зад.
                  <template v-if="stage.quiz"> · тест</template>
                </span>
              </button>

              <div class="stage-row-actions">
                <button type="button" class="button-secondary" @click="editStage(stage)">Редактировать этап</button>
                <button type="button" class="button-ghost" @click="quizErrors = {}; quizStageId = stage.id">{{ stage.quiz ? 'Редактировать тест' : 'Добавить тест' }}</button>
                <button type="button" class="button-ghost danger" @click="removeStage(stage.id)">Удалить</button>
              </div>
              <div v-if="openStageId === stage.id" class="stages__body">
                <p v-if="stage.description" class="faint">
                  {{ stage.description }}
                </p>

                <ul v-if="stage.links.length" class="plain">
                  <li v-for="link in stage.links" :key="link.id" class="plain__row">
                    <OnboardingItemIcon :kind="link.type" class="plain__icon" />
                    {{ link.title }}
                    <span v-if="link.day_offset" class="faint">— к дню {{ link.day_offset }}</span>
                    <span v-if="!link.is_required" class="faint">— по желанию</span>
                  </li>
                </ul>
                <ul v-if="stage.meetings.length" class="plain">
                  <li v-for="meeting in stage.meetings" :key="meeting.id" class="plain__row">
                    <OnboardingItemIcon kind="meeting" class="plain__icon" />
                    {{ meeting.topic }} — {{ [meeting.with_role === 'mentor' ? 'наставник сотрудника' : '', ...(meeting.participants ?? []).map(person => person.name)].filter(Boolean).join(', ') || meeting.with_name || 'не назначен' }}<span v-if="meeting.scheduled_time"> · {{ meeting.scheduled_time }}</span>
                  </li>
                </ul>
                <ul v-if="stage.tasks.length" class="plain">
                  <li v-for="task in stage.tasks" :key="task.id" class="plain__row">
                    <OnboardingItemIcon kind="task" class="plain__icon" />
                    {{ task.text }} <span class="faint">({{ task.confirm_by === 'self' ? 'отмечает сам' : 'подтверждает ответственный' }})</span>
                  </li>
                </ul>


              </div>
            </li>
          </ol>

          <!-- Форма этапа: добавление и правка. -->

        </div>
      </div>
      <AppSheet :open="stageDraft !== null" :title="stageDraft?.id == null ? 'Новый этап' : 'Редактирование этапа'" wide @close="!isSavingStage && (stageDraft = null)">
          <form v-if="stageDraft" class="editor-form" @submit.prevent="saveStage" @invalid.capture="stageSection = 'main'">
            <OnboardingEditorTabs v-model="stageSection" :counts="{ materials: stageDraft.links.length, tasks: stageDraft.tasks.length, meetings: stageDraft.meetings.length }" />
            <p v-if="generalError" class="alert alert--danger" role="alert">{{ generalError }}</p>
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
                <label class="field-label" for="s-day">День маршрута</label>
                <input id="s-day" v-model="stageDraft.day_offset" type="number" min="1" max="60" class="input input--short" required>
              </div>
            </div>

            <div class="field">
              <details class="editor-disclosure"><summary>Ответственные за этап · {{ stageDraft.responsible_ids.length }}</summary><OnboardingResponsibles v-model="stageDraft.responsible_ids" /></details>
              <label class="field-label" for="s-desc">Описание</label>
              <textarea id="s-desc" v-model.trim="stageDraft.description" class="input" rows="2" maxlength="2000" />
            </div>

            <!--
              Значки этапа. Два, а не один: закрытый этап и сданный его тест —
              разные поводы, и за них дают разное. Сотрудник получит значок
              сам, когда шаг закроется.
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
            </div>

            </details>
            </div>
            <div v-show="stageSection === 'materials'" class="editor-section">
            <!-- Материалы. -->
            <div class="field">
              <label class="field-label" for="s-material">Материалы базы знаний</label>
              <UiSelect id="s-material" v-model="materialToAdd" :options="materialOptions" placeholder="Добавить материал" searchable />

              <ul v-if="stageDraft.links.length" class="rows">
                <li v-for="(link, index) in stageDraft.links" :key="`${link.type}:${link.id}`" class="rows__item">
                  <span class="rows__name">{{ link.title }}</span>

                  <!-- День материала: срок на виду, открытия он не решает. -->
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
            <!-- Встречи. -->
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
                      :placeholder="meeting.format === 'online' ? 'https://…' : 'Торговый зал, кабинет' "
                      maxlength="255"
                    >
                  </div>
                </div>

                <div class="field">
                  <label class="field-label" :for="`meeting-time-${index}`">Время в день этапа <span class="field-optional">— необязательно</span></label>
                  <input :id="`meeting-time-${index}`" v-model="meeting.scheduled_time" class="input" type="time">
                  <p class="faint">Дата будет взята из календаря этапа. Часовой пояс маршрута — Москва.</p>
                </div>
                <OnboardingResponsibles v-model="meeting.participant_ids" label="Участники встречи" hint="Можно выбрать нескольких сотрудников. Стажёр уже участвует в своей встрече." />
                <label class="rows__toggle"><input v-model="meeting.with_role" type="checkbox" true-value="mentor" :false-value="null">Также добавить наставника сотрудника — подставится при назначении маршрута</label>
                <p v-if="stageErrors[`meetings.${index}.participant_ids`]?.length" class="field-error">{{ stageErrors[`meetings.${index}.participant_ids`]?.[0] }}</p>
                <p v-if="stageErrors[`meetings.${index}.with_user_id`]?.length" class="field-error">{{ stageErrors[`meetings.${index}.with_user_id`]?.[0] }}</p>

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
                @click="stageDraft.meetings.push({ id: null, topic: '', format: 'offline', place: '', participant_ids: [], scheduled_time: '', with_user_id: null, with_name: '', with_role: null, achievement_id: null })"
              >
                + Добавить встречу
              </button>
            </div>

            </div>
            <div v-show="stageSection === 'tasks'" class="editor-section">
            <!-- Задания. -->
            <div class="field">
              <span class="field-label">Задания</span>

              <ul v-if="stageDraft.tasks.length" class="rows">
                <li v-for="(task, index) in stageDraft.tasks" :key="index" class="rows__item rows__item--task">
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
      <AppSheet :open="quizStageId !== null" :title="`Тест · ${quizStage?.title ?? ''}`" wide @close="!isSavingQuiz && (quizStageId = null)">
        <p v-if="generalError" class="alert alert--danger" role="alert">{{ generalError }}</p>
        <QuizBuilder v-if="quizStage" :key="quizStage.id" :quiz="quizStage.quiz" :default-max-attempts="2" :errors="quizErrors" :is-submitting="isSavingQuiz"
          note="Настройте вопросы, проходной балл и количество попыток." @save="payload => saveQuiz(quizStage!.id, payload)" @remove="removeQuiz(quizStage!.id)" />
      </AppSheet>
      <OnboardingHistory :entries="template.history ?? []" />
    </template>
  </section>
</template>

<style scoped>
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
  margin-bottom: 1.5rem;
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

.stack {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.panel {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1.4rem 1.5rem;
}

.panel__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
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

.stages {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.stages__row {
  border: 1px solid var(--color-border);
  border-radius: 0.75rem;
  overflow: hidden;
}

.stages__header {
  display: flex;
  align-items: baseline;
  gap: 0.75rem;
  width: 100%;
  padding: 0.75rem 1rem;
  background: none;
  border: 0;
  text-align: left;
  cursor: pointer;
  flex-wrap: wrap;
}

.stages__day {
  flex: none;
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--color-text-faint);
}

.stages__title {
  font-weight: 600;
}

.stages__body {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 0 1rem 1rem;
}

/* Состав этапа в развёрнутом виде: строка со значком вида требования. */
.plain {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  margin: 0.4rem 0 0;
  padding: 0;
  list-style: none;
}

.plain__row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.plain__icon {
  color: var(--color-text-faint);
}

.stages__actions {
  display: flex;
  gap: 0.75rem;
}

/* Строки списка: материал или задание — имя слева, настройки справа. */
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

/* Крестик строки: кнопка, а не символ в воздухе — у неё есть зона нажатия. */
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

/* Вложенная карточка: встреча внутри формы этапа. */
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

/* Переключатель из двух: очно или онлайн. Радиокнопки спрятаны, но живы —
   клавиатура и чтение с экрана работают как прежде. */
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

.segmented__option:focus-within {
  outline: 2px solid var(--color-accent);
  outline-offset: 2px;
}

/* Поле поиска с подсказкой под ним. */
.search {
  position: relative;
}

.search__note {
  margin: 0.35rem 0 0;
  font-size: 0.82rem;
  color: var(--color-text-faint);
}

.chip--person {
  background: var(--color-surface-raised);
}

/* Кнопка «добавить ещё»: пунктир во всю ширину — место, куда дописывают. */
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

.stage-form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding-top: 1rem;
  border-top: 1px solid var(--color-border);
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

.chip__drop {
  border: 0;
  background: none;
  cursor: pointer;
  font-size: 1rem;
  line-height: 1;
  color: var(--color-text-faint);
}

/*
 * Подсказка поиска.
 *
 * `color: inherit` здесь не украшение, а лекарство: глобальный стиль кнопок
 * красит текст белым, и найденное имя рисовалось белым по белому — список был
 * на месте, но выглядел пустой полосой.
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

.picker__option:hover,
.picker__option:focus-visible {
  background: var(--color-surface);
}

@media (max-width: 34rem) {
  .field-pair {
    grid-template-columns: 1fr;
  }
}
</style>
