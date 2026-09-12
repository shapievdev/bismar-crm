<script setup lang="ts">
import type { CourseProgress, CourseProgressPerson, LearnerProgress, LearnerLessonProgress } from '~/types/lms'

/**
 * Кто проходит курс и как далеко ушёл.
 *
 * Внизу самой страницы курса, а не в разделе аналитики: там отчёт отвечает
 * «сколько всего и в среднем по компании», а здесь спрашивают о курсе, который
 * открыт прямо сейчас, — и о людях поимённо.
 *
 * Уроки одного человека приходят по раскрытию строки и своим адресом:
 * программа, помноженная на штат, — это тысячи строк ради одной раскрытой.
 */
const props = defineProps<{
  load: () => Promise<CourseProgress>
  loadLearner: (learnerId: number) => Promise<LearnerProgress>
}>()

const report = ref<CourseProgress | null>(null)
const isLoading = ref(true)
const errorMessage = ref<string | null>(null)

onMounted(async () => {
  try {
    report.value = await props.load()
  }
  catch {
    errorMessage.value = 'Не удалось загрузить статистику прохождения.'
  }
  finally {
    isLoading.value = false
  }
})

const summary = computed(() => report.value?.summary ?? null)
const people = computed(() => report.value?.people ?? [])

/* ---------- Раскрытая строка ---------- */

const openId = ref<number | null>(null)
const lessons = ref<Record<number, LearnerLessonProgress[]>>({})
const loadingId = ref<number | null>(null)
const failedId = ref<number | null>(null)

/**
 * Раскрытая строка одна: две открытые растягивают таблицу на экран, а
 * сравнивают людей всё равно по сводным цифрам в самой строке.
 */
async function toggle(person: CourseProgressPerson) {
  if (openId.value === person.id) {
    openId.value = null

    return
  }

  openId.value = person.id
  failedId.value = null

  // Уже спрошенное не спрашивается снова: за время, пока страница открыта,
  // человек в ней не продвинется.
  if (lessons.value[person.id]) {
    return
  }

  loadingId.value = person.id

  try {
    lessons.value[person.id] = (await props.loadLearner(person.id)).lessons
  }
  catch {
    failedId.value = person.id
    openId.value = null
  }
  finally {
    loadingId.value = null
  }
}

const statusLabels: Record<CourseProgressPerson['status'], string> = {
  not_started: 'Не приступал',
  in_progress: 'Проходит',
  completed: 'Пройден',
}

/** Чем кончился тест урока у этого человека — подписью в раскрытой строке. */
function quizLabel(lesson: LearnerLessonProgress): string {
  const quiz = lesson.quiz

  if (!quiz) {
    return ''
  }

  if (quiz.attempts === 0) {
    return 'тест не проходил'
  }

  if (quiz.awaits_review) {
    return 'работа ждёт проверки'
  }

  const attempts = `${quiz.attempts} ${pluralise(quiz.attempts, 'попытка', 'попытки', 'попыток')}`

  return `${quiz.passed ? 'тест сдан' : 'тест не сдан'}, ${quiz.best_score}% · ${attempts}`
}
</script>

<template>
  <section class="progress card">
    <h2 class="progress__title">
      Как проходят курс
    </h2>

    <p v-if="isLoading" class="progress__note">
      Считаем…
    </p>

    <p v-else-if="errorMessage" class="alert alert--danger" role="alert">
      {{ errorMessage }}
    </p>

    <template v-else-if="summary">
      <!-- Круг — записавшиеся и те, кому курс назначен планом: весь штат сюда
           не входит, иначе отчёт был бы расписанием компании. -->
      <p v-if="!people.length" class="progress__note">
        Курс пока никто не открывал, и в планах обучения его нет.
      </p>

      <template v-else>
        <p class="progress__summary">
          {{ summary.people }} {{ pluralise(summary.people, 'человек', 'человека', 'человек') }}:
          прошли {{ summary.completed }},
          проходят {{ summary.in_progress }},
          не приступали {{ summary.not_started }}.
          <span class="progress__note">Средний прогресс — {{ summary.average_progress }}%.</span>
        </p>

        <ul class="people">
          <li v-for="person in people" :key="person.id" class="person">
            <button
              type="button"
              class="person__row"
              :aria-expanded="openId === person.id"
              @click="toggle(person)"
            >
              <UserAvatar :name="person.name" :src="person.avatar_url" :size="32" />

              <span class="person__body">
                <span class="person__name">
                  {{ person.name }}
                  <span v-if="person.in_plan" class="badge">в плане</span>
                </span>
                <span v-if="person.job_title" class="faint person__job">{{ person.job_title }}</span>
              </span>

              <span class="person__bar">
                <UiProgressBar :value="person.progress" size="sm" />
              </span>

              <span class="person__counts faint">
                {{ person.lessons_done }}/{{ person.lessons }}
                {{ pluralise(person.lessons, 'урок', 'урока', 'уроков') }}
                <template v-if="person.quizzes">
                  · {{ person.quizzes_passed }}/{{ person.quizzes }}
                  {{ pluralise(person.quizzes, 'тест', 'теста', 'тестов') }}
                </template>
              </span>

              <span
                class="person__status"
                :class="person.status === 'completed' ? 'badge badge--success' : 'badge'"
              >
                {{ statusLabels[person.status] }}
              </span>

              <span class="person__chevron" aria-hidden="true">
                {{ openId === person.id ? '▴' : '▾' }}
              </span>
            </button>

            <p v-if="failedId === person.id" class="alert alert--danger" role="alert">
              Не удалось загрузить уроки этого человека.
            </p>

            <p v-else-if="loadingId === person.id" class="progress__note person__loading">
              Смотрим…
            </p>

            <ol v-else-if="openId === person.id" class="lessons">
              <li
                v-for="lesson in lessons[person.id] ?? []"
                :key="lesson.id"
                class="lesson"
              >
                <span class="lesson__check" :class="{ 'lesson__check--done': lesson.is_done }">
                  <template v-if="lesson.is_done">✓</template>
                </span>

                <span class="lesson__body">
                  <span class="lesson__title">{{ lesson.title }}</span>
                  <span class="faint lesson__module">{{ lesson.module }}</span>
                </span>

                <span
                  v-if="lesson.quiz"
                  class="lesson__quiz faint"
                  :class="{ 'lesson__quiz--failed': lesson.quiz.attempts > 0 && !lesson.quiz.passed && !lesson.quiz.awaits_review }"
                >
                  {{ quizLabel(lesson) }}
                </span>
              </li>

              <li v-if="!(lessons[person.id] ?? []).length" class="faint lesson__empty">
                В курсе пока нет уроков.
              </li>
            </ol>
          </li>
        </ul>
      </template>
    </template>
  </section>
</template>

<style scoped>
.progress {
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
  margin-top: 1.25rem;
  padding: 1.2rem 1.35rem 1.35rem;
}

.progress__title {
  margin: 0;
  font-size: 1.1rem;
  font-weight: 600;
}

.progress__summary {
  margin: 0;
  font-size: 0.92rem;
}

.progress__note {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.people {
  display: flex;
  flex-direction: column;
  margin: 0.2rem 0 0;
  padding: 0;
  list-style: none;
}

.person {
  border-top: 1px solid var(--color-border-subtle, var(--color-border));
}

.person:first-child {
  border-top: none;
}

/* Строка целиком — кнопка: раскрывают её, целясь в имя, а не в стрелку
   шириной в палец. */
.person__row {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  width: 100%;
  padding: 0.6rem 0.4rem;
  border: none;
  border-radius: var(--radius);
  background: none;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.person__row:hover {
  background: var(--color-surface-sunken);
}

.person__body {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-width: 0;
}

.person__name {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.92rem;
}

.person__job {
  font-size: 0.8rem;
}

.person__bar {
  flex-shrink: 0;
  width: 7rem;
}

.person__counts {
  flex-shrink: 0;
  font-size: 0.82rem;
  font-variant-numeric: tabular-nums;
}

.person__status,
.person__chevron {
  flex-shrink: 0;
}

.person__chevron {
  color: var(--color-text-faint);
  font-size: 0.75rem;
}

.person__loading {
  padding: 0 0 0.6rem 3.1rem;
}

.lessons {
  margin: 0 0 0.6rem;
  padding: 0 0 0 3.1rem;
  list-style: none;
}

.lesson {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.35rem 0;
  font-size: 0.88rem;
}

.lesson__check {
  display: grid;
  place-items: center;
  width: 1.15rem;
  height: 1.15rem;
  flex-shrink: 0;
  border: 1.5px solid var(--color-border-strong);
  border-radius: var(--radius-pill);
  font-size: 0.65rem;
}

.lesson__check--done {
  background: var(--color-success);
  border-color: var(--color-success);
  color: #fff;
}

.lesson__body {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-width: 0;
}

.lesson__module {
  font-size: 0.78rem;
}

.lesson__quiz {
  flex-shrink: 0;
  font-size: 0.82rem;
  font-variant-numeric: tabular-nums;
}

/* Цвет здесь не единственный признак: рядом стоят слова «тест не сдан». */
.lesson__quiz--failed {
  color: var(--color-danger);
}

.lesson__empty {
  padding: 0.35rem 0;
  font-size: 0.88rem;
}

@media (max-width: 56rem) {
  /* Полоса прогресса уходит первой: доля названа числами рядом. */
  .person__bar {
    display: none;
  }
}

@media (max-width: 44rem) {
  .person__row {
    flex-wrap: wrap;
  }

  .person__body {
    flex-basis: calc(100% - 5rem);
  }

  .person__counts {
    margin-left: 2.7rem;
  }

  .lessons,
  .person__loading {
    padding-left: 1rem;
  }
}
</style>
