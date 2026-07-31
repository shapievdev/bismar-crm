<script setup lang="ts">
definePageMeta({ middleware: 'auth', permission: 'courses.view' })
useHead({ title: 'Мои курсы' })

const { myCourses } = useLmsApi()

const { data, pending, error } = await useAsyncData('lms.my-courses', () => myCourses())

const enrollments = computed(() => data.value?.data ?? [])
</script>

<template>
  <section>
    <NuxtLink to="/lms" class="back">
      ← Ко всем курсам
    </NuxtLink>

    <h1>Мои курсы</h1>

    <p v-if="error" class="auth-alert" role="alert">
      Не удалось загрузить список.
    </p>

    <p v-else-if="pending" class="muted">
      Загрузка…
    </p>

    <p v-else-if="!enrollments.length" class="empty">
      Вы пока не записаны ни на один курс.
    </p>

    <ul v-else class="list">
      <li v-for="item in enrollments" :key="item.id" class="item">
        <NuxtLink v-if="item.course" :to="`/lms/${item.course.slug}`" class="item__title">
          {{ item.course.title }}
        </NuxtLink>

        <div class="item__progress">
          <div class="bar">
            <div class="bar__fill" :style="{ width: `${item.progress ?? 0}%` }" />
          </div>
          <span class="muted">
            {{ item.is_completed ? 'Пройден' : `${item.progress ?? 0}%` }}
          </span>
        </div>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.back {
  display: inline-block;
  margin-bottom: 1rem;
  font-size: 0.9rem;
  text-decoration: none;
}

h1 {
  margin: 0 0 1.25rem;
  font-size: 1.5rem;
}

.muted {
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.empty {
  padding: 2rem;
  border: 1px dashed var(--color-border);
  border-radius: var(--radius);
  color: var(--color-text-muted);
  text-align: center;
}

.list {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.item {
  padding: 1rem 1.25rem;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
}

.item__title {
  font-weight: 500;
  color: inherit;
  text-decoration: none;
}

.item__progress {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-top: 0.6rem;
}

.bar {
  flex: 1;
  max-width: 20rem;
  height: 0.4rem;
  background: var(--color-border);
  border-radius: 999px;
  overflow: hidden;
}

.bar__fill {
  height: 100%;
  background: var(--color-accent);
}
</style>
