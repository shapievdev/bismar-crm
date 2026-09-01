<script setup lang="ts">
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'

definePageMeta({ middleware: 'auth', permission: 'courses.create' })
useHead({ title: 'Новый документ' })

const { createRegulation, fetchCategories } = useRegulationsApi()
const router = useRouter()

const { data: categoryData } = await useAsyncData('lms.regulation-categories.new', () => fetchCategories())
const categories = computed(() => categoryData.value?.data ?? [])

/**
 * Здесь только название и место: статью и файлы пишут на экране правки. Так
 * вышло не для простоты — картинке и видео нужен номер документа, под которым
 * они лягут в хранилище, а до сохранения его ещё нет.
 */
const form = reactive({
  title: '',
  summary: '',
  category_id: null as number | null,
  visibility: 'public' as 'public' | 'private',
})

const errors = ref<ValidationErrors>({})
const generalError = ref<string | null>(null)
const isSaving = ref(false)

async function save() {
  isSaving.value = true
  errors.value = {}
  generalError.value = null

  try {
    const { data } = await createRegulation({
      title: form.title,
      summary: form.summary || null,
      content_json: null,
      // Заводится черновиком всегда: публиковать пустое правило незачем.
      status: 'draft',
      visibility: form.visibility,
      category_id: form.category_id,
    })

    await router.push(`/lms/documents/${data.slug}/edit`)
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      errors.value = caught.errors
    }
    else {
      generalError.value = 'Не удалось создать документ.'
    }
  }
  finally {
    isSaving.value = false
  }
}
</script>

<template>
  <section>
    <header class="head">
      <h1 class="page-title">
        Новый документ
      </h1>
      <p class="page-subtitle">
        Назовите его и сохраните — дальше откроется редактор со статьёй и файлами.
      </p>
    </header>

    <p v-if="generalError" class="alert alert--danger" role="alert">
      {{ generalError }}
    </p>

    <form class="card form" novalidate @submit.prevent="save">
      <div class="field">
        <label class="field-label" for="title">Название</label>
        <input id="title" v-model.trim="form.title" class="input" maxlength="255">
        <p v-if="errors.title?.length" class="field-error">
          {{ errors.title[0] }}
        </p>
      </div>

      <div class="field">
        <label class="field-label" for="summary">
          Короткое описание <span class="field-optional">— строка для каталога</span>
        </label>
        <input id="summary" v-model.trim="form.summary" class="input" maxlength="500">
      </div>

      <div class="field">
        <label class="field-label" for="category">
          Категория <span class="field-optional">— если есть</span>
        </label>
        <CategoryTreeSelect id="category" v-model="form.category_id" :categories="categories" />
      </div>

      <div class="field">
        <span class="field-label">Кому виден</span>
        <label class="choice">
          <input v-model="form.visibility" type="radio" value="public">
          Всем, кто читает базу знаний
        </label>
        <label class="choice">
          <input v-model="form.visibility" type="radio" value="private">
          Только допущенным — назовёте их в редакторе
        </label>
      </div>

      <div class="form__actions">
        <button type="submit" class="button-primary" :disabled="isSaving">
          {{ isSaving ? 'Создаём…' : 'Создать черновик' }}
        </button>
        <NuxtLink to="/lms/documents" class="button-ghost">
          Отмена
        </NuxtLink>
      </div>
    </form>
  </section>
</template>

<style scoped>
.head {
  margin-bottom: 1.75rem;
}

.form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1.4rem 1.5rem;
  max-width: 40rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.field-optional {
  color: var(--color-text-faint);
  font-weight: 400;
}

.choice {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.9rem;
}

.form__actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.form__actions a {
  text-decoration: none;
}

@media (max-width: 48rem) {
  .form {
    padding: 1.15rem 1.15rem 1.25rem;
  }
}

@media (max-width: 34rem) {
  .form__actions {
    flex-direction: column;
    align-items: stretch;
  }

  .form__actions a {
    text-align: center;
  }
}
</style>
