<script setup lang="ts">
definePageMeta({ middleware: 'auth', permission: 'courses.view' })
useHead({ title: 'Обучение' })

const { fetchCourses } = useLmsApi()
const { can } = useAuth()
const route = useRoute()
const router = useRouter()

const search = ref(typeof route.query.search === 'string' ? route.query.search : '')

const { data, pending, error } = await useAsyncData(
  'lms.courses',
  () => fetchCourses({ search: search.value || undefined }),
  { watch: [search] },
)

watchEffect(() => {
  router.replace({ query: search.value ? { search: search.value } : {} })
})

const courses = computed(() => data.value?.data ?? [])
</script>

<template>
  <section>
    <header class="page-header">
      <div>
        <h1>Обучение</h1>
        <p class="muted">
          Курсы для команды. Проходите последовательно — прогресс сохраняется.
        </p>
      </div>

      <div class="page-header__actions">
        <NuxtLink to="/lms/my" class="button-plain">
          Мои курсы
        </NuxtLink>
        <NuxtLink v-if="can('courses.create')" to="/lms/new" class="button-primary">
          Новый курс
        </NuxtLink>
      </div>
    </header>

    <input
      v-model.trim="search"
      type="search"
      class="search"
      placeholder="Поиск по курсам…"
      aria-label="Поиск по курсам"
    >

    <p v-if="error" class="auth-alert" role="alert">
      Не удалось загрузить курсы.
    </p>

    <p v-else-if="pending" class="muted">
      Загрузка…
    </p>

    <p v-else-if="!courses.length" class="empty">
      {{ search ? 'Ничего не найдено.' : 'Курсов пока нет.' }}
    </p>

    <ul v-else class="courses">
      <li v-for="course in courses" :key="course.slug" class="course">
        <NuxtLink :to="`/lms/${course.slug}`" class="course__title">
          {{ course.title }}
        </NuxtLink>

        <p v-if="course.summary" class="course__summary">
          {{ course.summary }}
        </p>

        <div class="course__meta">
          <span v-if="course.status !== 'published'" class="badge">{{ course.status_label }}</span>
          <span>{{ course.lessons_count ?? 0 }} уроков</span>
          <span>{{ course.enrollments_count ?? 0 }} записалось</span>
          <span v-if="course.author">{{ course.author.name }}</span>
        </div>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.page-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1.25rem;
}

.page-header h1 {
  margin: 0 0 0.25rem;
  font-size: 1.5rem;
}

.page-header__actions {
  display: flex;
  gap: 0.5rem;
}

.page-header__actions a {
  text-decoration: none;
}

.muted {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.search {
  width: 100%;
  max-width: 24rem;
  padding: 0.5rem 0.7rem;
  margin-bottom: 1.25rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface);
  color: var(--color-text);
  font: inherit;
}

.empty {
  padding: 2rem;
  border: 1px dashed var(--color-border);
  border-radius: var(--radius);
  color: var(--color-text-muted);
  text-align: center;
}

.courses {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(18rem, 1fr));
  gap: 0.75rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.course {
  padding: 1rem 1.25rem;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
}

.course__title {
  font-size: 1.05rem;
  font-weight: 500;
  color: inherit;
  text-decoration: none;
}

.course__title:hover {
  color: var(--color-accent);
}

.course__summary {
  margin: 0.35rem 0 0;
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.course__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  margin-top: 0.6rem;
  color: var(--color-text-muted);
  font-size: 0.8rem;
}

.badge {
  padding: 0.05rem 0.45rem;
  border: 1px solid var(--color-border);
  border-radius: 999px;
}

.button-plain {
  padding: 0.6rem 1rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface);
  color: var(--color-text);
  font: inherit;
  cursor: pointer;
}
</style>
