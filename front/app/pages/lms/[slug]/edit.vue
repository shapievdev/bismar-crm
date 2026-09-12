<script setup lang="ts">
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'
import type { CoursePayload } from '~/types/lms'

definePageMeta({ middleware: 'auth', permission: 'courses.update' })

const route = useRoute()
const router = useRouter()
const { fetchCourse, updateCourse, deleteCourse, fetchStatuses, fetchCategories } = useLmsApi()
const { confirm } = useAppDialog()
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
  keywords: data.value?.course.keywords ?? [],
})

/** Закрывать курс и вести список допущенных вправе автор — и только он. */
const canManageAccess = computed(() => data.value?.course.can_manage_access ?? false)

const errors = ref<ValidationErrors>({})
const generalError = ref<string | null>(null)
const isSubmitting = ref(false)

async function submit(payload: CoursePayload) {
  isSubmitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    const { data: saved } = await updateCourse(slug.value, {
      ...payload,
      summary: payload.summary || null,
      description: payload.description || null,
    })

    // Сохранили — значит правка закончена, и дальше человек смотрит, что
    // получилось: на странице правки его держала только отметка «Сохранено
    // в 14:32», по которой всё равно не видно, как курс выглядит.
    //
    // Адрес берётся из ответа: у неопубликованного курса он следует за
    // названием, и прежний привёл бы в «не найдено».
    await router.push(`/lms/${saved.slug}`)
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      errors.value = caught.errors
    }
    else {
      generalError.value = 'Не удалось сохранить курс.'
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

  const confirmed = await confirm({
    title: `Удалить «${title}»?`,
    message: 'Курс уйдёт со всеми уроками и приложенными файлами.',
    confirmLabel: 'Удалить',
    danger: true,
  })

  if (!confirmed) {
    return
  }

  isDeleting.value = true
  generalError.value = null

  try {
    await deleteCourse(slug.value)
    await router.replace('/lms')
  }
  catch {
    generalError.value = 'Не удалось удалить курс.'
    isDeleting.value = false
  }
}
</script>

<template>
  <section v-if="data">
    <header class="page-header">
      <h1 class="page-title">
        Редактирование курса
      </h1>
      <NuxtLink :to="`/lms/${slug}`" class="back">
        ← К курсу
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
      :saved-status="data.course.status"
      submit-label="Сохранить"
      @submit="submit"
    >
      <template #secondary-actions>
        <button
          v-if="can('courses.delete')"
          type="button"
          class="button-danger course-delete"
          :disabled="isDeleting"
          @click="remove"
        >
          {{ isDeleting ? 'Удаляем…' : 'Удалить курс' }}
        </button>
      </template>
    </CourseForm>

    <!-- Списки людей — рядом, а не друг под другом: в каждом две-три строки, и
         колонкой во всю ширину они разгоняли страницу на лишний экран. Тот же
         приём, что в редакторе документа. -->
    <div class="settings">
      <!--
        Только у приватного курса (решение пользователя 2026-09-12): у
        открытого список ни на что не влияет, а панель, объясняющая, что она ни
        на что не влияет, — лишняя строка на экране.

        Смотрит на форму, а не на сохранённое: переключив доступ на
        «приватный», список собирают тут же, не сохраняя курс наперёд.
        Обратное переключение список не стирает — он ждёт в базе, когда курс
        закроют снова.
      -->
      <CourseAccessPanel
        v-if="canManageAccess && form.visibility === 'private'"
        :key="data.course.id"
        :slug="slug"
        :author-name="data.course.author?.name ?? null"
      />

      <!-- Ответственные — не доступ: их назначает всякий, кто правит курс, и
           видит их всякий, кто курс открыл. -->
      <CourseExpertsPanel :key="`experts-${data.course.id}`" :slug="slug" />
    </div>

    <ModuleTree
      :course-slug="slug"
      :modules="data.course.modules ?? []"
      @changed="refresh"
    />
  </section>
</template>

<style scoped>
/*
 * Списки людей сеткой, соседки по ряду — одной высоты: списки растут по мере
 * наполнения, и ряд вразнобой читается как сбой вёрстки. Отступ сверху панели
 * здесь ни к чему — у сетки свой промежуток.
 */
.settings {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(26rem, 1fr));
  gap: 0.75rem;
  margin-top: 1rem;
}

.settings > * {
  margin-top: 0;
}

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

/* Подальше от «Сохранить»: соседство с кнопкой, которую жмут постоянно, — не
   то место для той, которую жмут раз в жизни. В колонке это отступ сверху, а
   не в сторону. */
.course-delete {
  margin-top: 0.6rem;
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
