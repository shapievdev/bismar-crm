<script setup lang="ts">
import type { FetchError } from 'ofetch'

definePageMeta({ middleware: 'auth', permission: 'onboarding.manage' })
useHead({ title: 'Адаптация — маршруты' })

const api = useOnboardingApi()
const router = useRouter()
const { prompt } = useAppDialog()

const { data, pending, error, refresh } = await useAsyncData('onboarding.templates', () => api.fetchTemplates())

const templates = computed(() => data.value?.data ?? [])

const generalError = ref<string | null>(null)

async function create() {
  const title = await prompt({
    title: 'Новый маршрут',
    message: 'Как назвать программу? Этапы заполните в редакторе.',
    confirmLabel: 'Создать',
  })

  if (!title) {
    return
  }

  try {
    const { data: created } = await api.createTemplate({ title })
    await router.push(`/onboarding/manage/templates/${created.slug}`)
  }
  catch {
    generalError.value = 'Не удалось создать маршрут.'
  }
}

async function duplicate(slug: string) {
  try {
    const { data: copy } = await api.duplicateTemplate(slug)
    await router.push(`/onboarding/manage/templates/${copy.slug}`)
  }
  catch (caught) {
    generalError.value = (caught as FetchError<{ message?: string }>).data?.message
      ?? 'Не удалось создать копию.'
  }
}
</script>

<template>
  <section>
    <header class="head">
      <div>
        <h1 class="page-title">
          Шаблоны адаптации
        </h1>
        <p class="page-subtitle">
          Готовые программы по дням: наложите на личный маршрут сотрудника — он получит свою копию. Новая версия сохраняет связь с программой и не меняет маршруты обучающихся.
        </p>
      </div>

      <div class="head__actions">
        <button type="button" class="button-primary" @click="create">
          Создать шаблон
        </button>
      </div>
    </header>

    <p v-if="generalError" class="alert alert--danger" role="alert">
      {{ generalError }}
    </p>

    <p v-if="error" class="alert alert--danger" role="alert">
      Не удалось загрузить список.
    </p>

    <div v-else-if="pending" class="stack">
      <div v-for="n in 2" :key="n" class="card row">
        <div class="skeleton skeleton-line" />
      </div>
    </div>

    <UiEmptyState
      v-else-if="!templates.length"
      title="Маршрутов пока нет"
      description="Создайте первый: этапы по дням, материалы, встречи, задания и тесты."
    />

    <div v-else class="stack">
      <div v-for="template in templates" :key="template.id" class="card row">
        <NuxtLink :to="`/onboarding/manage/templates/${template.slug}`" class="row__body">
          <span class="row__title">{{ template.title }} · v{{ template.version }}</span>
          <span class="faint">
            {{ template.job_title ? `${template.job_title} · ` : '' }}{{ template.store ? `${template.store} · ` : '' }}{{ template.stages_count ?? 0 }} эт. · {{ template.duration_days }} дн. ({{ template.day_mode_label.toLowerCase() }})
            <template v-if="template.journeys_count"> · стажировок: {{ template.journeys_count }}</template>
          </span>
        </NuxtLink>

        <span
          class="badge"
          :class="{ 'badge--success': template.status === 'published', 'badge--warning': template.status === 'draft' }"
        >
          {{ template.status_label }}
        </span>

        <button type="button" class="button-ghost" @click="duplicate(template.slug)">
          Новая версия
        </button>
      </div>
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
  flex-wrap: wrap;
}

.head__actions {
  display: flex;
  gap: 0.6rem;
  flex-wrap: wrap;
}

.head__actions a {
  text-decoration: none;
}

.stack {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  margin-bottom: 1.5rem;
}

.row {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1rem 1.25rem;
}

.row__body {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  min-width: 0;
  flex: 1;
  color: inherit;
  text-decoration: none;
}

.row__title {
  font-weight: 600;
}
</style>
