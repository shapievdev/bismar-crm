<script setup lang="ts">
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'
import type { CoursePayload } from '~/types/lms'

definePageMeta({ middleware: 'auth', permission: 'courses.update' })

const route = useRoute()
const router = useRouter()
const { fetchCourse, updateCourse, fetchStatuses, fetchCategories, uploadCover, deleteCover } = useLmsApi()

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
  category_id: data.value?.course.category?.id ?? null,
})

const errors = ref<ValidationErrors>({})
const generalError = ref<string | null>(null)
const isSubmitting = ref(false)
const savedAt = ref<string | null>(null)

const coverInput = useTemplateRef<HTMLInputElement>('coverInput')
const coverError = ref<string | null>(null)
const isUploadingCover = ref(false)

async function onCoverChosen(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]

  if (!file) {
    return
  }

  isUploadingCover.value = true
  coverError.value = null

  try {
    await uploadCover(slug.value, file)
    await refresh()
  }
  catch (caught) {
    const failure = caught as { data?: { message?: string, errors?: Record<string, string[]> } }
    coverError.value = failure.data?.errors?.cover?.[0]
      ?? failure.data?.message
      ?? 'Не удалось загрузить обложку.'
  }
  finally {
    isUploadingCover.value = false
    // Clear the input so the same file can be retried after a failure.
    input.value = ''
  }
}

async function removeCover() {
  coverError.value = null

  try {
    await deleteCover(slug.value)
    await refresh()
  }
  catch {
    coverError.value = 'Не удалось удалить обложку.'
  }
}

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

    <section class="cover">
      <div class="cover__preview" :class="{ 'cover__preview--empty': !data.course.cover_url }">
        <img v-if="data.course.cover_url" :src="data.course.cover_url" alt="Обложка курса">
        <span v-else class="faint">Нет обложки</span>
      </div>

      <div class="cover__actions">
        <button
          type="button"
          class="button-secondary button-sm"
          :disabled="isUploadingCover"
          @click="coverInput?.click()"
        >
          {{ isUploadingCover ? 'Загружаем…' : (data.course.cover_url ? 'Заменить' : 'Загрузить обложку') }}
        </button>

        <button
          v-if="data.course.cover_url"
          type="button"
          class="button-ghost button-sm"
          @click="removeCover"
        >
          Удалить
        </button>

        <input
          ref="coverInput"
          type="file"
          accept="image/png,image/jpeg,image/webp"
          class="visually-hidden"
          @change="onCoverChosen"
        >

        <p class="faint cover__hint">
          PNG, JPG или WebP, до 5 МБ. Хранится в S3.
        </p>
      </div>
    </section>

    <p v-if="coverError" class="alert alert--danger" role="alert">
      {{ coverError }}
    </p>

    <CourseForm
      v-model="form"
      :statuses="data.statuses"
      :categories="data.categories"
      :errors="errors"
      :is-submitting="isSubmitting"
      submit-label="Сохранить"
      @submit="submit"
    >
      <template #secondary-actions>
        <span v-if="savedAt" class="muted">Сохранено в {{ savedAt }}</span>
      </template>
    </CourseForm>

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

.cover {
  display: flex;
  align-items: center;
  gap: 1.25rem;
  margin-bottom: 1.75rem;
}

.cover__preview {
  width: 14rem;
  flex-shrink: 0;
  aspect-ratio: 16 / 9;
  border-radius: var(--radius);
  overflow: hidden;
  background: var(--color-surface-sunken);
}

.cover__preview--empty {
  display: grid;
  place-items: center;
  border: 1px dashed var(--color-border-strong);
  font-size: 0.85rem;
}

.cover__preview img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.cover__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.cover__hint {
  flex-basis: 100%;
  margin: 0;
  font-size: 0.82rem;
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
</style>
