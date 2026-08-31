<script setup lang="ts">
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'
import type { NewsAudienceKind } from '~/types/news'

definePageMeta({ middleware: 'auth', permission: 'news.manage' })
useHead({ title: 'Новая новость' })

const { createNews } = useNewsApi()
const router = useRouter()

/**
 * Здесь только заголовок и адресаты: статью, файлы и проверку пишут на экране
 * правки. Так вышло не для простоты — картинке и видео нужен номер новости,
 * под которым они лягут в хранилище, а до сохранения его ещё нет.
 */
const form = reactive({
  title: '',
  excerpt: '',
  audience: 'everyone' as NewsAudienceKind,
  requires_acknowledgement: false,
})

const errors = ref<ValidationErrors>({})
const generalError = ref<string | null>(null)
const isSaving = ref(false)

async function save() {
  isSaving.value = true
  errors.value = {}
  generalError.value = null

  try {
    const { data } = await createNews({
      title: form.title,
      excerpt: form.excerpt || null,
      content_json: null,
      // Заводится черновиком всегда: публиковать пустую новость незачем.
      status: 'draft',
      is_pinned: false,
      audience: form.audience,
      requires_acknowledgement: form.requires_acknowledgement,
      // Адресатов выбирают в редакторе: новость заводят с решения «это не
      // всем», а кому именно — выясняют, пока она черновик.
      recipients: [],
      department_ids: [],
      group_ids: [],
      // Материал привязывают там же — здесь ещё нечего связывать.
      links: [],
    })

    await router.push(`/news/${data.slug}/edit`)
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      errors.value = caught.errors
    }
    else {
      generalError.value = 'Не удалось создать новость.'
    }
  }
  finally {
    isSaving.value = false
  }
}
</script>

<template>
  <section class="new">
    <header class="head">
      <h1 class="page-title">
        Новая новость
      </h1>
      <p class="page-subtitle">
        Назовите её и сохраните — дальше откроется редактор со статьёй, файлами и проверкой.
      </p>
    </header>

    <p v-if="generalError" class="alert alert--danger" role="alert">
      {{ generalError }}
    </p>

    <form class="card form" novalidate @submit.prevent="save">
      <div class="field">
        <label class="field-label" for="title">Заголовок</label>
        <input id="title" v-model.trim="form.title" class="input" maxlength="255">
        <p v-if="errors.title?.length" class="field-error">
          {{ errors.title[0] }}
        </p>
      </div>

      <div class="field">
        <label class="field-label" for="excerpt">
          Короткое описание <span class="field-optional">— строка для ленты</span>
        </label>
        <input id="excerpt" v-model.trim="form.excerpt" class="input" maxlength="500">
        <p v-if="errors.excerpt?.length" class="field-error">
          {{ errors.excerpt[0] }}
        </p>
      </div>

      <div class="field">
        <span class="field-label">Кому</span>
        <label class="choice">
          <input v-model="form.audience" type="radio" value="everyone">
          Всем сотрудникам
        </label>
        <label class="choice">
          <input v-model="form.audience" type="radio" value="selected">
          Выбранным — назовёте их в редакторе
        </label>
      </div>

      <label class="choice">
        <input v-model="form.requires_acknowledgement" type="checkbox">
        Обязательна для ознакомления
      </label>

      <div class="form__actions">
        <button type="submit" class="button-primary" :disabled="isSaving">
          {{ isSaving ? 'Создаём…' : 'Создать черновик' }}
        </button>
        <NuxtLink to="/news" class="button-ghost">
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

/* На узком экране кнопки становятся в столбец во всю ширину: пара «создать —
   отмена» вплотную у края слишком легко нажимается не та. */
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
