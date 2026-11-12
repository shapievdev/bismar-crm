<script setup lang="ts">
definePageMeta({ middleware: 'auth', permission: 'exams.manage' })
useHead({ title: 'Ведение экзаменов' })

const { fetchAll } = useExamsApi()

const { data, pending, error } = await useAsyncData('exams.manage', () => fetchAll())

const exams = computed(() => data.value?.data ?? [])

function day(value: string | null): string {
  return value ? new Date(value).toLocaleDateString('ru-RU') : ''
}

/** Окно сдачи одной строкой: обе даты необязательны и порознь. */
function windowLabel(opensAt: string | null, closesAt: string | null): string {
  if (opensAt && closesAt) {
    return `${day(opensAt)} — ${day(closesAt)}`
  }

  if (opensAt) {
    return `с ${day(opensAt)}`
  }

  return closesAt ? `до ${day(closesAt)}` : 'бессрочно'
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
          Все, включая черновики. Сдающие видят только опубликованные.
        </p>
      </div>

      <NuxtLink to="/exams/manage/new" class="button-primary">
        Создать экзамен
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
      title="Экзаменов пока нет"
      description="Создайте первый: вопросы, окно сдачи и назначенные — всё в одном редакторе."
    />

    <div v-else class="stack">
      <NuxtLink
        v-for="exam in exams"
        :key="exam.id"
        :to="`/exams/manage/${exam.slug}`"
        class="card row"
      >
        <div class="row__body">
          <span class="row__title">{{ exam.title }}</span>
          <span class="faint">
            {{ windowLabel(exam.opens_at, exam.closes_at) }}
            <template v-if="exam.time_limit_minutes"> · {{ exam.time_limit_minutes }} мин</template>
            <template v-if="exam.assignments_count !== undefined">
              · назначено: {{ exam.assignments_count }}
            </template>
          </span>
        </div>

        <span
          class="badge"
          :class="{ 'badge--success': exam.status === 'published', 'badge--warning': exam.status === 'draft' }"
        >
          {{ exam.status_label }}
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
}
</style>
