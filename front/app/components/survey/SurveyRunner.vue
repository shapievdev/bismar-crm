<script setup lang="ts">
import type { Survey, SurveyAnswer, SurveyQuestion } from '~/types/survey'

/**
 * Опрос, который проходят: одна разметка на урок, документ, справочник и новость.
 *
 * Проходят его один раз (решение пользователя 2026-09-21), поэтому у бланка два
 * состояния и обратного пути нет: до отправки — вопросы, после — благодарность.
 * Кнопки «пройти заново» здесь не будет никогда, и говорить об этом надо до
 * отправки, а не после: человек должен понимать, что отвечает набело.
 *
 * Компонент ничего не решает сам — показывает вопросы и отдаёт ответы наружу.
 * Что значит отправка для материала, знает тот, кто его поставил.
 */
const props = defineProps<{
  survey: Survey
  isSubmitting: boolean
  errorMessage?: string | null
  /** Отправлен ли опрос только что — или он был пройден раньше. */
  isDone: boolean
  /** Как назвать материал в объяснении: «урок», «документ», «новость». */
  materialLabel?: string
}>()

const emit = defineEmits<{
  submit: [answers: Record<number, SurveyAnswer>]
}>()

const answers = ref<Record<number, SurveyAnswer>>({})

const questions = computed<SurveyQuestion[]>(() => props.survey.questions ?? [])

function answer(questionId: number): SurveyAnswer {
  return answers.value[questionId] ?? {}
}

function set(questionId: number, patch: SurveyAnswer) {
  answers.value = { ...answers.value, [questionId]: { ...answer(questionId), ...patch } }
}

function chosen(questionId: number): number[] {
  return answer(questionId).options ?? []
}

function isChosen(questionId: number, optionId: number): boolean {
  return chosen(questionId).includes(optionId)
}

function toggle(question: SurveyQuestion, optionId: number) {
  const picked = chosen(question.id)

  set(question.id, {
    options: question.type === 'single'
      ? [optionId]
      : picked.includes(optionId) ? picked.filter(id => id !== optionId) : [...picked, optionId],
  })
}

/**
 * Отвечен ли вопрос — по тому же правилу, по какому это решает сервер: пустая
 * строка и ничего не отмеченное — не ответ.
 */
function isAnswered(question: SurveyQuestion): boolean {
  const given = answer(question.id)

  if (question.type === 'single' || question.type === 'multiple') {
    return (given.options?.length ?? 0) > 0 || (given.other ?? '').trim() !== ''
  }

  if (question.type === 'scale') {
    return given.scale != null
  }

  return (given.text ?? '').trim() !== ''
}

/** Отправлять можно, когда отвечен каждый обязательный вопрос. */
const canSubmit = computed(
  () => questions.value.length > 0
    && questions.value.every(question => !question.is_required || isAnswered(question)),
)

const label = computed(() => props.materialLabel ?? 'материал')
</script>

<template>
  <section class="card survey">
    <header class="survey__head">
      <h2 class="survey__title">{{ survey.title }}</h2>

      <ul class="survey__marks">
        <li v-if="survey.is_required" class="mark mark--required">Обязательный</li>
        <li v-if="survey.is_anonymous" class="mark">Анонимный</li>
      </ul>
    </header>

    <!-- Пройден: ни бланка, ни кнопки. Второго раза не бывает, и делать вид,
         что его можно попросить, нечестно. -->
    <template v-if="isDone">
      <p class="survey__thanks">
        {{ survey.thanks ?? 'Спасибо — ответы записаны.' }}
      </p>
      <p class="faint survey__note">
        Опрос проходят один раз, поэтому изменить ответы уже нельзя.
      </p>
    </template>

    <!-- Срок вышел: бланк не показываем, но говорим, почему. -->
    <template v-else-if="!survey.is_open">
      <p class="survey__note faint">
        Опрос закрыт — срок приёма ответов вышел.
      </p>
    </template>

    <template v-else>
      <p v-if="survey.description" class="survey__intro">{{ survey.description }}</p>

      <p class="survey__note faint">
        Правильных ответов здесь нет — это опрос, а не проверка. Пройти его можно
        <b>один раз</b>.
        <template v-if="survey.is_anonymous">
          Ответы записываются без имени: видно будет, что вы его прошли, но не что ответили.
        </template>
        <template v-else-if="survey.is_required">
          Пока опрос не отправлен, {{ label }} не зачитывается.
        </template>
      </p>

      <ol class="questions">
        <li v-for="question in questions" :key="question.id" class="question">
          <p class="question__text">
            {{ question.text }}
            <span v-if="!question.is_required" class="faint question__optional">— можно пропустить</span>
          </p>

          <!-- Выбор: один вариант или несколько. -->
          <template v-if="question.type === 'single' || question.type === 'multiple'">
            <label
              v-for="option in question.options"
              :key="option.id"
              class="choice"
              :class="{ 'choice--on': isChosen(question.id, option.id) }"
            >
              <input
                :type="question.type === 'single' ? 'radio' : 'checkbox'"
                :name="`survey-question-${question.id}`"
                :checked="isChosen(question.id, option.id)"
                @change="toggle(question, option.id)"
              >
              <span>{{ option.text }}</span>
            </label>

            <input
              v-if="question.allows_other"
              :value="answer(question.id).other ?? ''"
              type="text"
              class="input survey__other"
              maxlength="500"
              placeholder="Свой вариант"
              @input="set(question.id, { other: ($event.target as HTMLInputElement).value })"
            >
          </template>

          <!-- Шкала: деления подряд, с подписями концов под ними. -->
          <template v-else-if="question.type === 'scale'">
            <div class="scale">
              <button
                v-for="step in question.scale?.steps ?? []"
                :key="step"
                type="button"
                class="scale__step"
                :class="{ 'scale__step--on': answer(question.id).scale === step }"
                @click="set(question.id, { scale: step })"
              >
                {{ step }}
              </button>
            </div>

            <p v-if="question.scale?.min_label || question.scale?.max_label" class="scale__labels faint">
              <span>{{ question.scale?.min_label }}</span>
              <span>{{ question.scale?.max_label }}</span>
            </p>
          </template>

          <!-- Своими словами: строка или абзац. -->
          <input
            v-else-if="question.type === 'text'"
            :value="answer(question.id).text ?? ''"
            type="text"
            class="input"
            maxlength="500"
            @input="set(question.id, { text: ($event.target as HTMLInputElement).value })"
          >

          <textarea
            v-else
            :value="answer(question.id).text ?? ''"
            class="textarea"
            rows="3"
            maxlength="4000"
            @input="set(question.id, { text: ($event.target as HTMLTextAreaElement).value })"
          />
        </li>
      </ol>

      <p v-if="errorMessage" class="survey__error">{{ errorMessage }}</p>

      <button
        type="button"
        class="button-primary survey__submit"
        :disabled="!canSubmit || isSubmitting"
        @click="emit('submit', answers)"
      >
        {{ isSubmitting ? 'Отправляем…' : 'Отправить ответы' }}
      </button>
    </template>
  </section>
</template>

<style scoped>
.survey {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
  padding: 1.1rem;
}

.survey__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.75rem;
  flex-wrap: wrap;
}

.survey__title {
  margin: 0;
  font-size: 1.1rem;
}

.survey__marks {
  display: flex;
  gap: 0.35rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.mark {
  padding: 0.15rem 0.55rem;
  border-radius: var(--radius-pill);
  background: var(--color-surface-sunken);
  color: var(--color-text-muted);
  font-size: 0.74rem;
}

.mark--required {
  background: var(--color-accent);
  color: var(--color-accent-text);
}

.survey__intro,
.survey__note,
.survey__thanks {
  margin: 0;
  font-size: 0.9rem;
  line-height: 1.5;
}

.survey__note {
  font-size: 0.82rem;
}

.survey__thanks {
  font-size: 1rem;
}

.questions {
  display: flex;
  flex-direction: column;
  gap: 1.1rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.question {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.question__text {
  margin: 0 0 0.15rem;
  font-size: 0.95rem;
}

.question__optional {
  font-size: 0.8rem;
}

.choice {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  padding: 0.4rem 0.6rem;
  border-radius: var(--radius-sm);
  font-size: 0.92rem;
  cursor: pointer;
}

.choice--on {
  background: var(--color-surface-sunken);
}

.survey__other {
  margin-top: 0.3rem;
}

/*
 * Деления шкалы — ряд кнопок, а не ползунок: пальцем по ползунку ставят не то
 * число, которое имели в виду, а промахнуться по кнопке нельзя.
 */
.scale {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
}

.scale__step {
  min-width: 2.4rem;
  padding: 0.45rem 0.6rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: var(--control-surface);
  color: inherit;
  font: inherit;
  font-variant-numeric: tabular-nums;
  cursor: pointer;
}

.scale__step--on {
  border-color: transparent;
  background: var(--color-accent);
  color: var(--color-accent-text);
}

.scale__labels {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  margin: 0;
  font-size: 0.78rem;
}

.survey__error {
  margin: 0;
  color: var(--color-danger);
  font-size: 0.85rem;
}

.survey__submit {
  align-self: flex-start;
}
</style>
