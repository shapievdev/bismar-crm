<script setup lang="ts">
import type { FetchError } from 'ofetch'
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'
import type { GoogleSettings } from '~/composables/useIntegrationsApi'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Интеграции' })

const { isAdmin } = useAuth()
const { fetchGoogleSettings, saveGoogleSettings } = useIntegrationsApi()

// Настроить связку с чужой службой — решение о компании, а не право с галочкой.
if (!isAdmin.value) {
  throw createError({ statusCode: 403, statusMessage: 'Недостаточно прав', fatal: true })
}

const { data, refresh } = await useAsyncData('integrations.google.settings', () => fetchGoogleSettings())

const settings = computed<GoogleSettings | undefined>(() => data.value?.data)

const form = reactive({
  client_id: settings.value?.client_id ?? '',
  api_key: settings.value?.api_key ?? '',
})

const errors = ref<ValidationErrors>({})
const generalError = ref<string | null>(null)
const isSaving = ref(false)
const savedAt = ref<string | null>(null)

/**
 * Настройки, которыми пользуется всё приложение, живут одним состоянием — по
 * нему кнопка «С Google Диска» решает, показываться ли. После сохранения его
 * надо обновить здесь же: иначе кнопка появится только после перезагрузки
 * страницы, и человек решит, что настройка не сработала.
 */
const shared = useState<GoogleSettings | null>('integrations.google', () => null)

async function save(): Promise<void> {
  isSaving.value = true
  errors.value = {}
  generalError.value = null
  savedAt.value = null

  try {
    const saved = await saveGoogleSettings({
      // Пустое поле — это «не задано здесь»: тогда возьмётся значение из
      // переменных окружения сервера, если оно там есть.
      client_id: form.client_id.trim() || null,
      api_key: form.api_key.trim() || null,
    })

    shared.value = saved.data
    savedAt.value = new Date().toLocaleTimeString('ru-RU')
    await refresh()
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      errors.value = caught.errors
    }
    else {
      generalError.value = (caught as FetchError).data?.message ?? 'Не удалось сохранить настройки.'
    }
  }
  finally {
    isSaving.value = false
  }
}

/** Адреса, которые Google требует внести в список разрешённых источников. */
const origin = computed(() => (import.meta.client ? window.location.origin : ''))
</script>

<template>
  <section>
    <header class="head">
      <div>
        <h1 class="page-title">
          Интеграции
        </h1>
        <p class="page-subtitle">
          Связка с Google Диском: откуда авторы прикладывают файлы к урокам и документам.
        </p>
      </div>
    </header>

    <p v-if="generalError" class="alert alert--danger" role="alert">
      {{ generalError }}
    </p>

    <!-- Ненастроенная интеграция — не поломка, а несделанная работа, поэтому
         красным она не кричит: просто состояние, в котором кнопки нет. -->
    <p
      class="alert status"
      :class="{ 'alert--success': settings?.is_configured }"
      role="status"
    >
      <template v-if="settings?.is_configured">
        Диск настроен: в редакторе урока и документа есть кнопка «С Google Диска».
      </template>
      <template v-else>
        Диск не настроен — кнопки выбора файла нет. Нужны оба значения ниже.
      </template>
    </p>

    <form class="card card--raised form" novalidate @submit.prevent="save">
      <FormField
        id="client_id"
        v-model="form.client_id"
        label="Идентификатор клиента OAuth"
        :errors="errors.client_id"
        placeholder="123456789-abc.apps.googleusercontent.com"
      />
      <p class="hint">
        Им сотрудник входит в свой Google и выдаёт доступ — только к тем файлам,
        которые сам выберет.
        <template v-if="settings?.effective.client_id && !settings.client_id">
          Сейчас применяется значение из переменных окружения сервера.
        </template>
      </p>

      <FormField
        id="api_key"
        v-model="form.api_key"
        label="Ключ API"
        :errors="errors.api_key"
        placeholder="AIza…"
      />
      <p class="hint">
        Им открывается само окно выбора файла.
        <template v-if="settings?.effective.api_key && !settings.api_key">
          Сейчас применяется значение из переменных окружения сервера.
        </template>
      </p>

      <p class="hint hint--boxed">
        Оба значения публичны по устройству: окно Google открывается в браузере
        сотрудника, и они уезжают туда вместе со страницей. Тайной их не защищают —
        защищает список разрешённых источников в Google Cloud, с чужого домена
        они не работают. Платёжных ключей здесь нет.
      </p>

      <footer class="actions">
        <button type="submit" class="button-primary" :disabled="isSaving">
          {{ isSaving ? 'Сохраняем…' : 'Сохранить' }}
        </button>

        <span v-if="savedAt" class="faint">Сохранено в {{ savedAt }}</span>
      </footer>
    </form>

    <section class="card card--raised guide">
      <h2 class="guide__title">
        Где взять эти два значения
      </h2>

      <ol class="guide__steps">
        <li>
          Откройте <a href="https://console.cloud.google.com" target="_blank" rel="noopener noreferrer">console.cloud.google.com</a>
          и создайте проект — или возьмите тот, что уже есть у компании.
        </li>
        <li>
          <b>APIs &amp; Services → Library</b> — включите <b>Google Picker API</b>.
        </li>
        <li>
          <b>Credentials → Create credentials → API key</b>. Ограничьте его сайтами:
          в <i>Websites</i> добавьте <code>{{ origin }}/*</code> — здесь звёздочка нужна, —
          а в ограничениях API оставьте только Google Picker API. Это и есть «Ключ API».
        </li>
        <li>
          <b>Credentials → Create credentials → OAuth client ID → Web application</b>.
          В <i>Authorized JavaScript origins</i> добавьте <code>{{ origin }}</code> —
          <b>без звёздочки, без пути и без слэша на конце</b>: Google их здесь не принимает.
          Поле <i>Authorized redirect URIs</i> оставьте пустым — окно Google открывается
          поверх страницы и возвращаться ему некуда, а звёздочка в этом поле как раз и даёт
          ошибку «Invalid Redirect: cannot contain a wildcard». Это и есть «Идентификатор
          клиента OAuth».
        </li>
        <li>
          <b>OAuth consent screen</b>: если почта компании на Google Workspace, выберите
          <b>Internal</b> — тогда проверка приложения в Google не потребуется.
        </li>
      </ol>

      <p class="hint">
        Файл в уроке увидят те, кому он открыт на самом Диске: либо доступ по ссылке,
        либо выданный рабочим аккаунтам. Права из CRM на Диск не переносятся.
      </p>
    </section>

    <p v-if="settings?.updated_at" class="faint updated">
      Последнее изменение: {{ new Date(settings.updated_at).toLocaleString('ru-RU') }}
      <template v-if="settings.updated_by">— {{ settings.updated_by }}</template>
    </p>
  </section>
</template>

<style scoped>
.head {
  margin-bottom: 1.75rem;
}

.form,
.guide {
  max-width: 42rem;
  padding: 1.5rem;
}

.form {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.form :deep(.field) {
  margin-bottom: 0.35rem;
}

.hint {
  margin: 0 0 1rem;
  font-size: 0.82rem;
  line-height: 1.45;
  color: var(--color-text-faint);
}

.hint--boxed {
  margin-bottom: 0;
  padding: 0.7rem 0.85rem;
  border-radius: var(--radius);
  background: var(--color-surface-sunken);
}

.hint code,
.guide code {
  padding: 0.05em 0.3em;
  border-radius: var(--radius-sm);
  background: var(--control-surface-hover);
  font-size: 0.95em;
  word-break: break-all;
}

.actions {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  margin-top: 0.75rem;
}

.alert {
  max-width: 42rem;
  margin-bottom: 1rem;
}

.status {
  background: var(--color-surface-sunken);
  color: var(--color-text-muted);
}

/* Настроенная связка красится своим цветом — правило выше его не перебивает. */
.status.alert--success {
  background: var(--color-success-soft);
  color: var(--color-success);
}

.guide {
  margin-top: 1.25rem;
}

.guide__title {
  margin: 0 0 0.8rem;
  font-size: 1.02rem;
  font-weight: 550;
}

.guide__steps {
  margin: 0 0 1rem;
  padding-left: 1.2rem;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  font-size: 0.88rem;
  line-height: 1.5;
}

.updated {
  display: block;
  margin-top: 1rem;
  font-size: 0.82rem;
}
</style>
