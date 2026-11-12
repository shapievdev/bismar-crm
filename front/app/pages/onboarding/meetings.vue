<script setup lang="ts">
import type { FetchError } from 'ofetch'
import type { HostedMeeting } from '~/types/onboarding'

/**
 * Встречи, которые проводит вошедший: его очередь подтверждений.
 *
 * Отдельным экраном, потому что это не его маршрут, а чужие: наставнику с
 * поручениями раздел виден без единого права, и значок на рельсе считает
 * ровно эти строки. Подтверждение здесь закрывает требование этапа у того,
 * с кем встречались, — и может закрыть сам этап.
 */
definePageMeta({ middleware: 'auth' })
useHead({ title: 'Мои поручения' })

const api = useOnboardingApi()
const { refreshBadges } = useNavigation()

const { data, pending, refresh } = await useAsyncData('onboarding.meetings.mine', () => api.fetchMyMeetings())

const { data: taskData, refresh: refreshTasks } = await useAsyncData('onboarding.tasks.mine', () => api.fetchMyTasks())
const route = useRoute()
const { data: assignedData, refresh: refreshAssigned } = await useAsyncData('onboarding.assigned', () => api.fetchAssignedStages())
const assigned = computed(() => assignedData.value?.data ?? [])
const selectedAssigned = ref(Number(route.query.stage) || null)
const tasks = computed(() => taskData.value?.data ?? [])
const confirmingTask = ref<number | null>(null)
async function confirmTask(id: number) {
  confirmingTask.value = id
  try { await api.confirmTask(id); await refreshTasks(); await refreshAssigned() } catch { actionError.value = 'Не удалось подтвердить задание.' } finally { confirmingTask.value = null }
}

const meetings = computed<HostedMeeting[]>(() => data.value?.data ?? [])

const confirmingId = ref<number | null>(null)
const actionError = ref<string | null>(null)

async function confirm(meeting: HostedMeeting) {
  confirmingId.value = meeting.id
  actionError.value = null

  try {
    await api.confirmMeeting(meeting.id)
    await refresh()
    await refreshAssigned()
    // Значок на рельсе считает эти же строки — обновим, пока человек здесь.
    await refreshBadges()
  }
  catch (caught) {
    actionError.value = (caught as FetchError<{ message?: string }>).data?.message
      ?? 'Не удалось отметить встречу проведённой.'
  }
  finally {
    confirmingId.value = null
  }
}

/** Инициалы: фотографий у нас нет. */
function initials(name: string): string {
  return name.split(/\s+/).slice(0, 2).map(part => part[0] ?? '').join('').toUpperCase()
}

function when(value: string | null): string {
  return value
    ? new Date(value).toLocaleString('ru-RU', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' })
    : 'время уточняется'
}
</script>

<template>
  <section>
    <header class="head">
      <h1 class="page-title">
        Мои поручения
      </h1>
      <p class="page-subtitle">
        Встречи по адаптации, которые проводите вы. Отметьте проведённую — и этап у сотрудника пойдёт дальше.
      </p>
    </header>

    <p v-if="actionError" class="alert alert--danger" role="alert">
      {{ actionError }}
    </p>

    <div v-if="pending" class="card">
      <div class="skeleton skeleton-line" />
    </div>

    <UiEmptyState
      v-else-if="meetings.length === 0"
      title="Встреч нет"
      description="Когда вас назначат провести встречу по адаптации, она появится здесь."
    />

    <ul v-else class="list" role="list">
      <li v-for="meeting in meetings" :key="meeting.id" class="card row">
        <span class="row__avatar" aria-hidden="true">{{ initials(meeting.employee.name) }}</span>

        <div class="row__body">
          <span class="row__name">{{ meeting.employee.name }}</span>
          <span class="row__topic">{{ meeting.topic }}</span>
          <span class="faint small">
            {{ when(meeting.scheduled_at) }} · {{ meeting.format_label }}<template v-if="meeting.place"> · {{ meeting.place }}</template>
          </span>
        </div>

        <button
          type="button"
          class="button-primary"
          :disabled="confirmingId === meeting.id"
          @click="confirm(meeting)"
        >
          {{ confirmingId === meeting.id ? 'Отмечаем…' : 'Провели' }}
        </button>
      </li>
    </ul>
    <h2>Назначенные вам этапы</h2>
    <details v-for="entry in assigned" :key="entry.stage.id" :open="entry.stage.id === selectedAssigned" class="card row">
      <summary>{{ entry.employee.name }} · {{ entry.stage.title }} · {{ entry.stage.status_label }}</summary>
      <OnboardingStageCard :stage="entry.stage" :interactive="false" :load-review="async id => (await api.fetchAssignedAttempt(id)).data.review ?? null" />
    </details>
    <h2>Задания подопечных</h2>
    <p v-if="!tasks.length" class="faint">Заданий на подтверждение нет.</p>
    <ul class="list"><li v-for="task in tasks" :key="task.id" class="card row"><div class="row__body"><strong>{{ task.employee.name }}</strong><span>{{ task.text }}</span><span class="faint">{{ task.submitted_at ? 'Стажёр отметил выполнение' : 'Ожидает выполнения' }}</span></div><button class="button-primary" type="button" :disabled="confirmingTask === task.id" @click="confirmTask(task.id)">Подтвердить</button></li></ul>
  </section>
</template>

<style scoped>
.head {
  margin-bottom: 1.25rem;
}

.list {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.row {
  display: flex;
  align-items: center;
  gap: 1rem;
  flex-wrap: wrap;
  padding: 1rem 1.25rem;
}

.row__avatar {
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

.row__body {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1;
}

.row__name {
  font-weight: 600;
}

.row__topic {
  color: var(--color-text-muted);
}

.small {
  font-size: 0.82rem;
}
</style>
