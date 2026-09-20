<script setup lang="ts">
import type { QuizAttempt, QuizLearner, QuizReview, QuizStatistics } from '~/types/lms'

/**
 * Разбор теста для того, кто его ведёт: какие вопросы заваливают и кто как
 * прошёл.
 *
 * Откуда брать цифры, говорит тот, кто ставит панель: тест висит и на уроке, и
 * на документе, а панель об их различиях знать не должна. Тем же путём приходит
 * и разбор одной попытки — он спрашивается по нажатию, а не вместе со сводкой.
 *
 * Показывается по одному экрану за раз: доли по вопросам и список проходивших,
 * из него — попытки человека, из них — разбор одной попытки. Прежде разборы
 * раскрывались прямо в списке, и у троих проходивших с тремя попытками окно
 * превращалось в простыню ответов, через которую надо было пролистывать дальше
 * (решение пользователя 2026-09-20). Путь назад стоит первой строкой каждого
 * экрана.
 */
const props = defineProps<{
  load: () => Promise<QuizStatistics>
  loadReview: (attemptId: number) => Promise<QuizReview | null>
}>()

const statistics = ref<QuizStatistics | null>(null)
const isLoading = ref(true)
const errorMessage = ref<string | null>(null)

onMounted(async () => {
  try {
    statistics.value = await props.load()
  }
  catch {
    errorMessage.value = 'Не удалось загрузить разбор теста.'
  }
  finally {
    isLoading.value = false
  }
})

/**
 * Вопросы от самого трудного к лёгкому.
 *
 * Автор приходит сюда не читать список, а найти то, что чинить: вопрос с
 * долей верных в четверть — почти всегда признак того, что в уроке об этом
 * либо не сказано, либо сказано так, что понять нельзя.
 */
const questions = computed(() =>
  [...(statistics.value?.questions ?? [])].sort(
    (a, b) => (a.correct_share ?? 101) - (b.correct_share ?? 101),
  ),
)

/** Порог, ниже которого вопрос стоит перечитать вместе с уроком. */
const HARD = 50

function share(question: { answered: number, chosen: number }): number {
  return question.answered === 0 ? 0 : Math.round(question.chosen / question.answered * 100)
}

/**
 * Чем дело кончилось у человека — подписью в списке.
 *
 * «Сдано» вместо «сдал»: в списке стоят и сотрудницы, а форма ответа от этого
 * не зависит.
 */
function outcomeLabel(person: QuizLearner): string {
  return person.passed ? `сдано, ${person.best_score}%` : 'не сдано'
}

/* ---------- Один человек и одна его попытка ---------- */

const selected = ref<QuizLearner | null>(null)
const opened = ref<QuizAttempt | null>(null)

const review = ref<QuizReview | null>(null)
const isLoadingReview = ref(false)
const reviewError = ref<string | null>(null)

/**
 * Разбор спрашивается по одной попытке за раз и только когда его открыли:
 * попыток у одного человека бывает пять, а смотрят одну.
 */
async function openAttempt(attempt: QuizAttempt) {
  opened.value = attempt
  review.value = null
  reviewError.value = null
  isLoadingReview.value = true

  try {
    review.value = await props.loadReview(attempt.id)
  }
  catch {
    reviewError.value = 'Не удалось показать разбор попытки.'
  }
  finally {
    isLoadingReview.value = false
  }
}

function backToPeople() {
  selected.value = null
  opened.value = null
  review.value = null
}

function backToAttempts() {
  opened.value = null
  review.value = null
  reviewError.value = null
}

function when(value: string | null): string {
  return value ? new Date(value).toLocaleString('ru-RU') : ''
}
</script>

<template>
  <section class="stats">
    <p v-if="isLoading" class="stats__note">
      Считаем…
    </p>

    <p v-else-if="errorMessage" class="alert alert--danger" role="alert">
      {{ errorMessage }}
    </p>

    <template v-else-if="statistics">
      <p v-if="statistics.attempts === 0" class="stats__note">
        Тест ещё никто не проходил.
      </p>

      <!-- Разбор одной попытки. -->
      <template v-else-if="selected && opened">
        <button type="button" class="stats__back" @click="backToAttempts">
          ← Попытки: {{ selected.name }}
        </button>

        <p class="attempt-head">
          <span :class="opened.passed ? 'pass' : 'fail'">
            {{ opened.passed ? 'сдано' : 'не сдано' }}
          </span>
          <span>{{ opened.score }}%</span>
          <span class="faint">{{ when(opened.completed_at) }}</span>
        </p>

        <p v-if="reviewError" class="alert alert--danger" role="alert">
          {{ reviewError }}
        </p>

        <p v-else-if="isLoadingReview" class="stats__note">
          Загружаем разбор…
        </p>

        <!-- Работа чужая: здесь её читает не сдававший, а ведущий тест. -->
        <QuizReviewPanel v-else-if="review" :review="review" foreign />

        <p v-else class="stats__note">
          Разбор этой попытки недоступен.
        </p>
      </template>

      <!-- Попытки одного человека. -->
      <template v-else-if="selected">
        <button type="button" class="stats__back" @click="backToPeople">
          ← Все проходившие
        </button>

        <p class="person-head">
          {{ selected.name }}
          <span :class="selected.passed ? 'badge badge--success' : 'badge'">
            {{ outcomeLabel(selected) }}
          </span>
        </p>

        <ul class="rows">
          <li v-for="attempt in selected.attempts" :key="attempt.id">
            <button type="button" class="row" @click="openAttempt(attempt)">
              <span :class="attempt.passed ? 'pass' : 'fail'">
                {{ attempt.passed ? 'сдано' : 'не сдано' }}
              </span>
              <span class="row__score">{{ attempt.score }}%</span>
              <span class="faint row__when">{{ when(attempt.completed_at) }}</span>
              <span class="faint">разбор</span>
              <span class="row__chevron" aria-hidden="true">›</span>
            </button>
          </li>
        </ul>
      </template>

      <!-- Доли по вопросам и список проходивших. -->
      <template v-else>
        <p class="stats__summary">
          {{ statistics.learners }}
          {{ pluralise(statistics.learners, 'человек', 'человека', 'человек') }},
          сдали {{ statistics.passed }}.
          Средний балл с первой попытки — {{ statistics.average_first_score }}%.
          <span class="stats__note">Всего попыток: {{ statistics.attempts }}.</span>
        </p>

        <!-- Считается по первым попыткам: вторая испорчена тем, что человек
             уже видел разбор, и по ней любой вопрос выглядит лёгким. -->
        <p class="stats__note">
          Ниже — по первым попыткам каждого.
        </p>

        <ol class="stats__list">
          <li v-for="question in questions" :key="question.id" class="stat-question">
            <p class="stat-question__head">
              <span
                class="stat-question__share"
                :class="{ 'stat-question__share--hard': (question.correct_share ?? 100) < HARD }"
              >
                {{ question.correct_share === null ? '—' : `${question.correct_share}%` }}
              </span>
              <span class="stat-question__text">{{ question.text }}</span>
            </p>

            <!-- У письменного вопроса вариантов нет: вместо них средняя
                 схожесть с эталоном. Если верные по смыслу ответы стоят у
                 самой черты, дело не в людях, а в узком эталоне. -->
            <p
              v-if="question.average_similarity !== null && question.average_similarity !== undefined"
              class="stat-written"
            >
              Ответы своими словами · средняя схожесть с эталоном
              {{ Math.round(question.average_similarity * 100) }}%
              · зачтено {{ question.correct }} из {{ question.answered }}
            </p>

            <ul v-else class="stat-options">
              <li
                v-for="option in question.options"
                :key="option.id"
                class="stat-option"
                :class="{ 'stat-option--right': option.is_correct }"
              >
                <span class="stat-option__text">
                  {{ option.text }}
                  <span v-if="option.is_correct" class="stat-option__mark" aria-label="верный ответ">✓</span>
                </span>

                <span class="stat-option__bar" aria-hidden="true">
                  <span
                    class="stat-option__fill"
                    :style="{ width: `${share({ answered: question.answered, chosen: option.chosen })}%` }"
                  />
                </span>

                <span class="stat-option__count">{{ option.chosen }}</span>
              </li>
            </ul>
          </li>
        </ol>

        <!-- Доли по вопросам говорят о материале, а этот список — о людях:
             кому тест не дался, видно поимённо, и разговор с человеком ведётся
             по тому, что он отправил. Сами ответы — за нажатием: их у троих
             проходивших набирается на десяток экранов. -->
        <section v-if="statistics.people.length" class="people">
          <h4 class="people__title">
            Кто проходил
          </h4>

          <ul class="rows">
            <li v-for="person in statistics.people" :key="person.id">
              <button type="button" class="row" @click="selected = person">
                <span class="row__name">{{ person.name }}</span>
                <span :class="person.passed ? 'pass' : 'fail'">{{ outcomeLabel(person) }}</span>
                <span class="faint row__when">
                  {{ person.attempts.length }}
                  {{ pluralise(person.attempts.length, 'попытка', 'попытки', 'попыток') }}
                </span>
                <span class="row__chevron" aria-hidden="true">›</span>
              </button>
            </li>
          </ul>
        </section>
      </template>
    </template>
  </section>
</template>

<style scoped>
.stat-written {
  margin: 0.3rem 0 0 3.5rem;
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

/* Отступы и подложку даёт окно, в котором панель стоит. */
.stats {
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
}

.stats__summary {
  margin: 0;
  font-size: 0.92rem;
}

.stats__note {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

/* Путь назад — ссылкой, а не кнопкой с рамкой: он стоит над содержимым, и
   вторая рамка спорила бы с ним за внимание. */
.stats__back {
  align-self: flex-start;
  padding: 0;
  border: none;
  background: none;
  color: var(--color-text-muted);
  font: inherit;
  font-size: 0.87rem;
  cursor: pointer;
}

.stats__back:hover {
  color: var(--color-text);
}

.people {
  margin-top: 0.6rem;
  padding-top: 0.9rem;
  border-top: 1px solid var(--color-border);
}

.people__title {
  margin: 0 0 0.35rem;
  font-size: 0.93rem;
  font-weight: 500;
}

/* Строки людей и попыток устроены одинаково: имя или итог слева, подробности
   справа, стрелка у края. */
.rows {
  display: flex;
  flex-direction: column;
  margin: 0;
  padding: 0;
  list-style: none;
}

.rows > li + li {
  border-top: 1px solid var(--color-border);
}

.row {
  display: flex;
  align-items: baseline;
  gap: 0.7rem;
  width: 100%;
  padding: 0.55rem 0.5rem;
  border: none;
  border-radius: var(--radius);
  background: none;
  color: inherit;
  font: inherit;
  font-size: 0.89rem;
  text-align: left;
  cursor: pointer;
}

.row:hover {
  background: var(--color-surface-sunken);
}

.row__name {
  flex: 1;
  min-width: 0;
}

.row__score,
.row__when {
  font-variant-numeric: tabular-nums;
}

/* В строке попытки имени нет — вправо отодвигает сама дата. */
.row__when {
  margin-left: auto;
  font-size: 0.82rem;
}

.row__chevron {
  color: var(--color-text-faint);
}

.person-head,
.attempt-head {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin: 0;
  font-weight: 550;
}

.attempt-head {
  font-size: 0.92rem;
  font-variant-numeric: tabular-nums;
}

.pass { color: var(--color-success); }
.fail { color: var(--color-danger); }

.stats__list {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  margin: 0.3rem 0 0;
  padding: 0;
  list-style: none;
}

.stat-question__head {
  display: flex;
  align-items: baseline;
  gap: 0.6rem;
  margin: 0 0 0.4rem;
  font-size: 0.93rem;
}

.stat-question__share {
  flex-shrink: 0;
  min-width: 3rem;
  color: var(--color-text-muted);
  font-variant-numeric: tabular-nums;
  font-weight: 500;
}

/* Цвет здесь не единственный признак: рядом стоит само число. */
.stat-question__share--hard {
  color: var(--color-danger);
}

.stat-options {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  margin: 0;
  padding: 0 0 0 3.6rem;
  list-style: none;
}

.stat-option {
  display: grid;
  grid-template-columns: minmax(6rem, 1fr) 6rem 2rem;
  align-items: center;
  gap: 0.6rem;
  color: var(--color-text-muted);
  font-size: 0.87rem;
}

.stat-option--right {
  color: var(--color-text);
}

.stat-option__mark {
  color: var(--color-success);
}

.stat-option__bar {
  height: 0.4rem;
  border-radius: var(--radius-pill);
  background: var(--color-surface-sunken);
  overflow: hidden;
}

.stat-option__fill {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: var(--color-text-faint);
}

.stat-option--right .stat-option__fill {
  background: var(--color-success);
}

.stat-option__count {
  text-align: right;
  font-variant-numeric: tabular-nums;
}

@media (max-width: 40rem) {
  .stat-options {
    padding-left: 0;
  }

  .row__when {
    display: none;
  }
}
</style>
