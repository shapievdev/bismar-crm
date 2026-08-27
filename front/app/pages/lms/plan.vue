<script setup lang="ts">
definePageMeta({ middleware: 'auth', permission: 'courses.view' })
useHead({ title: 'Мой план' })

const { myPlan } = useLmsApi()

/** Куда ведёт шаг: у курса и регламента разные адреса. */
function href(step: { kind: string, slug: string | null }): string {
  return step.kind === 'regulation'
    ? `/lms/regulations/${step.slug}`
    : `/lms/${step.slug}`
}

const { data, pending, error } = await useAsyncData('lms.my-plan', () => myPlan())

const steps = computed(() => data.value?.data ?? [])
const done = computed(() => steps.value.filter(step => step.is_completed).length)

/**
 * Шаг, к которому стоит вернуться: первый непройденный.
 *
 * Порядок здесь — совет, а не запрет: открыть можно любой шаг, и потому это
 * подсказка «продолжить отсюда», а не единственная доступная дверь.
 */
const current = computed(() => steps.value.find(step => !step.is_completed) ?? null)
</script>

<template>
  <section>
    <header class="head">
      <div>
        <h1 class="page-title">
          Мой план
        </h1>
        <p class="page-subtitle">
          Курсы и регламенты, которые вам назначили, в том порядке, в каком их стоит пройти.
        </p>
      </div>

      <span v-if="steps.length" class="badge">{{ done }} из {{ steps.length }}</span>
    </header>

    <p v-if="error" class="alert alert--danger" role="alert">
      Не удалось загрузить план.
    </p>

    <div v-else-if="pending" class="stack">
      <div v-for="n in 3" :key="n" class="card row">
        <div class="skeleton skeleton-line" />
      </div>
    </div>

    <UiEmptyState
      v-else-if="!steps.length"
      title="Плана пока нет"
      description="Когда вам что-нибудь назначат, оно появится здесь по порядку. Пока можно выбрать материал самому."
    >
      <NuxtLink to="/lms" class="button-primary">
        Открыть каталог
      </NuxtLink>
    </UiEmptyState>

    <ol v-else class="stack plan">
      <li v-for="step in steps" :key="step.id">
        <NuxtLink
          :to="href(step)"
          class="card row"
          :class="{ 'row--done': step.is_completed }"
        >
          <!-- Номер шага, а не значок прогресса: план читают как очередь, и
               «третий» здесь говорит больше, чем «45%». -->
          <span class="step" :class="{ 'step--done': step.is_completed }">
            <svg
              v-if="step.is_completed"
              viewBox="0 0 24 24"
              width="15"
              height="15"
              fill="none"
              stroke="currentColor"
              stroke-width="2.4"
              stroke-linecap="round"
              stroke-linejoin="round"
              aria-hidden="true"
            >
              <path d="m5 13 4.5 4.5L19 7" />
            </svg>
            <template v-else>{{ step.position }}</template>
          </span>

          <div class="row__body">
            <span class="row__title">{{ step.title }}</span>
            <span class="faint">
              <!-- У регламента доли нет: он либо прочитан, либо нет. -->
              <template v-if="step.kind === 'regulation'">
                Регламент — {{ step.is_completed ? 'ознакомлен' : 'нужно прочитать' }}
              </template>
              <template v-else-if="step.is_completed">Пройден</template>
              <template v-else-if="step.is_started">Пройдено {{ step.progress }}%</template>
              <template v-else>Ещё не начат</template>
            </span>
          </div>

          <UiProgressRing
            v-if="step.kind === 'course' && step.is_started && !step.is_completed"
            :value="step.progress"
            :size="40"
          />

          <span v-if="current && current.id === step.id" class="button-primary button-sm">
            {{ step.kind === 'regulation' ? 'Прочитать' : (step.is_started ? 'Продолжить' : 'Начать') }}
          </span>
          <span v-else-if="!step.is_completed" class="button-secondary button-sm">Открыть</span>
        </NuxtLink>
      </li>
    </ol>
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

.stack {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

/* Нумерует шаги сама разметка, а не список: номер шага приходит с сервера и
   переживает то, что часть плана читателю не показали. */
.plan {
  margin: 0;
  padding: 0;
  list-style: none;
}

.row {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0.9rem 1.1rem;
  color: inherit;
  text-decoration: none;
  transition: box-shadow 0.15s ease;
}

.row:hover {
  box-shadow: var(--shadow-md);
}

/* Пройденное не выключено — к нему возвращаются, — но и внимания не просит. */
.row--done .row__title {
  color: var(--color-text-muted);
}

.row__body {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-width: 0;
  gap: 0.1rem;
  font-size: 0.9rem;
}

.row__title {
  font-weight: 550;
}

.step {
  display: inline-flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  width: 2rem;
  height: 2rem;
  border-radius: 50%;
  background: var(--color-surface-sunken);
  color: var(--color-text-muted);
  font-size: 0.85rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.step--done {
  background: var(--color-success-soft);
  color: var(--color-success);
}

.skeleton-line {
  width: 100%;
  height: 1.5rem;
}

@media (prefers-reduced-motion: reduce) {
  .row { transition: none; }
}
</style>
