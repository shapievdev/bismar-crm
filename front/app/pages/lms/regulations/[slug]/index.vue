<script setup lang="ts">
import { withResolvedMedia } from '~/utils/editor/attachments'
import type { CoursePerson } from '~/types/lms'

definePageMeta({ middleware: 'auth', permission: 'courses.view' })

const route = useRoute()
const slug = computed(() => String(route.params.slug))

const { can } = useAuth()
const { fetchRegulation, acknowledge, fetchReaders } = useRegulationsApi()

const { data, error, refresh } = await useAsyncData(
  () => `lms.regulation.${slug.value}`,
  () => fetchRegulation(slug.value),
)

if (error.value) {
  throw createError({ statusCode: 404, statusMessage: 'Регламент не найден', fatal: true })
}

const regulation = computed(() => data.value?.data ?? null)

useHead({ title: () => regulation.value?.title ?? 'Регламент' })

/**
 * Адреса вложенных картинок и видео подставляются на пути к экрану: статья
 * хранит их номера, а подписанные ссылки живут час.
 */
const article = computed(() => withResolvedMedia(
  regulation.value?.content_json ?? null,
  regulation.value?.attachments ?? [],
))

const documents = computed(() =>
  (regulation.value?.attachments ?? []).filter(file => !file.opens_inline || file.description))

function day(value: string | null): string {
  return value ? new Date(value).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' }) : ''
}

/* ---------- Ознакомление ---------- */

const isConfirming = ref(false)
const confirmError = ref<string | null>(null)

async function confirm() {
  isConfirming.value = true
  confirmError.value = null

  try {
    await acknowledge(slug.value)
    await refresh()
  }
  catch {
    confirmError.value = 'Не удалось отметить ознакомление.'
  }
  finally {
    isConfirming.value = false
  }
}

/* ---------- Кто прочитал: только тому, кто регламент ведёт ---------- */

const readers = ref<CoursePerson[] | null>(null)

async function toggleReaders() {
  if (readers.value !== null) {
    readers.value = null

    return
  }

  readers.value = (await fetchReaders(slug.value)).data
}
</script>

<template>
  <article v-if="regulation" class="regulation">
    <header class="head">
      <div class="head__marks">
        <span v-if="!regulation.is_published" class="badge badge--warning">{{ regulation.status_label }}</span>
        <span v-if="regulation.is_private" class="badge">{{ regulation.visibility_label }}</span>
        <span v-if="regulation.is_acknowledged" class="badge badge--success">Ознакомлен</span>
      </div>

      <h1 class="page-title">
        {{ regulation.title }}
      </h1>

      <p v-if="regulation.summary" class="page-subtitle">
        {{ regulation.summary }}
      </p>

      <p class="faint">
        <template v-if="regulation.category">{{ regulation.category.name }} · </template>
        <template v-if="regulation.author">{{ regulation.author.name }} · </template>
        {{ day(regulation.published_at) }}
      </p>

      <div v-if="can('courses.update')" class="head__actions">
        <NuxtLink :to="`/lms/regulations/${regulation.slug}/edit`" class="button-secondary button-sm">
          Редактировать
        </NuxtLink>
        <button type="button" class="button-ghost button-sm" @click="toggleReaders">
          {{ readers ? 'Скрыть' : 'Кто ознакомился' }}
          <template v-if="regulation.acknowledged_count !== undefined">
            ({{ regulation.acknowledged_count }})
          </template>
        </button>
      </div>
    </header>

    <section v-if="readers" class="card readers">
      <h2 class="readers__title">
        Ознакомились — {{ readers.length }}
      </h2>
      <p v-if="!readers.length" class="faint">
        Пока никто.
      </p>
      <ul v-else class="people">
        <li v-for="person in readers" :key="person.id" class="person">
          <UserAvatar :name="person.name" :src="person.avatar_url" :size="26" />
          <span class="person__body">
            <span>{{ person.name }}</span>
            <span v-if="person.acknowledged_at" class="faint">{{ day(person.acknowledged_at) }}</span>
          </span>
        </li>
      </ul>
    </section>

    <EditorRichTextRenderer :content="article" />

    <section v-if="documents.length" class="card files">
      <h2 class="files__title">
        Документы
      </h2>
      <ul class="files__list">
        <li v-for="file in documents" :key="file.id" class="file">
          <UiFileIcon :name="file.name" :mime-type="file.mime_type" />
          <a :href="file.url" target="_blank" rel="noopener noreferrer" class="file__link">
            {{ file.name }}
            <span v-if="file.description" class="faint file__note">{{ file.description }}</span>
          </a>
        </li>
      </ul>
    </section>

    <!-- Кому писать, если написанного не хватило. -->
    <section v-if="regulation.experts?.length" class="card experts">
      <h2 class="files__title">
        Спросите ответственного
      </h2>
      <ul class="people">
        <li v-for="person in regulation.experts" :key="person.id" class="person">
          <UserAvatar :name="person.name" :src="person.avatar_url" :size="26" />
          <span class="person__body">
            <span>{{ person.name }}</span>
            <NuxtLink :to="`/messenger?write=${person.id}`" class="faint person__write">
              Написать
            </NuxtLink>
          </span>
        </li>
      </ul>
    </section>

    <section v-if="regulation.is_published" class="card confirm">
      <template v-if="regulation.is_acknowledged">
        <p class="confirm__done">
          Вы ознакомились с этим регламентом
          <template v-if="regulation.acknowledged_at">
            {{ day(regulation.acknowledged_at) }}
          </template>
        </p>
      </template>

      <template v-else>
        <p v-if="confirmError" class="alert alert--danger" role="alert">
          {{ confirmError }}
        </p>
        <p class="faint">
          Отметьтесь, когда прочтёте: по этой отметке видно, что правило до вас дошло.
        </p>
        <button type="button" class="button-primary" :disabled="isConfirming" @click="confirm">
          {{ isConfirming ? 'Отмечаем…' : 'Ознакомлен' }}
        </button>
      </template>
    </section>
  </article>
</template>

<style scoped>
.regulation {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.head {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.head__marks,
.head__actions {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.head__actions {
  margin-top: 0.35rem;
}

.head__actions a {
  text-decoration: none;
}

.readers,
.files,
.experts,
.confirm {
  padding: 1.25rem;
}

.readers__title,
.files__title {
  margin: 0 0 0.6rem;
  font-size: 1rem;
  font-weight: 600;
}

.people,
.files__list {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.person,
.file {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  font-size: 0.9rem;
}

.person__body,
.file__link {
  display: flex;
  flex-direction: column;
  gap: 0.05rem;
  min-width: 0;
}

.person__write {
  align-self: flex-start;
  font-size: 0.8rem;
}

.file__link {
  color: inherit;
  text-decoration: none;
}

.file__link:hover {
  text-decoration: underline;
}

.file__note {
  font-size: 0.825rem;
}

.confirm {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  align-items: flex-start;
}

.confirm__done {
  margin: 0;
  color: var(--color-success);
  font-weight: 550;
}
</style>
