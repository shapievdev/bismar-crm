<script setup lang="ts">
import type { AssignedExam } from '~/types/exams'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Экзамены' })

const { can } = useAuth()
const { fetchMine } = useExamsApi()

const isKeeper = computed(() => can('exams.manage'))

const { data, pending, error } = await useAsyncData('exams.mine', () => fetchMine())

const exams = computed(() => data.value?.data ?? [])

/** Срок словами: сам срок — и просрочен ли он, решает сервер. */
function dueLabel(item: AssignedExam): string | null {
  if (!item.due_at) {
    return null
  }

  return `до ${new Date(item.due_at).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' })}`
}

function stateOf(item: AssignedExam): { label: string, tone: 'success' | 'warning' | 'danger' | null } {
  const summary = item.summary

  if (!summary) {
    return { label: '', tone: null }
  }

  if (summary.passed) {
    return { label: `Сдан — ${summary.best_score}%`, tone: 'success' }
  }

  if (summary.awaiting_review) {
    return { label: 'Работа на проверке', tone: 'warning' }
  }

  if (summary.overdue) {
    return { label: 'Срок прошёл', tone: 'danger' }
  }

  if (summary.attempts_spent > 0) {
    return { label: `Не сдан — лучшая попытка ${summary.best_score}%`, tone: 'danger' }
  }

  return { label: 'Ещё не начинали', tone: null }
}
</script>

<template>
  <section>
    <header class="head">
      <div>
        <h1 class="page-title">
          Экзамены
        </h1>
        <p class="page-subtitle">
          Назначенное вам: несданное и срочное — сверху.
        </p>
      </div>

      <NuxtLink v-if="isKeeper" to="/exams/manage" class="button-secondary">
        Ведение экзаменов
      </NuxtLink>
    </header>

    <p v-if="error" class="alert alert--danger" role="alert">
      Не удалось загрузить список.
    </p>

    <div v-else-if="pending" class="stack">
      <div v-for="n in 3" :key="n" class="card row">
        <div class="skeleton skeleton-line" />
      </div>
    </div>

    <UiEmptyState
      v-else-if="!exams.length"
      title="Экзаменов нет"
      description="Когда вам назначат экзамен, он появится здесь."
    />

    <div v-else class="stack">
      <NuxtLink
        v-for="item in exams"
        :key="item.exam.id"
        :to="`/exams/${item.exam.slug}`"
        class="card row"
      >
        <div class="row__body">
          <span class="row__title">{{ item.exam.title }}</span>
          <span class="faint">
            <template v-if="dueLabel(item)">{{ dueLabel(item) }}</template>
            <template v-if="item.exam.time_limit_minutes"> · {{ item.exam.time_limit_minutes }} мин на попытку</template>
            <template v-if="item.window"> · попытка идёт</template>
          </span>
        </div>

        <span
          v-if="stateOf(item).label"
          class="badge"
          :class="{
            'badge--success': stateOf(item).tone === 'success',
            'badge--warning': stateOf(item).tone === 'warning',
            'badge--danger': stateOf(item).tone === 'danger',
          }"
        >
          {{ stateOf(item).label }}
        </span>
      </NuxtLink>
    </div>
  </section>
</template>

<style scoped>
.head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1.75rem;
}

.head a {
  text-decoration: none;
  white-space: nowrap;
}

.stack {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1rem 1.25rem;
  text-decoration: none;
  color: inherit;
}

.row__body {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  min-width: 0;
}

.row__title {
  font-weight: 600;
}

@media (max-width: 34rem) {
  .head {
    flex-direction: column;
  }

  .row {
    flex-direction: column;
    align-items: flex-start;
    gap: 0.5rem;
  }
}
</style>
