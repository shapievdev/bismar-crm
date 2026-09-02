<script setup lang="ts">
import type { SelectOption } from '~/components/ui/Select.vue'
import type { ValidationErrors } from '~/composables/useAuth'
import type { QuestionTable, QuestionType, Quiz, QuizPayload } from '~/types/lms'

const props = defineProps<{
  quiz: Quiz | null
  errors: ValidationErrors
  isSubmitting: boolean
  /**
   * Проходной балл, заданный правилом, а не автором.
   *
   * У теста при уроке он равен ста процентам: урок зачитывается, когда все
   * ответы верны, — и поле в форме обещало бы выбор, которого нет. У проверки
   * при новости планку по-прежнему ставит автор: там тест подтверждает
   * ознакомление, а не зачитывает урок.
   */
  fixedPassingScore?: number | null
}>()

const emit = defineEmits<{
  save: [payload: QuizPayload]
  remove: []
}>()

const questionTypes: SelectOption<QuestionType>[] = [
  { value: 'single', label: 'Один ответ' },
  { value: 'multiple', label: 'Несколько ответов' },
  { value: 'text', label: 'Короткий ответ' },
  { value: 'long_text', label: 'Развёрнутый ответ' },
  { value: 'table', label: 'Таблица' },
]

/** Заполняют таблицу: ни вариантов, ни эталона — зачёт по заполненности. */
function isTable(type: QuestionType): boolean {
  return type === 'table'
}

function blankTable(): QuestionTable {
  return {
    row_label_title: '',
    columns: [{ title: '', kind: 'text', options: [] }],
    rows: [{ label: '' }],
    can_add_rows: false,
  }
}

/** Отвечают своими словами: вариантов нет, зато нужен эталон. */
function isWritten(type: QuestionType): boolean {
  return type === 'text' || type === 'long_text'
}

/**
 * Черновик хранит номера того, что уже есть на сервере: по ним разложены ответы
 * прошлых попыток, и вопрос, вернувшийся без номера, сервер заведёт заново — а
 * вместе с ним потеряется разбор всего, что на него отвечали.
 */
interface DraftOption { id?: number | null, text: string, is_correct: boolean }
interface DraftQuestion {
  id?: number | null
  text: string
  type: QuestionType
  points: number
  options: DraftOption[]
  expected_answer: string
  table: QuestionTable
}

function blankQuestion(): DraftQuestion {
  return {
    text: '',
    type: 'single',
    points: 1,
    options: [
      { text: '', is_correct: true },
      { text: '', is_correct: false },
    ],
    expected_answer: '',
    table: blankTable(),
  }
}

/**
 * The server replaces the quiz wholesale on save, so the editor keeps its own
 * draft and sends the complete thing — вместе с номерами: по ним сервер узнаёт
 * уже существующие вопросы и варианты и правит их на месте.
 */
function draftFrom(quiz: Quiz | null): QuizPayload {
  if (quiz === null) {
    return {
      title: 'Проверка знаний',
      description: null,
      passing_score: props.fixedPassingScore ?? 70,
      max_attempts: null,
      questions: [blankQuestion()],
    }
  }

  return {
    title: quiz.title,
    description: quiz.description,
    passing_score: props.fixedPassingScore ?? quiz.passing_score,
    max_attempts: quiz.max_attempts,
    questions: (quiz.questions ?? []).map(question => ({
      id: question.id,
      text: question.text,
      type: question.type,
      points: question.points,
      options: question.options.map(option => ({
        id: option.id,
        text: option.text,
        is_correct: option.is_correct ?? false,
      })),
      expected_answer: question.expected_answer ?? '',
      table: question.table ?? blankTable(),
    })),
  }
}

const draft = ref<QuizPayload>(draftFrom(props.quiz))

watch(() => props.quiz, quiz => (draft.value = draftFrom(quiz)))

function addQuestion() {
  draft.value.questions.push(blankQuestion())
}

function removeQuestion(index: number) {
  draft.value.questions.splice(index, 1)
}

function addOption(questionIndex: number) {
  draft.value.questions[questionIndex]?.options.push({ text: '', is_correct: false })
}

function removeOption(questionIndex: number, optionIndex: number) {
  draft.value.questions[questionIndex]?.options.splice(optionIndex, 1)
}

/**
 * A single-choice question may have only one correct option, which the server
 * also enforces — mirroring it here keeps the editor from building something
 * that cannot be saved.
 */
function markCorrect(questionIndex: number, optionIndex: number) {
  const question = draft.value.questions[questionIndex]

  if (!question) {
    return
  }

  if (question.type === 'single') {
    question.options.forEach((option, index) => {
      option.is_correct = index === optionIndex
    })

    return
  }

  const option = question.options[optionIndex]

  if (option) {
    option.is_correct = !option.is_correct
  }
}

/**
 * Что уходит на сервер: у вопроса с выбором — варианты, у письменного —
 * эталон. Присылать и то и другое значило бы держать два ключа у одного
 * вопроса.
 */
function payload(): QuizPayload {
  return {
    ...draft.value,
    questions: draft.value.questions.map((question) => {
      const common = {
        // Номер едет обратно всегда: без него сервер заведёт вопрос заново, и
        // ответы прошлых попыток перестанут с ним сходиться.
        id: question.id ?? null,
        text: question.text,
        type: question.type,
        points: question.points,
      }

      if (isWritten(question.type)) {
        return {
          ...common,
          options: [],
          expected_answer: (question.expected_answer ?? '').trim(),
        }
      }

      if (isTable(question.type)) {
        return { ...common, options: [], table: question.table }
      }

      return { ...common, options: question.options }
    }),
  }
}

function onTypeChange(questionIndex: number) {
  const question = draft.value.questions[questionIndex]

  if (question?.type !== 'single') {
    return
  }

  // Collapsing to single choice: keep only the first correct option.
  const firstCorrect = question.options.findIndex(option => option.is_correct)

  question.options.forEach((option, index) => {
    option.is_correct = index === (firstCorrect === -1 ? 0 : firstCorrect)
  })
}

/** Flat error lookup, e.g. errors['questions.0.options']. */
function errorFor(path: string): string | null {
  return props.errors[path]?.[0] ?? null
}
</script>

<template>
  <section class="quiz-builder">
    <header class="quiz-builder__header">
      <h2>Тест</h2>
      <button
        v-if="quiz"
        type="button"
        class="danger"
        :disabled="isSubmitting"
        @click="emit('remove')"
      >
        Удалить тест
      </button>
    </header>

    <p class="muted">
      Урок с тестом нельзя отметить пройденным — его засчитывает только сдача.
    </p>

    <p v-if="fixedPassingScore != null" class="rule">
      Тест зачитывает урок: он считается пройденным, когда сотрудник ответил
      верно на все вопросы. Проходной балл поэтому не настраивается.
    </p>

    <div class="row">
      <div class="field">
        <label for="quiz-title">Название</label>
        <input id="quiz-title" v-model.trim="draft.title" type="text">
        <p v-if="errorFor('title')" class="field__error">
          {{ errorFor('title') }}
        </p>
      </div>

      <div v-if="fixedPassingScore == null" class="field field--narrow">
        <label for="quiz-score">Проходной балл, %</label>
        <input id="quiz-score" v-model.number="draft.passing_score" type="number" min="1" max="100">
        <p v-if="errorFor('passing_score')" class="field__error">
          {{ errorFor('passing_score') }}
        </p>
      </div>

      <div class="field field--narrow">
        <label for="quiz-attempts">Попыток</label>
        <input
          id="quiz-attempts"
          v-model.number="draft.max_attempts"
          type="number"
          min="1"
          max="100"
          placeholder="без лимита"
        >
      </div>
    </div>

    <article v-for="(question, questionIndex) in draft.questions" :key="questionIndex" class="question">
      <header class="question__header">
        <span class="question__number">Вопрос {{ questionIndex + 1 }}</span>

        <div class="question__controls">
          <UiSelect
            v-model="question.type"
            :options="questionTypes"
            auto
            @update:model-value="onTypeChange(questionIndex)"
          />

          <input v-model.number="question.points" type="number" min="1" max="100" title="Баллы">

          <button
            type="button"
            class="danger"
            :disabled="draft.questions.length === 1"
            @click="removeQuestion(questionIndex)"
          >
            Удалить
          </button>
        </div>
      </header>

      <textarea v-model.trim="question.text" rows="2" placeholder="Текст вопроса" />
      <p v-if="errorFor(`questions.${questionIndex}.text`)" class="field__error">
        {{ errorFor(`questions.${questionIndex}.text`) }}
      </p>

      <!-- Письменный вопрос: вариантов нет, есть эталон. С ним ИИ сравнивает
           написанное сотрудником — по смыслу, а не по буквам. -->
      <template v-if="isWritten(question.type)">
        <label class="reference">
          <span>Эталонный ответ</span>
          <textarea
            v-model.trim="question.expected_answer"
            :rows="question.type === 'long_text' ? 4 : 2"
            placeholder="Как выглядит верный ответ. Своими словами, полно — с этим текстом будет сравниваться написанное сотрудником"
          />
        </label>

        <p class="reference__note">
          Ответ зачитывается, если он близок к эталону по смыслу: «деньги
          заморожены в запасах» и «товар лежит на складе, оплата у клиентов» —
          для проверки одно и то же. Порог схожести общий для всех тестов и
          задаётся в настройках консультанта. Сотрудник эталона не видит.
        </p>

        <p v-if="errorFor(`questions.${questionIndex}.expected_answer`)" class="field__error">
          {{ errorFor(`questions.${questionIndex}.expected_answer`) }}
        </p>
      </template>

      <!-- Таблица: столбцы и строки собирает автор, а зачитывается она по
           заполненности — верны ли числа, приложение знать не может. -->
      <template v-else-if="isTable(question.type)">
        <QuizTableEditor v-if="question.table" v-model="question.table" />

        <p v-if="errorFor(`questions.${questionIndex}.table.columns`)" class="field__error">
          {{ errorFor(`questions.${questionIndex}.table.columns`) }}
        </p>
        <p v-if="errorFor(`questions.${questionIndex}.table.rows`)" class="field__error">
          {{ errorFor(`questions.${questionIndex}.table.rows`) }}
        </p>
      </template>

      <template v-else>
        <div v-for="(option, optionIndex) in question.options" :key="optionIndex" class="option">
          <input
            :type="question.type === 'single' ? 'radio' : 'checkbox'"
            :name="`correct-${questionIndex}`"
            :checked="option.is_correct"
            title="Правильный вариант"
            @change="markCorrect(questionIndex, optionIndex)"
          >

          <input v-model.trim="option.text" type="text" placeholder="Текст варианта">

          <button
            type="button"
            class="danger"
            :disabled="question.options.length <= 2"
            @click="removeOption(questionIndex, optionIndex)"
          >
            ×
          </button>
        </div>

        <p v-if="errorFor(`questions.${questionIndex}.options`)" class="field__error">
          {{ errorFor(`questions.${questionIndex}.options`) }}
        </p>

        <button type="button" class="button-plain" @click="addOption(questionIndex)">
          Добавить вариант
        </button>
      </template>
    </article>

    <p v-if="errorFor('questions')" class="field__error">
      {{ errorFor('questions') }}
    </p>

    <div class="actions">
      <button type="button" class="button-plain" @click="addQuestion">
        Добавить вопрос
      </button>

      <button
        type="button"
        class="button-primary"
        :disabled="isSubmitting"
        @click="emit('save', payload())"
      >
        {{ isSubmitting ? 'Сохраняем…' : 'Сохранить тест' }}
      </button>
    </div>
  </section>
</template>

<style scoped>
.reference {
  display: block;
  margin: 0.5rem 0 0;
}

.reference span {
  display: block;
  margin-bottom: 0.25rem;
  font-size: 0.85rem;
  color: var(--color-text-muted);
}

.reference__note {
  margin: 0.4rem 0 0;
  color: var(--color-text-muted);
  font-size: 0.82rem;
}

/* Правило, а не настройка: сказать о нём надо там, где ищут поле. */
.rule {
  margin: 0 0 0.9rem;
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.quiz-builder {
  max-width: 46rem;
  margin-top: 2.5rem;
  padding-top: 1.5rem;
  border-top: 1px solid var(--color-border);
}

.quiz-builder__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}

.quiz-builder__header h2 {
  margin: 0;
  font-size: 1.2rem;
}

.muted {
  margin: 0.25rem 0 1rem;
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.row {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  flex: 1;
  min-width: 12rem;
}

.field--narrow {
  flex: 0 0 9rem;
  min-width: 9rem;
}

.field label {
  font-size: 0.85rem;
  font-weight: 500;
}

.field__error {
  margin: 0.25rem 0 0;
  color: var(--color-danger);
  font-size: 0.82rem;
}

input[type='text'],
input[type='number'],
select,
textarea {
  padding: 0.5rem 0.7rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface);
  color: var(--color-text);
  font: inherit;
}

textarea {
  width: 100%;
  resize: vertical;
}

.question {
  margin-top: 1.25rem;
  padding: 1rem 1.25rem;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
}

.question__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 0.6rem;
}

.question__number {
  font-weight: 500;
  font-size: 0.9rem;
}

.question__controls {
  display: flex;
  gap: 0.4rem;
}

.question__controls input[type='number'] {
  width: 4.5rem;
}

.option {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-top: 0.5rem;
}

.option input[type='text'] {
  flex: 1;
}

.button-plain,
.danger {
  padding: 0.4rem 0.85rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface);
  color: var(--color-text);
  font: inherit;
  font-size: 0.9rem;
  cursor: pointer;
}

.danger {
  color: var(--color-danger);
  border-color: var(--color-danger);
}

.button-plain:disabled,
.danger:disabled {
  opacity: 0.45;
  cursor: default;
}

.actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-top: 1.25rem;
}

/*
 * На телефоне строка заголовка вопроса не помещалась: «Вопрос 1» переносился
 * по слогам, а «Удалить» уходил за край карточки. Номер здесь встаёт над
 * управлением, а не рядом с ним, — вертикали на узком экране больше, чем
 * горизонтали, и тратить её дешевле.
 */
@media (max-width: 34rem) {
  .question {
    padding: 0.9rem 1rem;
  }

  .question__header {
    flex-direction: column;
    align-items: stretch;
    gap: 0.5rem;
  }

  .question__controls {
    flex-wrap: wrap;
  }

  /* Удаление уезжает к дальнему краю: разрушающее действие не должно стоять
     вплотную к выбору типа, куда палец идёт постоянно. */
  .question__controls .danger {
    margin-left: auto;
  }

  /*
   * Проходной балл и попытки перестают быть узкими колонками. В девять
   * сантиметров они складывались в одинокий короткий ящик посреди пустой
   * строки — на телефоне это читалось как оборванная форма.
   */
  .field--narrow {
    flex: 1 1 100%;
    min-width: 0;
  }

  .actions {
    flex-wrap: wrap;
  }
}
</style>
