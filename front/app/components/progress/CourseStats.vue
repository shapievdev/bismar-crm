<script setup lang="ts">
import type { CourseProgress, CourseProgressPerson, LearnerProgress, LearnerLessonProgress } from '~/types/lms'

/**
 * Как проходят курс — кнопкой в шапке, а не полотном внизу страницы.
 *
 * Прежде отчёт лежал под курсом целиком: два десятка строк, у каждой полоса,
 * счётчики и отметка, и всё это разворачивалось само собой у всякого
 * администратора, открывшего курс. Читать его так невозможно — статистика
 * попадалась на глаза тем, кто пришёл за программой, и пряталась от тех, кто
 * пришёл именно за ней (решение пользователя 2026-09-20).
 *
 * Теперь она за кнопкой: окно, в окне список людей, по нажатию на человека —
 * его уроки. Два уровня вместо раскрывающихся строк: раскрытая строка
 * растягивала таблицу, а сравнивают людей всё равно по сводным цифрам.
 *
 * Отчёт спрашивается при первом открытии окна, а не при загрузке страницы:
 * считает его сервер по всему кругу учащихся, а открывают окно далеко не на
 * каждом заходе в курс.
 */
const props = defineProps<{
  load: () => Promise<CourseProgress>
  loadLearner: (learnerId: number) => Promise<LearnerProgress>
}>()

const isOpen = ref(false)

const report = ref<CourseProgress | null>(null)
const isLoading = ref(false)
const errorMessage = ref<string | null>(null)

async function open() {
  isOpen.value = true

  // Один раз за жизнь страницы: пока она открыта, люди в ней не продвинутся.
  if (report.value || isLoading.value) {
    return
  }

  isLoading.value = true
  errorMessage.value = null

  try {
    report.value = await props.load()
  }
  catch {
    errorMessage.value = 'Не удалось загрузить статистику прохождения.'
  }
  finally {
    isLoading.value = false
  }
}

function close() {
  isOpen.value = false

  // Открытый человек не переживает закрытие окна: вернувшись, спрашивают
  // «как там у всех», а не «что там было у Петрова».
  selected.value = null
}

const summary = computed(() => report.value?.summary ?? null)
const people = computed(() => report.value?.people ?? [])

/* ---------- Один человек ---------- */

const selected = ref<CourseProgressPerson | null>(null)
const lessons = ref<Record<number, LearnerLessonProgress[]>>({})
const isLoadingPerson = ref(false)
const personError = ref<string | null>(null)

/**
 * Уроки одного человека приходят своим запросом и по нажатию: программа,
 * помноженная на штат, — это тысячи строк ради одной открытой карточки.
 */
async function select(person: CourseProgressPerson) {
  selected.value = person
  personError.value = null

  if (lessons.value[person.id]) {
    return
  }

  isLoadingPerson.value = true

  try {
    lessons.value[person.id] = (await props.loadLearner(person.id)).lessons
  }
  catch {
    personError.value = 'Не удалось загрузить уроки этого человека.'
  }
  finally {
    isLoadingPerson.value = false
  }
}

const statusLabels: Record<CourseProgressPerson['status'], string> = {
  not_started: 'Не приступал',
  in_progress: 'Проходит',
  completed: 'Пройден',
}

/** Чем кончился тест урока у этого человека — подписью в карточке. */
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

function day(value: string | null): string {
  return value
    ? new Date(value).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' })
    : ''
}
</script>

<template>
  <div class="stats">
    <button type="button" class="button-secondary stats__open" @click="open">
      <ProgressChartIcon />
      Аналитика
    </button>

    <AppSheet
      :open="isOpen"
      wide
      title="Как проходят курс"
      @close="close"
    >
      <p v-if="isLoading" class="faint">
        Считаем…
      </p>

      <p v-else-if="errorMessage" class="alert alert--danger" role="alert">
        {{ errorMessage }}
      </p>

      <!-- Круг — записавшиеся и те, кому курс назначен планом: весь штат сюда
           не входит, иначе отчёт был бы расписанием компании. -->
      <p v-else-if="!people.length" class="faint">
        Курс пока никто не открывал, и в планах обучения его нет.
      </p>

      <!-- Один человек: путь назад стоит первой строкой, чтобы из карточки
           возвращались тем же движением, каким в неё вошли. -->
      <template v-else-if="selected">
        <button type="button" class="stats__back" @click="selected = null">
          ← Все сотрудники
        </button>

        <div class="card-person">
          <UserAvatar :name="selected.name" :src="selected.avatar_url" :size="44" />

          <div class="card-person__body">
            <p class="card-person__name">
              {{ selected.name }}
              <span v-if="selected.in_plan" class="badge">в плане</span>
              <span :class="selected.status === 'completed' ? 'badge badge--success' : 'badge'">
                {{ statusLabels[selected.status] }}
              </span>
            </p>

            <p v-if="selected.job_title" class="faint card-person__job">
              {{ selected.job_title }}
            </p>

            <UiProgressBar :value="selected.progress" size="sm" />

            <p class="faint card-person__counts">
              {{ selected.lessons_done }}/{{ selected.lessons }}
              {{ pluralise(selected.lessons, 'урок', 'урока', 'уроков') }}
              <template v-if="selected.quizzes">
                · {{ selected.quizzes_passed }}/{{ selected.quizzes }}
                {{ pluralise(selected.quizzes, 'тест', 'теста', 'тестов') }}
              </template>
              <template v-if="selected.started_at"> · начал {{ day(selected.started_at) }}</template>
              <template v-if="selected.completed_at"> · закончил {{ day(selected.completed_at) }}</template>
            </p>
          </div>
        </div>

        <p v-if="personError" class="alert alert--danger" role="alert">
          {{ personError }}
        </p>

        <p v-else-if="isLoadingPerson" class="faint">
          Смотрим…
        </p>

        <ol v-else class="lessons">
          <li v-for="lesson in lessons[selected.id] ?? []" :key="lesson.id" class="lesson">
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

          <li v-if="!(lessons[selected.id] ?? []).length" class="faint lesson__empty">
            В курсе пока нет уроков.
          </li>
        </ol>
      </template>

      <!-- Все: сводка строкой и список. Строка целиком — кнопка, целятся в имя,
           а не в стрелку шириной в палец. -->
      <template v-else-if="summary">
        <p class="stats__summary">
          {{ summary.people }} {{ pluralise(summary.people, 'человек', 'человека', 'человек') }}:
          прошли {{ summary.completed }},
          проходят {{ summary.in_progress }},
          не приступали {{ summary.not_started }}.
          <span class="faint">Средний прогресс — {{ summary.average_progress }}%.</span>
        </p>

        <ul class="people">
          <li v-for="person in people" :key="person.id">
            <button type="button" class="person" @click="select(person)">
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
              </span>

              <span :class="person.status === 'completed' ? 'badge badge--success' : 'badge'">
                {{ statusLabels[person.status] }}
              </span>

              <span class="person__chevron" aria-hidden="true">›</span>
            </button>
          </li>
        </ul>
      </template>
    </AppSheet>
  </div>
</template>

<style scoped>
.stats__open {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
}

.stats__summary {
  margin: 0;
  font-size: 0.92rem;
}

/* Путь назад — ссылкой, а не кнопкой с рамкой: он стоит над карточкой, и
   вторая рамка спорила бы с ней за внимание. */
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
  display: flex;
  flex-direction: column;
  margin: 0;
  padding: 0;
  list-style: none;
}

.people > li + li {
  border-top: 1px solid var(--color-border);
}

.person {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  width: 100%;
  padding: 0.6rem 0.5rem;
  border: none;
  border-radius: var(--radius);
  background: none;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.person:hover {
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
  overflow: hidden;
  font-size: 0.8rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.person__bar {
  flex-shrink: 0;
  width: 6rem;
}

.person__counts {
  flex-shrink: 0;
  font-size: 0.82rem;
  font-variant-numeric: tabular-nums;
}

.person__chevron {
  flex-shrink: 0;
  color: var(--color-text-faint);
}

/* Карточка человека: то же, что в строке, плюс даты и полоса во всю ширину. */
.card-person {
  display: flex;
  align-items: flex-start;
  gap: 0.8rem;
  padding: 0.9rem 1rem;
  border-radius: var(--radius);
  background: var(--color-surface-sunken);
}

.card-person__body {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  flex: 1;
  min-width: 0;
}

.card-person__name {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin: 0;
  font-weight: 550;
}

.card-person__job,
.card-person__counts {
  margin: 0;
  font-size: 0.85rem;
}

.card-person__counts {
  font-variant-numeric: tabular-nums;
}

.lessons {
  display: flex;
  flex-direction: column;
  margin: 0;
  padding: 0;
  list-style: none;
}

.lesson {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.4rem 0.5rem;
  font-size: 0.88rem;
}

.lesson + .lesson {
  border-top: 1px solid var(--color-border);
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
  border-color: var(--color-success);
  background: var(--color-success);
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
  padding: 0.4rem 0.5rem;
  font-size: 0.88rem;
}

/*
 * На телефоне из строки уходит всё, кроме имени и отметки: остальное человек
 * смотрит в карточке, ради которой строку и нажимают. А вот итог теста не
 * уходит никуда — за ним в карточку и приходят, — он переносится под название
 * урока.
 */
@media (max-width: 44rem) {
  .person__bar,
  .person__counts {
    display: none;
  }

  .lesson {
    flex-wrap: wrap;
  }

  .lesson__quiz {
    margin-left: 1.75rem;
  }
}
</style>
