<script setup lang="ts">
import type { JourneyStage, StageItem } from '~/types/onboarding'

/**
 * Карточка этапа: что в нём лежит и что с этим делать.
 *
 * Карточка показывает и сообщает о действиях наверх — решает страница: ей
 * виден весь журнал, и после каждого действия сервер присылает его заново.
 * Тест живёт отдельным экраном (слот страницы) — здесь только его состояние.
 *
 * Закрытый этап не прячется: что внутри — читается, делать — нельзя. Так
 * видно, к чему готовиться, и день открытия не приходит неожиданностью.
 */
const props = defineProps<{
  stage: JourneyStage
  /** Проходящему — кнопки действий; наблюдателю (HR) — только состояние. */
  interactive: boolean
  /** Следующий этап — подпись внизу, когда он откроется. */
  next?: JourneyStage | null
  submittingTaskId?: number | null
  loadReview?: (attemptId: number) => Promise<import('~/types/lms').QuizReview | null>
}>()

const emit = defineEmits<{
  submitTask: [taskId: number]
  openQuiz: []
}>()

function day(value: string | null | undefined): string {
  return value
    ? new Date(value).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' })
    : ''
}

function time(value: string | null | undefined): string {
  return value
    ? new Date(value).toLocaleString('ru-RU', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' })
    : 'время уточняется'
}

/** Инициалы для кружка собеседника: фотографий у нас нет. */
function initials(name: string): string {
  return name.split(/\s+/).slice(0, 2).map(part => part[0] ?? '').join('').toUpperCase()
}

const materials = computed(() => props.stage.items.filter(item => item.kind === 'material'))
const meetings = computed(() => props.stage.items.filter(item => item.kind === 'meeting'))
const tasks = computed(() => props.stage.items.filter(item => item.kind === 'task'))
const quiz = computed(() => props.stage.items.find(item => item.kind === 'quiz') ?? null)

const isLocked = computed(() => props.stage.status === 'locked')

const doneCount = computed(() => props.stage.items.filter(item => item.done).length)

/** Подпись статуса берёт цвет из общих значков платформы. */
const STATUS_BADGES: Record<string, string> = {
  completed: 'badge--success',
  in_progress: 'badge--accent',
  available: 'badge--highlight',
  quiz_failed: 'badge--danger',
}

const statusBadge = computed(() => STATUS_BADGES[props.stage.status] ?? '')

const MATERIAL_LABELS: Record<string, string> = {
  course: 'Курс',
  document: 'Документ',
  handbook: 'Регламент',
}

function materialLabel(item: StageItem): string {
  const kind = MATERIAL_LABELS[item.meta.material_type ?? ''] ?? 'Материал'

  return item.meta.day_offset ? `${kind} · к дню ${item.meta.day_offset}` : kind
}

function quizButtonLabel(item: StageItem): string {
  if (item.meta.awaiting_review) {
    return 'Работа на проверке'
  }

  return (item.meta.attempts_spent ?? 0) > 0 ? 'Попробовать ещё раз' : 'Пройти тест'
}
</script>

<template>
  <section class="card stage">
    <header class="stage__head">
      <span class="stage__icon" :class="`stage__icon--${stage.status}`" aria-hidden="true">
        <svg v-if="stage.status === 'completed'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <path d="m4.5 12.75 6 6 9-13.5" />
        </svg>
        <svg v-else-if="isLocked" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
          <path d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
        </svg>
        <span v-else class="stage__icon-day">{{ stage.day_offset ?? stage.position }}</span>
      </span>

      <div class="stage__heading">
        <span class="stage__eyebrow">
          <template v-if="stage.day_offset">День {{ stage.day_offset }}</template>
          <template v-else>Этап {{ stage.position }}</template>
        </span>
        <h2 class="stage__title">
          {{ stage.title }}
        </h2>
      </div>

      <span class="badge" :class="statusBadge">{{ stage.status_label }}</span>
    </header>

    <ul class="meta" role="list">
      <li v-if="stage.opens_at" class="meta__item">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
          <path d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
        </svg>
        {{ isLocked ? 'Откроется' : 'Открыт' }} {{ day(stage.opens_at) }}
      </li>
      <li v-if="stage.items.length" class="meta__item">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
          <path d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
        </svg>
        {{ doneCount }} из {{ stage.items.length }} выполнено
      </li>
      <li v-if="stage.completed_at" class="meta__item meta__item--done">
        Пройден {{ day(stage.completed_at) }}
      </li>
    </ul>

    <UiProgressBar v-if="stage.status === 'in_progress'" :value="stage.progress" />

    <p v-if="isLocked" class="note" role="status">
      Этап ещё закрыт<template v-if="stage.opens_at"> — он откроется {{ day(stage.opens_at) }}</template>.
      Посмотрите, что вас ждёт, и возвращайтесь.
    </p>

    <!-- Цель дня: ради чего этот этап, своими словами. -->
    <section v-if="stage.description" class="block">
      <h3 class="block__title">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
          <path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm0-4.5a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9Zm0-3a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z" />
        </svg>
        Цель дня
      </h3>
      <p class="block__text">
        {{ stage.description }}
      </p>
    </section>

    <section v-if="stage.responsibles?.length" class="block">
      <h3 class="block__title">Ответственные</h3>
      <p v-for="person in stage.responsibles" :key="person.id">{{ person.name }}<span v-if="person.job_title" class="faint"> · {{ person.job_title }}</span></p>
    </section>
    <p v-if="stage.due_at" class="faint">Завершить до {{ day(stage.due_at) }}</p>
    <section v-if="meetings.length" class="block">
      <h3 class="block__title">
        <OnboardingItemIcon kind="meeting" />
        {{ meetings.length > 1 ? 'Встречи' : 'Встреча' }}
      </h3>

      <div v-for="item in meetings" :key="item.key" class="meeting">
        <div class="meeting__head">
          <span class="meeting__avatar" aria-hidden="true">{{ item.meta.with ? initials(item.meta.with.name) : '—' }}</span>
          <span class="meeting__who">
            <span class="meeting__name">{{ item.meta.participants?.length ? item.meta.participants.map(person => person.name).join(', ') : item.meta.with?.name ?? 'Участники уточняются' }}</span>
            <span v-if="item.meta.with?.job_title && (item.meta.participants?.length ?? 0) <= 1" class="faint">{{ item.meta.with.job_title }}</span>
          </span>
          <AchievementsMedal
            v-if="item.meta.achievement"
            :achievement="item.meta.achievement"
            size="sm"
            :muted="!item.done"
          />

          <span v-if="item.done" class="badge badge--success">Проведена</span>
        </div>

        <dl class="pairs">
          <div class="pairs__row">
            <dt>Тема</dt>
            <dd>{{ item.title }}</dd>
          </div>
          <div class="pairs__row">
            <dt>Формат</dt>
            <dd>
              {{ item.meta.format_label }}<template v-if="item.meta.place"> · {{ item.meta.place }}</template>
            </dd>
          </div>
          <div class="pairs__row">
            <dt>Когда</dt>
            <dd>{{ time(item.meta.scheduled_at) }}</dd>
          </div>
        </dl>

        <p v-if="!item.done && (item.meta.reschedules_count ?? 0) > 0" class="faint small">
          Встречу переносили — это не неудача, просто новая дата.
        </p>
        <p v-else-if="!item.done" class="faint small">
          Отметит проведённой тот, с кем вы встречаетесь.
        </p>
      </div>
    </section>

    <section v-if="materials.length" class="block">
      <h3 class="block__title">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
          <path d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
        </svg>
        Учебные материалы
      </h3>

      <ul class="rows" role="list">
        <li v-for="item in materials" :key="item.key" class="rows__item">
          <span class="rows__icon" :class="{ 'rows__icon--done': item.done }" aria-hidden="true">
            <OnboardingItemIcon :kind="item.meta.material_type ?? 'material'" />
          </span>

          <span class="rows__body">
            <NuxtLink v-if="item.url && !isLocked" :to="item.url" class="rows__link">
              {{ item.title }}
            </NuxtLink>
            <span v-else class="rows__name">{{ item.title }}</span>
            <span class="rows__sub">
              {{ materialLabel(item) }}<template v-if="!item.is_required"> · по желанию</template>
            </span>
          </span>

          <AchievementsMedal
            v-if="item.meta.achievement"
            :achievement="item.meta.achievement"
            size="sm"
            :muted="!item.done"
          />

          <span v-if="item.done" class="badge badge--success">Изучен</span>
          <span v-else-if="isLocked" class="tag">Недоступен</span>
          <span v-else class="tag">Не изучен</span>
        </li>
      </ul>
    </section>

    <section v-if="tasks.length" class="block">
      <h3 class="block__title">
        <OnboardingItemIcon kind="task" />
        Задания
      </h3>

      <ul class="rows" role="list">
        <li v-for="item in tasks" :key="item.key" class="rows__item">
          <span class="rows__mark" :class="{ 'rows__mark--done': item.done }" aria-hidden="true">
            <svg v-if="item.done" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
              <path d="m4.5 12.75 6 6 9-13.5" />
            </svg>
          </span>

          <span class="rows__body">
            <span class="rows__name">{{ item.title }}</span>
            <span class="rows__sub">
              {{ item.meta.confirm_by === 'self' ? 'Отмечаете сами' : 'Подтверждает ответственный' }}
              <template v-if="item.meta.confirm_by === 'responsible' && item.meta.responsibles?.length">: {{ item.meta.responsibles.map(person => person.name).join(', ') }}</template>
            </span>
          </span>

          <AchievementsMedal
            v-if="item.meta.achievement"
            :achievement="item.meta.achievement"
            size="sm"
            :muted="!item.done"
          />

          <span v-if="item.done" class="badge badge--success">Сделано</span>
          <template v-else-if="interactive && !isLocked">
            <span v-if="item.meta.awaiting_confirmation" class="badge badge--warning">Ждёт подтверждения</span>
            <button
              v-else
              type="button"
              class="button-secondary"
              :disabled="submittingTaskId === item.meta.id"
              @click="emit('submitTask', item.meta.id!)"
            >
              {{ item.meta.confirm_by === 'self' ? 'Сделано' : 'Отметить' }}
            </button>
          </template>
        </li>
      </ul>
    </section>

    <section v-if="quiz" class="block">
      <h3 class="block__title">
        <OnboardingItemIcon kind="quiz" />
        {{ stage.is_final_exam ? 'Итоговый экзамен' : 'Тест этапа' }}
      </h3>

      <div class="quiz">
        <div class="quiz__body">
          <span class="rows__name">{{ quiz.title }}</span>
          <span class="rows__sub">
            Попытки: {{ quiz.meta.attempts_spent ?? 0 }}<template v-if="quiz.meta.attempts_allowed != null"> из {{ quiz.meta.attempts_allowed }}</template>
            <template v-if="quiz.meta.best_score != null"> · ваш лучший балл {{ quiz.meta.best_score }}%</template>
            <template v-if="quiz.meta.best_score_others != null"> · рекорд прошлых участников {{ quiz.meta.best_score_others }}%</template>
          </span>
          <span v-if="stage.status === 'quiz_failed' && quiz.meta.attempts_remaining === 0" class="faint small">
            Попытки кончились — ничего страшного: повторите материал и попросите наставника открыть ещё одну.
          </span>
        </div>

        <AchievementsMedal
          v-if="quiz.meta.achievement"
          :achievement="quiz.meta.achievement"
          size="sm"
          :muted="!quiz.done"
        />

        <span v-if="quiz.done" class="badge badge--success">Сдан</span>
        <span v-else-if="isLocked" class="tag">Недоступен</span>
        <button
          v-else-if="interactive && quiz.meta.attempts_remaining !== 0"
          type="button"
          class="button-primary"
          :disabled="Boolean(quiz.meta.awaiting_review)"
          @click="emit('openQuiz')"
        >
          {{ quizButtonLabel(quiz) }}
        </button>
      </div>
      <QuizAttemptsHistory :attempts="quiz.meta.attempts ?? []" :load-review="loadReview" label="История попыток" />
    </section>

    <!-- Что дальше: день открытия следующего этапа, чтобы не гадать. -->
    <p v-if="next" class="next">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
        <path d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
      </svg>
      <span>
        Следующий этап — «{{ next.title }}»<template v-if="next.opens_at">, откроется {{ day(next.opens_at) }}</template>.
        <span class="faint">Мы напомним о начале.</span>
      </span>
    </p>
  </section>
</template>

<style scoped>
.stage {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
  padding: 1.5rem;
}

.stage__head {
  display: flex;
  align-items: flex-start;
  gap: 0.9rem;
}

.stage__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 3rem;
  height: 3rem;
  border-radius: var(--radius);
  background: var(--color-accent);
  color: var(--color-accent-text);
}

.stage__icon svg {
  width: 1.4rem;
  height: 1.4rem;
}

.stage__icon-day {
  font-size: 1.15rem;
  font-weight: 700;
}

.stage__icon--completed {
  background: var(--color-success);
  color: #fff;
}

.stage__icon--locked {
  background: var(--color-surface-sunken);
  color: var(--color-text-faint);
}

.stage__icon--quiz_failed {
  background: var(--color-danger);
  color: #fff;
}

.stage__heading {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  min-width: 0;
  flex: 1;
}

.stage__eyebrow {
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-text-faint);
}

.stage__title {
  margin: 0;
  font-size: 1.25rem;
  line-height: 1.25;
}

/* Полоска фактов под заголовком: дата, счётчик, отметка о прохождении. */
.meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem 1.1rem;
  margin: 0;
  padding: 0;
  list-style: none;
  font-size: 0.85rem;
  color: var(--color-text-muted);
}

.meta__item {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
}

.meta__item svg {
  width: 1rem;
  height: 1rem;
  color: var(--color-text-faint);
}

.meta__item--done {
  color: var(--color-success);
}

.note {
  margin: 0;
  padding: 0.75rem 0.9rem;
  border-radius: var(--radius-sm);
  background: var(--control-surface);
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.block {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.block__title {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  margin: 0;
  font-size: 0.8rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-text-faint);
}

.block__title svg {
  width: 1.05rem;
  height: 1.05rem;
}

.block__text {
  margin: 0;
  color: var(--color-text-muted);
}

/* ---------- Встреча ---------- */

.meeting {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 1rem;
  background: var(--control-surface);
  border-radius: var(--radius);
}

.meeting__head {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.meeting__avatar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 999px;
  background: var(--color-highlight);
  color: var(--color-highlight-text);
  font-size: 0.85rem;
  font-weight: 700;
}

.meeting__who {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1;
}

.meeting__name {
  font-weight: 600;
}

.pairs {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  margin: 0;
  font-size: 0.9rem;
}

.pairs__row {
  display: flex;
  gap: 0.6rem;
}

.pairs__row dt {
  flex: none;
  width: 4.5rem;
  color: var(--color-text-faint);
}

.pairs__row dd {
  margin: 0;
  min-width: 0;
}

/* ---------- Строки материалов и заданий ---------- */

.rows {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.rows__item {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.6rem 0.75rem;
  background: var(--control-surface);
  border-radius: var(--radius-sm);
}

.rows__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 2rem;
  height: 2rem;
  border-radius: var(--radius-sm);
  background: var(--color-surface);
  color: var(--color-text-muted);
}

.rows__icon svg {
  width: 1.05rem;
  height: 1.05rem;
}

.rows__icon--done {
  background: var(--color-success-soft);
  color: var(--color-success);
}

.rows__mark {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 1.4rem;
  height: 1.4rem;
  border-radius: 999px;
  border: 2px solid var(--color-border-strong);
  color: transparent;
}

.rows__mark svg {
  width: 0.7rem;
  height: 0.7rem;
}

.rows__mark--done {
  border-color: var(--color-success);
  background: var(--color-success);
  color: #fff;
}

.rows__body {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1;
}

.rows__name,
.rows__link {
  font-weight: 500;
}

.rows__link {
  color: inherit;
}

.rows__sub {
  font-size: 0.8rem;
  color: var(--color-text-faint);
}

.small {
  font-size: 0.82rem;
}

/*
 * Нейтральная отметка и кнопка на плашке строки: у плашки и у обычного значка
 * подложка одна, поэтому здесь они поднимаются на ступень выше — иначе на
 * красной палитре и то и другое сливается со строкой.
 */
.tag {
  padding: 0.2rem 0.65rem;
  border-radius: var(--radius-pill);
  background: var(--color-surface);
  color: var(--color-text-faint);
  font-size: 0.78rem;
  white-space: nowrap;
}

.rows__item .button-secondary,
.quiz .button-secondary {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
}

/* ---------- Тест ---------- */

.quiz {
  display: flex;
  align-items: center;
  gap: 1rem;
  flex-wrap: wrap;
  padding: 0.85rem 1rem;
  background: var(--control-surface);
  border-radius: var(--radius-sm);
}

.quiz__body {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1;
}

/* ---------- Что дальше ---------- */

.next {
  display: flex;
  align-items: flex-start;
  gap: 0.6rem;
  margin: 0;
  padding: 0.85rem 1rem;
  border-radius: var(--radius-sm);
  background: var(--control-surface);
  color: var(--color-text-muted);
  font-size: 0.88rem;
}

.next svg {
  flex: none;
  width: 1.1rem;
  height: 1.1rem;
  margin-top: 0.1rem;
  color: var(--color-accent);
}
</style>
