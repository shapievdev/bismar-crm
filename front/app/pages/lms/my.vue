<script setup lang="ts">
definePageMeta({ middleware: 'auth', permission: 'courses.view' })
useHead({ title: 'Мои курсы' })

const { myCourses } = useLmsApi()

/*
 * Курс могли начать до того, как назначили план: тогда он ждёт своей очереди
 * наравне с остальными. Строка такого курса никуда не ведёт и отвечает на
 * нажатие тем же окном, что и каталог.
 */
const { explain: explainLock } = usePlanLock()
const link = resolveComponent('NuxtLink')

const { data, pending, error } = await useAsyncData('lms.my-courses', () => myCourses())

const enrollments = computed(() => data.value?.data ?? [])
const active = computed(() => enrollments.value.filter(item => !item.is_completed))
const finished = computed(() => enrollments.value.filter(item => item.is_completed))
</script>

<template>
  <section>
    <header class="head">
      <div>
        <h1 class="page-title">
          Мои курсы
        </h1>
        <p class="page-subtitle">
          Всё, на что вы записаны, с текущим прогрессом.
        </p>

        <!-- Свои числа живут здесь, а не в каталоге: «в работе» и «пройдено» —
             про читателя, а каталог — про то, что есть в компании. -->
        <p v-if="enrollments.length" class="faint counted">
          В работе — {{ active.length }} · Пройдено — {{ finished.length }}
        </p>
      </div>
    </header>

    <p v-if="error" class="alert alert--danger" role="alert">
      Не удалось загрузить список.
    </p>

    <div v-else-if="pending" class="stack">
      <div v-for="n in 2" :key="n" class="card row">
        <div class="skeleton skeleton-line" />
      </div>
    </div>

    <UiEmptyState
      v-else-if="!enrollments.length"
      title="Вы пока не записаны ни на один курс"
      description="Откройте каталог и выберите подходящий."
    >
      <NuxtLink to="/lms" class="button-primary">
        Открыть каталог
      </NuxtLink>
    </UiEmptyState>

    <template v-else>
      <template v-if="active.length">
        <h2 class="group-title">
          В процессе
        </h2>
        <div class="stack">
          <component
            :is="item.course?.is_locked ? 'button' : link"
            v-for="item in active"
            :key="item.id"
            :type="item.course?.is_locked ? 'button' : undefined"
            :to="item.course?.is_locked ? undefined : (item.course ? `/lms/${item.course.slug}` : '/lms')"
            class="card row"
            :class="{ 'row--locked': item.course?.is_locked }"
            @click="item.course?.is_locked ? explainLock() : undefined"
          >
            <UiProgressRing :value="item.progress ?? 0" :size="48" />
            <div class="row__body">
              <span class="row__title">{{ item.course?.title }}</span>
              <span class="faint">
                <template v-if="item.course?.is_locked">Откроется, когда дойдёт очередь плана</template>
                <template v-else>
                  Начат {{ item.enrolled_at ? new Date(item.enrolled_at).toLocaleDateString('ru-RU') : '' }}
                </template>
              </span>
            </div>
            <span v-if="item.course?.is_locked" class="badge">Закрыт планом</span>
            <span v-else class="button-secondary button-sm">Продолжить</span>
          </component>
        </div>
      </template>

      <template v-if="finished.length">
        <h2 class="group-title">
          Завершённые
        </h2>
        <div class="stack">
          <NuxtLink
            v-for="item in finished"
            :key="item.id"
            :to="item.course ? `/lms/${item.course.slug}` : '/lms'"
            class="card row"
          >
            <UiProgressRing :value="100" :size="48" />
            <div class="row__body">
              <span class="row__title">{{ item.course?.title }}</span>
              <span class="faint">
                Завершён {{ item.completed_at ? new Date(item.completed_at).toLocaleDateString('ru-RU') : '' }}
              </span>
            </div>
            <span class="badge badge--success">Пройден</span>
          </NuxtLink>
        </div>
      </template>
    </template>
  </section>
</template>

<style scoped>
.counted {
  margin: 0.4rem 0 0;
}

.head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1.75rem;
}

.head a {
  text-decoration: none;
}

.group-title {
  margin: 1.75rem 0 0.75rem;
  font-size: 0.78rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-text-faint);
}

.group-title:first-of-type {
  margin-top: 0;
}

.stack {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
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

/* Закрытый планом курс — кнопка: строка остаётся во всю ширину, как соседние,
   и отвечает объяснением, а не переходом. */
.row--locked {
  width: 100%;
  border: 0;
  font: inherit;
  text-align: left;
  color: var(--color-text-muted);
  cursor: pointer;
}

.row--locked:hover {
  box-shadow: var(--shadow-sm);
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

.skeleton-line {
  width: 100%;
  height: 1.5rem;
}

@media (prefers-reduced-motion: reduce) {
  .row { transition: none; }
}
</style>