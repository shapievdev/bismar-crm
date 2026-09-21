<script setup lang="ts">
import type { SelectOption } from '~/components/ui/Select.vue'
import type { ValidationErrors } from '~/composables/useAuth'
import type { Survey, SurveyPayload, SurveyQuestionType } from '~/types/survey'

/**
 * Редактор опроса — один на урок, документ, справочник, версию и новость.
 *
 * Рядом с редактором теста и нарочно проще: ни ключа, ни очков, ни попыток. Зато
 * есть то, чего у теста нет, — обязательность, анонимность и срок, и каждая из
 * трёх настроек меняет обещание, которое человек прочитает до того, как ответит.
 * Поэтому они объяснены словами рядом, а не спрятаны за короткой подписью.
 *
 * Своего представления о материале компонент не имеет: как называется то, при
 * чём он стоит, ему говорят снаружи — «урок», «документ», «новость».
 */
const props = withDefaults(defineProps<{
  survey: Survey | null
  errors: ValidationErrors
  isSubmitting: boolean
  /** Как назвать материал в объяснениях: «урок», «документ», «новость». */
  materialLabel?: string
  /** Что материал теряет, пока опрос не пройден: «не зачтётся», «не отметится». */
  creditLabel?: string
}>(), {
  materialLabel: 'материал',
  creditLabel: 'не будет зачтён',
})

const emit = defineEmits<{
  save: [payload: SurveyPayload]
  remove: []
}>()

const questionTypes: SelectOption<SurveyQuestionType>[] = [
  { value: 'single', label: 'Один вариант', hint: 'Отмечают что-то одно' },
  { value: 'multiple', label: 'Несколько вариантов', hint: 'Отмечают сколько угодно' },
  { value: 'scale', label: 'Шкала', hint: 'Оценка числом, с подписями у концов' },
  { value: 'text', label: 'Короткий ответ', hint: 'Одна строка' },
  { value: 'long_text', label: 'Развёрнутый ответ', hint: 'Несколько строк' },
]

function isChoice(type: SurveyQuestionType): boolean {
  return type === 'single' || type === 'multiple'
}

function isScale(type: SurveyQuestionType): boolean {
  return type === 'scale'
}

/**
 * Черновик держит номера того, что уже есть на сервере: по ним разложены снимки
 * ответов, и вопрос, вернувшийся без номера, сервер заведёт заново — вместе с
 * ним пропадёт из сводки всё, что на него отвечали.
 */
function blankQuestion(): SurveyPayload['questions'][number] {
  return {
    text: '',
    type: 'single',
    is_required: true,
    allows_other: false,
    scale_min: 1,
    scale_max: 5,
    scale_min_label: '',
    scale_max_label: '',
    options: [{ text: '' }, { text: '' }],
  }
}

function draftFrom(survey: Survey | null): SurveyPayload {
  if (survey === null) {
    return {
      title: 'Что скажете?',
      description: null,
      is_required: false,
      is_anonymous: false,
      closes_at: null,
      thanks: null,
      questions: [blankQuestion()],
    }
  }

  return {
    title: survey.title,
    description: survey.description,
    is_required: survey.is_required,
    is_anonymous: survey.is_anonymous,
    // Поле даты понимает только «ГГГГ-ММ-ДД», а с сервера приходит время целиком.
    closes_at: survey.closes_at === null ? null : survey.closes_at.slice(0, 10),
    thanks: survey.thanks,
    questions: (survey.questions ?? []).map(question => ({
      id: question.id,
      text: question.text,
      type: question.type,
      is_required: question.is_required,
      allows_other: question.allows_other,
      scale_min: question.scale?.steps[0] ?? 1,
      scale_max: question.scale?.steps.at(-1) ?? 5,
      scale_min_label: question.scale?.min_label ?? '',
      scale_max_label: question.scale?.max_label ?? '',
      options: question.options.map(option => ({ id: option.id, text: option.text })),
    })),
  }
}

const draft = ref<SurveyPayload>(draftFrom(props.survey))

watch(() => props.survey, survey => (draft.value = draftFrom(survey)))

function addQuestion() {
  draft.value.questions.push(blankQuestion())
}

function removeQuestion(index: number) {
  draft.value.questions.splice(index, 1)
}

function addOption(questionIndex: number) {
  draft.value.questions[questionIndex]?.options.push({ text: '' })
}

function removeOption(questionIndex: number, optionIndex: number) {
  draft.value.questions[questionIndex]?.options.splice(optionIndex, 1)
}

/**
 * Что уходит на сервер: у выбора — варианты, у шкалы — границы и подписи.
 * Лишнее не отправляется вовсе — сервер его всё равно снимет, а в черновике оно
 * пусть живёт: сменив вид вопроса туда и обратно, автор не должен набирать
 * варианты заново.
 */
function payload(): SurveyPayload {
  return {
    ...draft.value,
    thanks: (draft.value.thanks ?? '').trim() === '' ? null : draft.value.thanks,
    closes_at: draft.value.closes_at === null || draft.value.closes_at === '' ? null : draft.value.closes_at,
    questions: draft.value.questions.map((question) => {
      const common = {
        // Номер едет обратно всегда — иначе вопрос заведётся заново.
        id: question.id ?? null,
        text: question.text,
        type: question.type,
        is_required: question.is_required,
      }

      if (isChoice(question.type)) {
        return { ...common, allows_other: question.allows_other, options: question.options }
      }

      if (isScale(question.type)) {
        return {
          ...common,
          allows_other: false,
          options: [],
          scale_min: question.scale_min,
          scale_max: question.scale_max,
          scale_min_label: (question.scale_min_label ?? '').trim() || null,
          scale_max_label: (question.scale_max_label ?? '').trim() || null,
        }
      }

      return { ...common, allows_other: false, options: [] }
    }),
  }
}

/** Плоский поиск ошибки: `questions.0.options`. */
function errorFor(path: string): string | null {
  return props.errors[path]?.[0] ?? null
}

const scaleSteps: SelectOption<number>[] = [3, 4, 5, 7, 10].map(value => ({
  value,
  label: `1 … ${value}`,
}))
</script>

<template>
  <section class="survey-builder">
    <header class="survey-builder__header">
      <h2>Опрос</h2>

      <button
        v-if="survey"
        type="button"
        class="danger"
        :disabled="isSubmitting"
        @click="emit('remove')"
      >
        Удалить опрос
      </button>
    </header>

    <p class="muted">
      Опрос — не проверка: правильного ответа у него нет, и очков он не считает.
      Проходят его <b>один раз</b> — переспросить человека второй раз нельзя.
    </p>

    <div class="field">
      <label for="survey-title">Название</label>
      <input id="survey-title" v-model="draft.title" type="text" class="input" maxlength="255">
      <p v-if="errorFor('title')" class="error">{{ errorFor('title') }}</p>
    </div>

    <div class="field">
      <label for="survey-description">Вступление</label>
      <textarea
        id="survey-description"
        v-model="draft.description"
        class="textarea"
        rows="2"
        maxlength="1000"
        placeholder="Зачем спрашиваем и что будет с ответами"
      />
    </div>

    <!-- Две настройки, которые меняют обещание человеку, а не вид формы. -->
    <fieldset class="switches">
      <legend class="switches__legend">Как проводим</legend>

      <label class="switch">
        <input v-model="draft.is_required" type="checkbox">
        <span>
          <b>Обязательный</b>
          <span class="switch__hint">
            Пока опрос не отправлен, {{ materialLabel }} {{ creditLabel }}. Необязательный
            ничего не держит — его проходят те, кому есть что сказать.
          </span>
        </span>
      </label>

      <label class="switch">
        <input v-model="draft.is_anonymous" type="checkbox">
        <span>
          <b>Анонимный</b>
          <span class="switch__hint">
            В сводке останутся только цифры и тексты — без имён, и узнать их будет
            нельзя даже при желании. Кто опрос прошёл, видно по-прежнему: иначе
            обязательный опрос нечем закрыть.
          </span>
        </span>
      </label>
    </fieldset>

    <div class="pair">
      <div class="field">
        <label for="survey-closes">Принимать ответы до</label>
        <input id="survey-closes" v-model="draft.closes_at" type="date" class="input">
        <p class="hint faint">
          Пусто — опрос открыт, пока стоит {{ materialLabel }}. После этой даты ответы
          не принимаются, и обязательный опрос перестаёт держать зачёт.
        </p>
      </div>

      <div class="field">
        <label for="survey-thanks">Слово после отправки</label>
        <input
          id="survey-thanks"
          v-model="draft.thanks"
          type="text"
          class="input"
          maxlength="500"
          placeholder="Спасибо, разберём на планёрке"
        >
      </div>
    </div>

    <p v-if="errorFor('questions')" class="error">{{ errorFor('questions') }}</p>

    <article
      v-for="(question, questionIndex) in draft.questions"
      :key="questionIndex"
      class="question card"
    >
      <header class="question__header">
        <span class="question__number">Вопрос {{ questionIndex + 1 }}</span>

        <button
          v-if="draft.questions.length > 1"
          type="button"
          class="button-ghost button-sm"
          @click="removeQuestion(questionIndex)"
        >
          Убрать
        </button>
      </header>

      <div class="field">
        <label :for="`survey-question-${questionIndex}`">Вопрос</label>
        <textarea
          :id="`survey-question-${questionIndex}`"
          v-model="question.text"
          class="textarea"
          rows="2"
          maxlength="2000"
        />
        <p v-if="errorFor(`questions.${questionIndex}.text`)" class="error">
          {{ errorFor(`questions.${questionIndex}.text`) }}
        </p>
      </div>

      <div class="pair">
        <div class="field">
          <label :for="`survey-type-${questionIndex}`">Чем отвечают</label>
          <UiSelect
            :id="`survey-type-${questionIndex}`"
            v-model="question.type"
            :options="questionTypes"
          />
        </div>

        <label class="check">
          <input v-model="question.is_required" type="checkbox">
          <span>Обязательный вопрос — пропустить нельзя</span>
        </label>
      </div>

      <!-- Выбор: варианты и «свой вариант». -->
      <template v-if="isChoice(question.type)">
        <ul class="options">
          <li v-for="(option, optionIndex) in question.options" :key="optionIndex" class="option">
            <input
              v-model="option.text"
              type="text"
              class="input"
              maxlength="1000"
              :placeholder="`Вариант ${optionIndex + 1}`"
            >

            <button
              v-if="question.options.length > 2"
              type="button"
              class="button-ghost button-sm"
              @click="removeOption(questionIndex, optionIndex)"
            >
              ✕
            </button>
          </li>
        </ul>

        <p v-if="errorFor(`questions.${questionIndex}.options`)" class="error">
          {{ errorFor(`questions.${questionIndex}.options`) }}
        </p>

        <div class="question__foot">
          <button type="button" class="button-ghost button-sm" @click="addOption(questionIndex)">
            Ещё вариант
          </button>

          <label class="check">
            <input v-model="question.allows_other" type="checkbox">
            <span>Разрешить свой вариант</span>
          </label>
        </div>
      </template>

      <!-- Шкала: сколько делений и что значат её концы. -->
      <template v-if="isScale(question.type)">
        <div class="pair">
          <div class="field">
            <label :for="`survey-scale-${questionIndex}`">Делений</label>
            <UiSelect
              :id="`survey-scale-${questionIndex}`"
              v-model="question.scale_max as number"
              :options="scaleSteps"
            />
          </div>

          <div class="field">
            <label :for="`survey-scale-min-${questionIndex}`">Что значит 1</label>
            <input
              :id="`survey-scale-min-${questionIndex}`"
              v-model="question.scale_min_label"
              type="text"
              class="input"
              maxlength="120"
              placeholder="Совсем нет"
            >
          </div>

          <div class="field">
            <label :for="`survey-scale-max-${questionIndex}`">Что значит {{ question.scale_max }}</label>
            <input
              :id="`survey-scale-max-${questionIndex}`"
              v-model="question.scale_max_label"
              type="text"
              class="input"
              maxlength="120"
              placeholder="Да, полностью"
            >
          </div>
        </div>

        <p v-if="errorFor(`questions.${questionIndex}.scale_max`)" class="error">
          {{ errorFor(`questions.${questionIndex}.scale_max`) }}
        </p>
      </template>
    </article>

    <div class="survey-builder__actions">
      <button type="button" class="button-ghost" @click="addQuestion">
        Добавить вопрос
      </button>

      <button
        type="button"
        class="button-primary"
        :disabled="isSubmitting"
        @click="emit('save', payload())"
      >
        {{ isSubmitting ? 'Сохраняем…' : 'Сохранить опрос' }}
      </button>
    </div>

    <p v-if="survey" class="warn faint">
      Правка опроса, который уже проходили, стоит ответов: снятый вопрос уносит с
      собой всё, что на него отвечали.
    </p>
  </section>
</template>

<style scoped>
.survey-builder {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.survey-builder__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}

.survey-builder__header h2 {
  margin: 0;
  font-size: 1.15rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.field label {
  font-size: 0.85rem;
  color: var(--color-text-muted);
}

.pair {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
  gap: 0.75rem;
  align-items: end;
}

.switches {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  margin: 0;
  padding: 0.85rem;
  border: 0;
  border-radius: var(--radius);
  background: var(--color-surface);
}

.switches__legend {
  padding: 0 0.25rem;
  font-size: 0.85rem;
  color: var(--color-text-muted);
}

.switch,
.check {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  cursor: pointer;
}

.switch span,
.check span {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  font-size: 0.9rem;
}

.switch__hint {
  color: var(--color-text-muted);
  font-size: 0.82rem;
  line-height: 1.45;
}

.check {
  align-items: center;
  font-size: 0.88rem;
}

.question {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 0.9rem;
}

.question__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
}

.question__number {
  font-size: 0.8rem;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--color-text-muted);
}

.question__foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  flex-wrap: wrap;
}

.options {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.option {
  display: flex;
  align-items: center;
  gap: 0.4rem;
}

.survey-builder__actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  flex-wrap: wrap;
}

.hint,
.warn {
  margin: 0;
  font-size: 0.8rem;
  line-height: 1.45;
}

.error {
  margin: 0;
  color: var(--color-danger);
  font-size: 0.82rem;
}
</style>
