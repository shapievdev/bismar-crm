<script setup lang="ts">
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'
import type { ThemePreference } from '~/composables/useTheme'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Профиль' })

const { user, updateProfile, uploadAvatar, removeAvatar } = useAuth()
const { preference, setTheme, options } = useTheme()

const form = reactive({
  name: user.value?.name ?? '',
  email: user.value?.email ?? '',
})

const errors = ref<ValidationErrors>({})
const generalError = ref<string | null>(null)
const isSaving = ref(false)
const savedAt = ref<string | null>(null)

async function save() {
  isSaving.value = true
  errors.value = {}
  generalError.value = null
  savedAt.value = null

  try {
    await updateProfile({ name: form.name, email: form.email })
    savedAt.value = new Date().toLocaleTimeString('ru-RU')
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      errors.value = caught.errors
    }
    else {
      generalError.value = 'Не удалось сохранить профиль.'
    }
  }
  finally {
    isSaving.value = false
  }
}

const avatarInput = useTemplateRef<HTMLInputElement>('avatarInput')
const avatarError = ref<string | null>(null)
const isUploadingAvatar = ref(false)

async function onAvatarChosen(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''

  if (!file) {
    return
  }

  isUploadingAvatar.value = true
  avatarError.value = null

  try {
    await uploadAvatar(file)
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      avatarError.value = caught.errors.avatar?.[0] ?? 'Не удалось загрузить фото.'
    }
    else {
      avatarError.value = 'Не удалось загрузить фото.'
    }
  }
  finally {
    isUploadingAvatar.value = false
  }
}

async function dropAvatar() {
  avatarError.value = null

  try {
    await removeAvatar()
  }
  catch {
    avatarError.value = 'Не удалось удалить фото.'
  }
}

function choose(value: ThemePreference) {
  setTheme(value)
}
</script>

<template>
  <section class="profile">
    <header class="head">
      <h1 class="page-title">
        Профиль
      </h1>
      <p class="page-subtitle">
        Как вас видят коллеги и как выглядит интерфейс на этом устройстве.
      </p>
    </header>

    <section class="card card--raised block">
      <h2 class="block__title">
        Фото
      </h2>

      <div class="avatar-row">
        <UserAvatar :name="user?.name" :src="user?.avatar_url" :size="88" />

        <div class="avatar-row__actions">
          <button
            type="button"
            class="button-secondary button-sm"
            :disabled="isUploadingAvatar"
            @click="avatarInput?.click()"
          >
            {{ isUploadingAvatar ? 'Загружаем…' : (user?.avatar_url ? 'Заменить' : 'Загрузить фото') }}
          </button>

          <button
            v-if="user?.avatar_url"
            type="button"
            class="button-ghost button-sm"
            @click="dropAvatar"
          >
            Удалить
          </button>

          <input
            ref="avatarInput"
            type="file"
            accept="image/png,image/jpeg,image/webp"
            class="visually-hidden"
            @change="onAvatarChosen"
          >

          <p class="faint avatar-row__hint">
            PNG, JPG или WebP, до 4 МБ. Без фото показываются инициалы.
          </p>
        </div>
      </div>

      <p v-if="avatarError" class="alert alert--danger" role="alert">
        {{ avatarError }}
      </p>
    </section>

    <section class="card card--raised block">
      <h2 class="block__title">
        Данные
      </h2>

      <p v-if="generalError" class="alert alert--danger" role="alert">
        {{ generalError }}
      </p>

      <form class="form" novalidate @submit.prevent="save">
        <div class="field">
          <label class="field-label" for="name">Имя и фамилия</label>
          <input id="name" v-model.trim="form.name" class="input" autocomplete="name">
          <p v-if="errors.name?.length" class="field-error">
            {{ errors.name[0] }}
          </p>
        </div>

        <div class="field">
          <label class="field-label" for="email">Email</label>
          <input id="email" v-model.trim="form.email" type="email" class="input" autocomplete="email">
          <p v-if="errors.email?.length" class="field-error">
            {{ errors.email[0] }}
          </p>
        </div>

        <div class="form__actions">
          <button type="submit" class="button-primary" :disabled="isSaving">
            {{ isSaving ? 'Сохраняем…' : 'Сохранить' }}
          </button>
          <span v-if="savedAt" class="faint">Сохранено в {{ savedAt }}</span>
        </div>
      </form>
    </section>

    <section class="card card--raised block">
      <h2 class="block__title">
        Тема
      </h2>
      <p class="faint block__hint">
        Настройка этого браузера, а не аккаунта: она описывает экран перед вами.
      </p>

      <div class="themes" role="radiogroup" aria-label="Тема оформления">
        <button
          v-for="option in options"
          :key="option.value"
          type="button"
          role="radio"
          class="theme"
          :class="{ 'theme--active': preference === option.value }"
          :aria-checked="preference === option.value"
          :aria-label="option.label"
          @click="choose(option.value)"
        >
          <span class="theme__preview" :class="`theme__preview--${option.value}`" aria-hidden="true">
            <span class="theme__bar" />
            <span class="theme__body" />
          </span>
          <span class="theme__label">{{ option.label }}</span>
          <span class="faint theme__hint">{{ option.hint }}</span>
        </button>
      </div>
    </section>
  </section>
</template>

<style scoped>
.profile {
  max-width: 42rem;
}

.head {
  margin-bottom: 1.75rem;
}

.block {
  padding: 1.35rem 1.5rem 1.5rem;
  margin-bottom: 1rem;
}

.block__title {
  margin: 0 0 1rem;
  font-size: 1.05rem;
  font-weight: 600;
}

.block__hint {
  margin: -0.7rem 0 1rem;
  font-size: 0.87rem;
}

.avatar-row {
  display: flex;
  align-items: center;
  gap: 1.25rem;
}

.avatar-row__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

/*
 * .button-secondary paints itself in the raised tone, which is exactly what the
 * card underneath is — so on this page it needs the sunken tone to read as a
 * control at all. Same tone the theme swatches use for the same reason.
 */
.avatar-row__actions :is(.button-secondary, .button-ghost) {
  background: var(--color-surface-sunken);
}

.avatar-row__actions :is(.button-secondary, .button-ghost):hover:not(:disabled) {
  background: var(--color-border-strong);
}

.avatar-row__hint {
  flex-basis: 100%;
  margin: 0;
  font-size: 0.83rem;
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

.form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.form__actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.themes {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr));
  gap: 0.7rem;
}

.theme {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  padding: 0.65rem;
  border: 2px solid transparent;
  border-radius: var(--radius);
  background: var(--color-surface-sunken);
  color: var(--color-text);
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.theme--active {
  border-color: var(--color-accent);
  /* Doubles the 2px border optically so the choice reads at a glance. */
  box-shadow: 0 0 0 1px var(--color-accent);
}

/*
 * Fixed colours on purpose: a preview has to show what a theme looks like, not
 * what the current one does, so it cannot use the palette variables.
 */
.theme__preview {
  display: block;
  height: 3rem;
  margin-bottom: 0.45rem;
  border-radius: var(--radius-sm);
  overflow: hidden;
  /* Strong border so the light swatch stays legible on a light card. */
  border: 1px solid var(--color-border-strong);
}

.theme__bar {
  display: block;
  height: 0.85rem;
}

.theme__body {
  display: block;
  height: calc(100% - 0.85rem);
}

.theme__preview--light .theme__bar { background: #d7dadb; }
.theme__preview--light .theme__body { background: #f4f5f5; }

.theme__preview--dark .theme__bar { background: #1e2221; }
.theme__preview--dark .theme__body { background: #0e100f; }

/* Split down the middle: the system option is whichever the device says. */
.theme__preview--system .theme__bar {
  background: linear-gradient(to right, #d7dadb 50%, #1e2221 50%);
}

.theme__preview--system .theme__body {
  background: linear-gradient(to right, #f4f5f5 50%, #0e100f 50%);
}

.theme__label {
  font-size: 0.92rem;
  font-weight: 500;
}

.theme__hint {
  font-size: 0.8rem;
}

@media (max-width: 48rem) {
  .avatar-row {
    flex-direction: column;
    align-items: flex-start;
  }

  .block {
    padding: 1.1rem 1.15rem 1.25rem;
  }
}
</style>
