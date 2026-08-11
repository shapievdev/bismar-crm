<script setup lang="ts">
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'
import type { CoursePayload } from '~/types/lms'

definePageMeta({ middleware: 'auth', permission: 'courses.update' })

const route = useRoute()
const router = useRouter()
const { fetchCourse, updateCourse, deleteCourse, fetchStatuses, fetchCategories } = useLmsApi()
const { can } = useAuth()

const slug = computed(() => String(route.params.slug))

const { data, error, refresh } = await useAsyncData(
  () => `lms.edit.${slug.value}`,
  async () => {
    const [course, statuses, categories] = await Promise.all([
      fetchCourse(slug.value),
      fetchStatuses(),
      fetchCategories(),
    ])

    return { course: course.data, statuses: statuses.data, categories: categories.data }
  },
)

if (error.value) {
  throw createError({ statusCode: 404, statusMessage: 'Курс не найден', fatal: true })
}

useHead(() => ({ title: `Редактирование — ${data.value?.course.title ?? ''}` }))

const form = ref<CoursePayload>({
  title: data.value?.course.title ?? '',
  summary: data.value?.course.summary ?? '',
  description: data.value?.course.description ?? '',
  status: data.value?.course.status ?? 'draft',
  visibility: data.value?.course.visibility ?? 'public',
  category_id: data.value?.course.category?.id ?? null,
})

/** Закрывать курс и вести список допущенных вправе автор — и только он. */
const canManageAccess = computed(() => data.value?.course.can_manage_access ?? false)

const errors = ref<ValidationErrors>({})
const generalError = ref<string | null>(null)
const isSubmitting = ref(false)
const savedAt = ref<string | null>(null)

async function submit(payload: CoursePayload) {
  isSubmitting.value = true
  errors.value = {}
  generalError.value = null
  savedAt.value = null

  try {
    const { data: saved } = await updateCourse(slug.value, {
      ...payload,
      summary: payload.summary || null,
      description: payload.description || null,
    })

    // An unpublished course changes slug when retitled, so follow it.
    if (saved.slug !== slug.value) {
      await router.replace(`/lms/${saved.slug}/edit`)
    }

    await refresh()
    savedAt.value = new Date().toLocaleTimeString('ru-RU')
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      errors.value = caught.errors
    }
    else {
      generalError.value = 'Не удалось сохранить материал.'
    }
  }
  finally {
    isSubmitting.value = false
  }
}

/**
 * Удаление материала — с подтверждением и названием в вопросе.
 *
 * Название не для красоты: подтверждение, в котором не сказано, что именно
 * удаляют, люди прожимают не глядя. На сервере удаление мягкое, но материал
 * вместе со всеми уроками уходит из базы знаний сразу.
 */
const isDeleting = ref(false)

async function remove() {
  const title = data.value?.course.title ?? ''

  if (!window.confirm(`Удалить материал «${title}» со всеми уроками?`)) {
    return
  }

  isDeleting.value = true
  generalError.value = null

  try {
    await deleteCourse(slug.value)
    await router.replace('/lms')
  }
  catch {
    generalError.value = 'Не удалось удалить материал.'
    isDeleting.value = false
  }
}
</script>

<template>
  <section v-if="data">
    <header class="page-header">
      <h1 class="page-title">
        Редактирование материала
      </h1>
      <NuxtLink :to="`/lms/${slug}`" class="back">
        ← К материалу
      </NuxtLink>
    </header>

    <p v-if="generalError" class="alert alert--danger" role="alert">
      {{ generalError }}
    </p>

    <CourseForm
      v-model="form"
      :statuses="data.statuses"
      :categories="data.categories"
      :errors="errors"
      :is-submitting="isSubmitting"
      :can-manage-access="canManageAccess"
      submit-label="Сохранить"
      @submit="submit"
    >
      <template #secondary-actions>
        <span v-if="savedAt" class="muted">Сохранено в {{ savedAt }}</span>

        <button
          v-if="can('courses.delete')"
          type="button"
          class="button-danger course-delete"
          :disabled="isDeleting"
          @click="remove"
        >
          {{ isDeleting ? 'Удаляем…' : 'Удалить материал' }}
        </button>
      </template>
    </CourseForm>

    <CourseAccessPanel
      v-if="canManageAccess"
      :key="data.course.id"
      :slug="slug"
      :is-private="data.course.is_private"
      :author-name="data.course.author?.name ?? null"
    />

    <!-- Ответственные — не доступ: их назначает всякий, кто правит курс, и
         видит их всякий, кто курс открыл. -->
    <CourseExpertsPanel :key="`experts-${data.course.id}`" :slug="slug" />

    <ModuleTree
      :course-slug="slug"
      :modules="data.course.modules ?? []"
      @changed="refresh"
    />
  </section>
</template>

<style scoped>
.page-header {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1.25rem;
}

.page-header h1 {
  margin: 0;
  font-size: 1.5rem;
}

.back {
  font-size: 0.9rem;
  text-decoration: none;
}

.muted {
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

/* Подальше от «Сохранить»: соседство с кнопкой, которую жмут постоянно, — не
   то место для той, которую жмут раз в жизни. */
.course-delete {
  margin-left: auto;
}

.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

@media (max-width: 48rem) {
  .page-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 0.5rem;
  }

  .back,
  .link {
    white-space: nowrap;
  }

  .row {
    flex-direction: column;
  }

  .field--narrow {
    flex: 1 1 auto;
    min-width: 0;
  }
}
</style>
