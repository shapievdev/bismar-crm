<script setup lang="ts">
import type { MaterialProgress, MaterialProgressPerson, QuizReview, QuizStatistics } from '~/types/lms'

/**
 * Как проходят урок или документ — кнопкой в шапке, а не двумя полотнами внизу
 * страницы.
 *
 * Под уроком лежало подряд: кто прошёл — поимённо, и разбор теста с долями по
 * каждому вопросу и списком всех проходивших. Оба разворачивались сами собой у
 * всякого администратора, открывшего урок, и читать урок приходилось поверх
 * чужой статистики (решение пользователя 2026-09-20).
 *
 * Теперь это одно окно на две вкладки: «Люди» и «Разбор теста». Вкладка вторая
 * появляется, только если проверка у материала есть, — у документа без теста
 * окно остаётся простым списком.
 *
 * Одно окно на урок и на документ: прогресс у обоих — одно событие (урок
 * закрыт, документ прочитан), и разница только в том, как его назвать. Слова
 * приходят снаружи, откуда брать цифры — тоже: окно об устройстве разделов
 * знать не должно.
 *
 * Видит его администратор, и решено это не здесь, а на маршруте
 * (EnsureAdministrator) и на странице, которая кнопку ставит.
 */
const props = defineProps<{
  load: () => Promise<MaterialProgress>
  /** «Как проходят урок» / «Кто ознакомился» — заголовок окна. */
  title: string
  /** «Прошли урок» / «Ознакомились» — чем начинается сводка над списком. */
  summaryLabel: string
  /** «Пройден» / «Ознакомлен» — отметка у имени. */
  doneLabel: string
  pendingLabel: string
  /** Разбор теста второй вкладкой. Пусто — у материала нет проверки. */
  loadQuiz?: (() => Promise<QuizStatistics>) | null
  loadQuizReview?: ((attemptId: number) => Promise<QuizReview | null>) | null
}>()

const isOpen = ref(false)

const report = ref<MaterialProgress | null>(null)
const isLoading = ref(false)
const errorMessage = ref<string | null>(null)

/**
 * Отчёт спрашивается при первом открытии окна, а не при загрузке страницы:
 * считают его по всему кругу читателей, а открывают окно далеко не на каждом
 * заходе в материал.
 */
async function open() {
  isOpen.value = true

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

  // Открытый человек не переживает закрытие окна: вернувшись, спрашивают «как
  // там у всех», а не «что там было у Петрова». По той же причине сбрасывается
  // вкладка, а разбор теста собирается заново — со своего первого экрана и со
  // свежими цифрами.
  selected.value = null
  tab.value = 'people'
  wasQuizOpened.value = false
}

const people = computed(() => report.value?.people ?? [])
const summary = computed(() => report.value?.summary ?? null)

/** Есть ли о чём говорить в колонке теста: у материала без проверки — нет. */
const hasQuiz = computed(() => people.value.some(person => person.quiz !== null))

/* ---------- Вкладки ---------- */

const tab = ref<'people' | 'quiz'>('people')

/**
 * Разбор теста, однажды открытый, остаётся собранным.
 *
 * Панель забирает свои цифры при появлении, и рисуй мы её по `v-if`, каждое
 * переключение вкладок стоило бы нового запроса. Поэтому первый показ её
 * заводит, а дальше вкладки просто прячут её и показывают.
 */
const wasQuizOpened = ref(false)

watch(tab, (value) => {
  if (value === 'quiz') {
    wasQuizOpened.value = true
  }
})

/* ---------- Один человек ---------- */

const selected = ref<MaterialProgressPerson | null>(null)

/**
 * Чем кончился тест у человека — одной строкой.
 *
 * Ожидание проверяющего названо прямо: у аттестации ноль баллов до вердикта —
 * обычное дело, и без оговорки он читался бы провалом.
 */
function quizLabel(person: MaterialProgressPerson): string {
  const quiz = person.quiz

  if (!quiz || quiz.attempts === 0) {
    return 'не проходил'
  }

  if (quiz.awaits_review) {
    return 'ждёт проверки'
  }

  const attempts = `${quiz.attempts} ${pluralise(quiz.attempts, 'попытка', 'попытки', 'попыток')}`

  return quiz.passed
    ? `сдано, ${quiz.best_score}% · ${attempts}`
    : `не сдано, ${quiz.best_score}% · ${attempts}`
}

function day(value: string | null): string {
  return value
    ? new Date(value).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' })
    : ''
}
</script>

<template>
  <div class="stats">
    <button type="button" class="button-secondary button-sm stats__open" @click="open">
      <ProgressChartIcon />
      Аналитика
    </button>

    <AppSheet
      :open="isOpen"
      wide
      :title="title"
      @close="close"
    >
      <!-- Вкладки — только когда их две: у документа без проверки вторая
           показывала бы пустоту, а строка вкладок обещала бы больше, чем есть. -->
      <nav v-if="loadQuiz" class="tabs" aria-label="Что показывать">
        <button
          type="button"
          class="tabs__item"
          :class="{ 'tabs__item--current': tab === 'people' }"
          :aria-pressed="tab === 'people'"
          @click="tab = 'people'"
        >
          Люди
        </button>
        <button
          type="button"
          class="tabs__item"
          :class="{ 'tabs__item--current': tab === 'quiz' }"
          :aria-pressed="tab === 'quiz'"
          @click="tab = 'quiz'"
        >
          Разбор теста
        </button>
      </nav>

      <template v-if="tab === 'people'">
        <p v-if="isLoading" class="faint">
          Считаем…
        </p>

        <p v-else-if="errorMessage" class="alert alert--danger" role="alert">
          {{ errorMessage }}
        </p>

        <!-- Круг людей — начавшие и те, кому материал назначен планом. Весь штат
             сюда не входит: документ открыт всякому, кто читает базу знаний. -->
        <p v-else-if="!people.length" class="faint">
          Материал пока никто не открывал, и в планах обучения его нет.
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
                <span :class="selected.is_done ? 'badge badge--success' : 'badge'">
                  {{ selected.is_done ? doneLabel : pendingLabel }}
                </span>
              </p>

              <p v-if="selected.job_title" class="faint card-person__line">
                {{ selected.job_title }}
              </p>

              <dl class="facts">
                <template v-if="selected.done_at">
                  <dt>Отметка</dt>
                  <dd>{{ day(selected.done_at) }}</dd>
                </template>

                <!-- Какую версию человек читал: у кого какая, там и спрашивать.
                     У документа без версий поля нет вовсе. -->
                <template v-if="selected.version">
                  <dt>Версия</dt>
                  <dd>«{{ selected.version }}»</dd>
                </template>

                <template v-if="selected.quiz">
                  <dt>{{ selected.quiz.is_attestation ? 'Аттестация' : 'Тест' }}</dt>
                  <dd>
                    {{ quizLabel(selected) }}
                    <template v-if="selected.quiz.last_at">
                      · последняя попытка {{ day(selected.quiz.last_at) }}
                    </template>
                  </dd>
                </template>
              </dl>
            </div>
          </div>
        </template>

        <!-- Все: сводка строкой и список. Строка целиком — кнопка, целятся в
             имя, а не в стрелку шириной в палец. -->
        <template v-else-if="summary">
          <!-- «Ознакомились 1 из 3», а не «1 из 3 человек»: счётное слово здесь
               ломается на каждом числе, а сказать надо одно — сколько из
               скольких. -->
          <p class="stats__summary">
            {{ summaryLabel }} {{ summary.done }} из {{ summary.people }}.
            <span v-if="hasQuiz && summary.passed !== undefined" class="faint">
              Сдали {{ summary.passed }} из {{ summary.attempted }} проходивших.
            </span>
          </p>

          <ul class="people">
            <li v-for="person in people" :key="person.id">
              <button type="button" class="person" @click="selected = person">
                <UserAvatar :name="person.name" :src="person.avatar_url" :size="32" />

                <span class="person__body">
                  <span class="person__name">
                    {{ person.name }}
                    <span v-if="person.in_plan" class="badge">в плане</span>
                  </span>
                  <span v-if="person.job_title" class="faint person__job">{{ person.job_title }}</span>
                </span>

                <span v-if="hasQuiz" class="person__quiz faint">
                  {{ quizLabel(person) }}
                </span>

                <span :class="person.is_done ? 'badge badge--success' : 'badge'">
                  {{ person.is_done ? doneLabel : pendingLabel }}
                </span>

                <span class="person__chevron" aria-hidden="true">›</span>
              </button>
            </li>
          </ul>
        </template>
      </template>

      <QuizStatisticsPanel
        v-if="loadQuiz && wasQuizOpened"
        v-show="tab === 'quiz'"
        :load="loadQuiz"
        :load-review="loadQuizReview ?? (async () => null)"
      />
    </AppSheet>
  </div>
</template>

<style scoped>
.stats__open {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
}

/* Вкладки — теми же таблетками, что и версии документа: это тот же выбор
   одного из нескольких, и выглядеть он должен одинаково. */
.tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
}

.tabs__item {
  padding: 0.35rem 0.8rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-pill);
  background: var(--color-surface);
  color: var(--color-text-muted);
  font: inherit;
  font-size: 0.87rem;
  cursor: pointer;
}

.tabs__item:hover {
  border-color: var(--color-border-strong);
  color: var(--color-text);
}

.tabs__item--current {
  border-color: var(--color-accent);
  background: var(--color-accent-soft);
  color: var(--color-accent);
  font-weight: 550;
}

.stats__summary {
  margin: 0;
  font-size: 0.92rem;
}

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

/* Итог теста стоит перед отметкой и в одну строку: колонка читается сверху
   вниз, а не выискивается глазами посреди имени. */
.person__quiz {
  flex-shrink: 0;
  font-size: 0.82rem;
  font-variant-numeric: tabular-nums;
}

.person__chevron {
  flex-shrink: 0;
  color: var(--color-text-faint);
}

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

.card-person__line {
  margin: 0;
  font-size: 0.85rem;
}

/* Даты и итоги — парами «что» и «сколько»: подписи слева узкой колонкой,
   значения справа, и читается это сверху вниз одним столбиком. */
.facts {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 0.25rem 0.7rem;
  margin: 0.2rem 0 0;
  font-size: 0.87rem;
}

.facts dt {
  color: var(--color-text-muted);
}

.facts dd {
  margin: 0;
}

@media (max-width: 44rem) {
  /* На телефоне итог теста переезжает под имя: в строку он не встаёт, а
     отметка нужнее у края. */
  .person {
    flex-wrap: wrap;
  }

  .person__quiz {
    margin-left: 2.7rem;
  }
}
</style>
