<script setup lang="ts">
import type { FetchError } from 'ofetch'
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'
import type { QuizPayload } from '~/types/lms'
import type { Department, Group } from '~/types/structure'
import type { ExamStatus } from '~/types/exams'

/**
 * Редактор экзамена: шапка, вопросы, назначенные, результаты.
 *
 * Вопросы ведёт QuizBuilder — тот же, что у теста урока: планку здесь ставит
 * автор, поэтому fixedPassingScore не передаётся. Назначения сохраняются
 * списком целиком: убранный из списка освобождается от обязанности, его
 * сданные работы остаются в результатах.
 */
definePageMeta({ middleware: 'auth', permission: 'exams.manage' })

const route = useRoute()
const router = useRouter()
const slug = computed(() => String(route.params.slug))

const api = useExamsApi()

const { data, pending, error, refresh } = await useAsyncData(
  `exams.manage.${slug.value}`,
  () => api.fetchManaged(slug.value),
)

const exam = computed(() => data.value?.data ?? null)

useHead({ title: () => exam.value?.title ?? 'Экзамен' })

/* ---------- Шапка ---------- */

const form = reactive({
  title: '',
  description: '',
  opens_at: '',
  closes_at: '',
  time_limit_minutes: '' as string | number,
  shuffle_questions: false,
})

watch(exam, (value) => {
  if (!value) {
    return
  }

  form.title = value.title
  form.description = value.description ?? ''
  form.opens_at = toLocalInput(value.opens_at)
  form.closes_at = toLocalInput(value.closes_at)
  form.time_limit_minutes = value.time_limit_minutes ?? ''
  form.shuffle_questions = value.shuffle_questions
}, { immediate: true })

const headerErrors = ref<ValidationErrors>({})
const generalError = ref<string | null>(null)
const isSavingHeader = ref(false)

async function saveHeader() {
  isSavingHeader.value = true
  headerErrors.value = {}
  generalError.value = null

  try {
    await api.updateExam(slug.value, {
      title: form.title,
      description: form.description || null,
      opens_at: fromLocalInput(form.opens_at),
      closes_at: fromLocalInput(form.closes_at),
      time_limit_minutes: form.time_limit_minutes === '' ? null : Number(form.time_limit_minutes),
      shuffle_questions: form.shuffle_questions,
    })
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

/* ---------- Статус ---------- */

const isChangingStatus = ref(false)

async function changeStatus(status: ExamStatus) {
  isChangingStatus.value = true
  generalError.value = null

  try {
    await api.changeStatus(slug.value, status)
    await refresh()
  }
  catch (caught) {
    generalError.value = (caught as FetchError<{ message?: string }>).data?.message
      ?? 'Не удалось сменить статус.'
  }
  finally {
    isChangingStatus.value = false
  }
}

const { confirm } = useAppDialog()

async function destroy() {
  if (!await confirm({
    title: 'Удалить экзамен?',
    message: 'Он пропадёт из списков. Сданные работы останутся в базе.',
    confirmLabel: 'Удалить',
    danger: true,
  })) {
    return
  }

  await api.deleteExam(slug.value)
  await router.push('/exams/manage')
}

/* ---------- Вопросы ---------- */

const quizErrors = ref<ValidationErrors>({})
const isSavingQuiz = ref(false)

async function saveQuiz(payload: QuizPayload) {
  isSavingQuiz.value = true
  quizErrors.value = {}
  generalError.value = null

  try {
    await api.saveQuiz(slug.value, payload)
    await refresh()
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      quizErrors.value = caught.errors
    }
    else {
      generalError.value = 'Не удалось сохранить вопросы.'
    }
  }
  finally {
    isSavingQuiz.value = false
  }
}

async function removeQuiz() {
  if (!await confirm({
    title: 'Удалить вопросы?',
    message: 'Вместе с ними уйдут и попытки — и их разбор.',
    confirmLabel: 'Удалить',
    danger: true,
  })) {
    return
  }

  await api.deleteQuiz(slug.value)
  await refresh()
}

/* ---------- Назначенные ---------- */

const { data: assignmentsData, refresh: refreshAssignments } = await useAsyncData(
  `exams.manage.${slug.value}.assignments`,
  () => api.fetchAssignments(slug.value),
)

/** Выбранные люди — то, что уйдёт на сервер полным списком. */
const chosenPeople = ref<{ id: number, name: string }[]>([])
const dueAt = ref('')

watch(assignmentsData, (value) => {
  const rows = value?.data ?? []

  chosenPeople.value = rows.flatMap(row => row.user ? [row.user] : [])
  dueAt.value = toLocalInput(rows[0]?.due_at ?? null)
}, { immediate: true })

const people = useDebouncedSearch<{ id: number, name: string }>(
  async term => (await api.searchPeople(term)).data,
)

function addPerson(person: { id: number, name: string }) {
  people.clear()

  if (!chosenPeople.value.some(one => one.id === person.id)) {
    chosenPeople.value = [...chosenPeople.value, person]
  }
}

function dropPerson(id: number) {
  chosenPeople.value = chosenPeople.value.filter(person => person.id !== id)
}

/**
 * Отделы и группы — способ добрать людей разом: на сервере они разворачиваются
 * в тех же людей, и назад список приходит поимённым. Поэтому выбранное здесь
 * не хранится между сохранениями — хранится его результат.
 */
const { fetchStructure } = useStructureApi()
const { fetchGroups } = useGroupsApi()

const { data: addressees } = await useAsyncData('exams.addressees', async () => {
  const [structure, groups] = await Promise.allSettled([fetchStructure(), fetchGroups()])

  return {
    departments: structure.status === 'fulfilled' ? structure.value.data : [],
    groups: groups.status === 'fulfilled' ? groups.value.data : [],
  }
})

const chosenDepartments = ref<number[]>([])
const chosenGroups = ref<number[]>([])

const departmentOptions = computed(() => {
  const options: { value: string, label: string }[] = []

  const walk = (nodes: Department[], depth: number) => {
    for (const node of nodes) {
      options.push({ value: String(node.id), label: `${'— '.repeat(depth)}${node.name}` })
      walk(node.children ?? [], depth + 1)
    }
  }

  walk(addressees.value?.departments ?? [], 0)

  return options
})

const groupOptions = computed(() => (addressees.value?.groups ?? [])
  .map((group: Group) => ({ value: String(group.id), label: group.name })))

const departmentToAdd = ref('')
const groupToAdd = ref('')

watch(departmentToAdd, (value) => {
  if (value && !chosenDepartments.value.includes(Number(value))) {
    chosenDepartments.value = [...chosenDepartments.value, Number(value)]
  }

  departmentToAdd.value = ''
})

watch(groupToAdd, (value) => {
  if (value && !chosenGroups.value.includes(Number(value))) {
    chosenGroups.value = [...chosenGroups.value, Number(value)]
  }

  groupToAdd.value = ''
})

function departmentName(id: number): string {
  const found = departmentOptions.value.find(option => Number(option.value) === id)

  return found ? found.label.replace(/^(— )+/, '') : String(id)
}

function groupName(id: number): string {
  return (addressees.value?.groups ?? []).find((group: Group) => group.id === id)?.name ?? String(id)
}

function dropDepartment(id: number) {
  chosenDepartments.value = chosenDepartments.value.filter(one => one !== id)
}

function dropGroup(id: number) {
  chosenGroups.value = chosenGroups.value.filter(one => one !== id)
}

const assignErrors = ref<ValidationErrors>({})
const isAssigning = ref(false)

async function saveAssignments() {
  isAssigning.value = true
  assignErrors.value = {}
  generalError.value = null

  try {
    await api.assign(slug.value, {
      users: chosenPeople.value.map(person => person.id),
      departments: chosenDepartments.value,
      groups: chosenGroups.value,
      due_at: dueAt.value === '' ? null : fromLocalInput(dueAt.value),
    })

    chosenDepartments.value = []
    chosenGroups.value = []

    await Promise.all([refreshAssignments(), refreshResults()])
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      assignErrors.value = caught.errors
    }
    else {
      generalError.value = 'Не удалось сохранить назначения.'
    }
  }
  finally {
    isAssigning.value = false
  }
}

/* ---------- Результаты ---------- */

const { data: resultsData, refresh: refreshResults } = await useAsyncData(
  `exams.manage.${slug.value}.results`,
  () => api.fetchResults(slug.value),
)

const results = computed(() => resultsData.value?.data ?? [])

function resultLabel(row: { passed: boolean, awaiting_review: boolean, attempts_spent: number, best_score: number | null, overdue: boolean }): string {
  if (row.passed) {
    return `Сдан — ${row.best_score}%`
  }

  if (row.awaiting_review) {
    return 'На проверке'
  }

  if (row.attempts_spent > 0) {
    return `Не сдан — лучшая ${row.best_score}%`
  }

  return row.overdue ? 'Не садился, срок прошёл' : 'Не садился'
}

function day(value: string | null): string {
  return value ? new Date(value).toLocaleDateString('ru-RU') : '—'
}
</script>

<template>
  <section>
    <p v-if="error" class="alert alert--danger" role="alert">
      Экзамен не найден.
    </p>

    <div v-else-if="pending" class="card">
      <div class="skeleton skeleton-line" />
    </div>

    <template v-else-if="exam">
      <header class="head">
        <NuxtLink to="/exams/manage" class="faint back">
          ← Экзамены
        </NuxtLink>

        <div class="head__row">
          <h1 class="page-title">
            {{ exam.title }}
          </h1>
          <span
            class="badge"
            :class="{ 'badge--success': exam.status === 'published', 'badge--warning': exam.status === 'draft' }"
          >
            {{ exam.status_label }}
          </span>
        </div>
      </header>

      <p v-if="generalError" class="alert alert--danger" role="alert">
        {{ generalError }}
      </p>

      <div class="stack">
        <!-- Шапка: название, окно, таймер. -->
        <form class="card panel" @submit.prevent="saveHeader">
          <h2 class="panel__title">
            Об экзамене
          </h2>

          <div class="field">
            <label class="field-label" for="exam-title">Название</label>
            <input id="exam-title" v-model.trim="form.title" class="input" maxlength="255" required>
            <p v-if="headerErrors.title?.length" class="field-error">
              {{ headerErrors.title[0] }}
            </p>
          </div>

          <div class="field">
            <label class="field-label" for="exam-description">Описание <span class="field-optional">— видно сдающим</span></label>
            <textarea id="exam-description" v-model.trim="form.description" class="input" rows="3" maxlength="2000" />
          </div>

          <div class="field-pair">
            <div class="field">
              <label class="field-label" for="exam-opens">Открывается</label>
              <input id="exam-opens" v-model="form.opens_at" type="datetime-local" class="input">
              <p v-if="headerErrors.opens_at?.length" class="field-error">
                {{ headerErrors.opens_at[0] }}
              </p>
            </div>
            <div class="field">
              <label class="field-label" for="exam-closes">Закрывается</label>
              <input id="exam-closes" v-model="form.closes_at" type="datetime-local" class="input">
              <p v-if="headerErrors.closes_at?.length" class="field-error">
                {{ headerErrors.closes_at[0] }}
              </p>
            </div>
          </div>

          <div class="field">
            <label class="field-label" for="exam-limit">Минут на попытку <span class="field-optional">— пусто, если без таймера</span></label>
            <input id="exam-limit" v-model="form.time_limit_minutes" type="number" min="1" max="480" class="input input--short">
            <p v-if="headerErrors.time_limit_minutes?.length" class="field-error">
              {{ headerErrors.time_limit_minutes[0] }}
            </p>
          </div>

          <label class="choice">
            <input v-model="form.shuffle_questions" type="checkbox">
            Перемешивать вопросы — у соседей по столу будет разный порядок
          </label>

          <div class="panel__actions">
            <button type="submit" class="button-primary" :disabled="isSavingHeader">
              {{ isSavingHeader ? 'Сохраняем…' : 'Сохранить' }}
            </button>

            <button
              v-if="exam.status !== 'published'"
              type="button"
              class="button-secondary"
              :disabled="isChangingStatus"
              @click="changeStatus('published')"
            >
              Опубликовать
            </button>
            <button
              v-else
              type="button"
              class="button-secondary"
              :disabled="isChangingStatus"
              @click="changeStatus('archived')"
            >
              В архив
            </button>

            <button type="button" class="button-ghost danger" @click="destroy">
              Удалить
            </button>
          </div>
        </form>

        <!-- Вопросы: редактор общий с тестом урока. -->
        <QuizBuilder
          :quiz="exam.quiz ?? null"
          :errors="quizErrors"
          :is-submitting="isSavingQuiz"
          note="Планку зачёта и число попыток здесь ставите вы: у экзамена они свои, а не правило урока."
          @save="saveQuiz"
          @remove="removeQuiz"
        />

        <!-- Назначенные. -->
        <div class="card panel">
          <h2 class="panel__title">
            Кому сдавать
          </h2>
          <p class="faint">
            Список сохраняется целиком: убранный из него освобождается от
            обязанности, его сданные работы остаются в результатах. Отдел и
            группа добавляют своих людей в список при сохранении — новичков
            отдела назначают следующим сохранением.
          </p>

          <div class="field">
            <label class="field-label" for="exam-person">Добавить человека</label>
            <input
              id="exam-person"
              v-model="people.query.value"
              class="input"
              type="search"
              placeholder="Фамилия или имя"
              autocomplete="off"
            >
            <ul v-if="people.results.value.length" class="picker">
              <li v-for="person in people.results.value" :key="person.id">
                <button type="button" class="picker__option" @click="addPerson(person)">
                  {{ person.name }}
                </button>
              </li>
            </ul>
          </div>

          <div class="field-pair">
            <div class="field">
              <label class="field-label" for="exam-department">Добавить отдел — с вложенными</label>
              <UiSelect id="exam-department" v-model="departmentToAdd" :options="departmentOptions" placeholder="Выберите отдел" />
            </div>
            <div class="field">
              <label class="field-label" for="exam-group">Добавить группу</label>
              <UiSelect id="exam-group" v-model="groupToAdd" :options="groupOptions" placeholder="Выберите группу" />
            </div>
          </div>

          <ul v-if="chosenDepartments.length || chosenGroups.length" class="chips">
            <li v-for="id in chosenDepartments" :key="`d-${id}`" class="chip">
              {{ departmentName(id) }}
              <button type="button" class="chip__drop" aria-label="Убрать" @click="dropDepartment(id)">
                ×
              </button>
            </li>
            <li v-for="id in chosenGroups" :key="`g-${id}`" class="chip">
              {{ groupName(id) }}
              <button type="button" class="chip__drop" aria-label="Убрать" @click="dropGroup(id)">
                ×
              </button>
            </li>
          </ul>

          <ul v-if="chosenPeople.length" class="chips">
            <li v-for="person in chosenPeople" :key="person.id" class="chip">
              {{ person.name }}
              <button type="button" class="chip__drop" aria-label="Убрать" @click="dropPerson(person.id)">
                ×
              </button>
            </li>
          </ul>
          <p v-else class="faint">
            Пока никому не назначен.
          </p>

          <div class="field">
            <label class="field-label" for="exam-due">Срок <span class="field-optional">— один на всех в списке</span></label>
            <input id="exam-due" v-model="dueAt" type="datetime-local" class="input input--short">
            <p v-if="assignErrors.due_at?.length" class="field-error">
              {{ assignErrors.due_at[0] }}
            </p>
          </div>

          <div class="panel__actions">
            <button type="button" class="button-primary" :disabled="isAssigning" @click="saveAssignments">
              {{ isAssigning ? 'Сохраняем…' : 'Сохранить назначения' }}
            </button>
          </div>
        </div>

        <!-- Результаты. -->
        <div class="card panel">
          <h2 class="panel__title">
            Результаты
          </h2>

          <p v-if="!results.length" class="faint">
            Назначите экзамен — здесь появится строка на каждого.
          </p>

          <div v-else class="table-scroll">
            <table class="table">
              <thead>
                <tr>
                  <th>Сотрудник</th>
                  <th>Срок</th>
                  <th>Попытки</th>
                  <th>Итог</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in results" :key="row.user?.id ?? 0">
                  <td>{{ row.user?.name ?? '—' }}</td>
                  <td>{{ day(row.due_at) }}</td>
                  <td>{{ row.attempts_spent }}</td>
                  <td>
                    <span
                      class="badge"
                      :class="{
                        'badge--success': row.passed,
                        'badge--warning': row.awaiting_review,
                        'badge--danger': !row.passed && !row.awaiting_review && (row.attempts_spent > 0 || row.overdue),
                      }"
                    >
                      {{ resultLabel(row) }}
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </template>
  </section>
</template>

<style scoped>
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

.panel__title {
  margin: 0;
  font-size: 1.1rem;
}

.panel__actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.75rem;
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
  max-width: 12rem;
}

.choice {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.9rem;
}

.picker {
  margin: 0.25rem 0 0;
  padding: 0;
  list-style: none;
  border: 1px solid var(--color-border);
  border-radius: 0.5rem;
  overflow: hidden;
}

.picker__option {
  display: block;
  width: 100%;
  padding: 0.5rem 0.75rem;
  text-align: left;
  background: none;
  border: 0;
  cursor: pointer;
}

.picker__option:hover {
  background: var(--color-surface);
}

.chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin: 0;
  padding: 0;
  list-style: none;
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

.table-scroll {
  overflow-x: auto;
}

.table {
  width: 100%;
  border-collapse: collapse;
}

.table th,
.table td {
  padding: 0.5rem 0.75rem;
  text-align: left;
  border-bottom: 1px solid var(--color-border);
  white-space: nowrap;
}

.table th {
  font-weight: 500;
  color: var(--color-text-faint);
}

@media (max-width: 34rem) {
  .field-pair {
    grid-template-columns: 1fr;
  }
}
</style>
