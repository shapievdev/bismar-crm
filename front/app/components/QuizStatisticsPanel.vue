<script setup lang="ts">
import type { QuizStatistics } from '~/types/lms'

const props = defineProps<{ lessonId: number | string }>()

const { fetchQuizStatistics } = useLmsApi()

const statistics = ref<QuizStatistics | null>(null)
const isLoading = ref(true)
const errorMessage = ref<string | null>(null)

onMounted(async () => {
  try {
    statistics.value = (await fetchQuizStatistics(props.lessonId)).data
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
</script>

<template>
  <section class="stats card">
    <h3 class="stats__title">
      Как проходят тест
    </h3>

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

            <ul class="stat-options">
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
      </template>
    </template>
  </section>
</template>

<style scoped>
.stats {
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
  margin-top: 1.5rem;
  padding: 1.2rem 1.35rem 1.35rem;
}

.stats__title {
  margin: 0;
  font-size: 1.02rem;
  font-weight: 500;
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
}
</style>
