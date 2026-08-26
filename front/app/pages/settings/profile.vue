<script setup lang="ts">
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'
import type { ThemePreference } from '~/composables/useTheme'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Профиль' })

const { user, updateProfile, changePassword, uploadAvatar, removeAvatar } = useAuth()
const { preference, setTheme, options } = useTheme()

const form = reactive({
  last_name: user.value?.last_name ?? '',
  first_name: user.value?.first_name ?? '',
  middle_name: user.value?.middle_name ?? '',
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
    await updateProfile({
      last_name: form.last_name,
      first_name: form.first_name,
      // An empty box means "no patronymic", not an empty string.
      middle_name: form.middle_name || null,
      email: form.email,
    })
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

const password = reactive({
  current_password: '',
  password: '',
  password_confirmation: '',
})

const passwordErrors = ref<ValidationErrors>({})
const passwordError = ref<string | null>(null)
const isChangingPassword = ref(false)
const passwordChangedAt = ref<string | null>(null)

async function savePassword() {
  isChangingPassword.value = true
  passwordErrors.value = {}
  passwordError.value = null
  passwordChangedAt.value = null

  try {
    await changePassword({ ...password })

    // Nothing typed here is worth keeping once it has been accepted.
    password.current_password = ''
    password.password = ''
    password.password_confirmation = ''

    passwordChangedAt.value = new Date().toLocaleTimeString('ru-RU')
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      passwordErrors.value = caught.errors
    }
    else {
      passwordError.value = 'Не удалось сменить пароль.'
    }
  }
  finally {
    isChangingPassword.value = false
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

    <div class="blocks">
      <div class="blocks__side">
        <section class="card card--raised block">
          <header class="block__head">
            <h2 class="block__title">
              Фото
            </h2>
            <p class="block__hint">
              PNG, JPG или WebP, до 4 МБ. Без фото показываются инициалы.
            </p>
          </header>

          <div class="avatar-row">
            <UserAvatar :name="user?.name" :src="user?.avatar_url" :size="72" />

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
            </div>
          </div>

          <p v-if="avatarError" class="alert alert--danger" role="alert">
            {{ avatarError }}
          </p>
        </section>

        <section class="card card--raised block">
          <header class="block__head">
            <h2 class="block__title">
              Пароль
            </h2>
            <p class="block__hint">
              Не короче восьми знаков. После смены войти останется только здесь — на остальных устройствах спросят заново.
            </p>
          </header>

          <p v-if="passwordError" class="alert alert--danger" role="alert">
            {{ passwordError }}
          </p>

          <form class="form" novalidate @submit.prevent="savePassword">
            <!-- Не для чтения, а для менеджеров паролей: без имени учётной
                 записи рядом они не понимают, чей пароль им предлагают заменить. -->
            <input
              :value="user?.email"
              type="text"
              class="visually-hidden"
              autocomplete="username"
              tabindex="-1"
              aria-hidden="true"
              readonly
            >

            <div class="field">
              <label class="field-label" for="current-password">Текущий пароль</label>
              <input
                id="current-password"
                v-model="password.current_password"
                type="password"
                class="input"
                autocomplete="current-password"
              >
              <p v-if="passwordErrors.current_password?.length" class="field-error">
                {{ passwordErrors.current_password[0] }}
              </p>
            </div>

            <div class="field">
              <label class="field-label" for="new-password">Новый пароль</label>
              <input
                id="new-password"
                v-model="password.password"
                type="password"
                class="input"
                autocomplete="new-password"
              >
              <p v-if="passwordErrors.password?.length" class="field-error">
                {{ passwordErrors.password[0] }}
              </p>
            </div>

            <div class="field">
              <label class="field-label" for="repeat-password">Ещё раз</label>
              <input
                id="repeat-password"
                v-model="password.password_confirmation"
                type="password"
                class="input"
                autocomplete="new-password"
              >
            </div>

            <div class="form__actions">
              <button type="submit" class="button-primary" :disabled="isChangingPassword">
                {{ isChangingPassword ? 'Меняем…' : 'Сменить пароль' }}
              </button>
              <span v-if="passwordChangedAt" class="faint">Изменён в {{ passwordChangedAt }}</span>
            </div>
          </form>
        </section>

        <section class="card card--raised block">
          <header class="block__head">
            <h2 class="block__title">
              Тема
            </h2>
            <p class="block__hint">
              Настройка этого браузера, а не аккаунта: она описывает экран перед вами.
            </p>
          </header>

          <div class="segmented" role="radiogroup" aria-label="Тема оформления">
            <button
              v-for="option in options"
              :key="option.value"
              type="button"
              role="radio"
              class="segmented__option"
              :class="{ 'segmented__option--on': preference === option.value }"
              :aria-checked="preference === option.value"
              @click="choose(option.value)"
            >
              <svg
                class="segmented__icon"
                viewBox="0 0 24 24"
                width="16"
                height="16"
                fill="none"
                stroke="currentColor"
                stroke-width="1.6"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
              >
                <template v-if="option.value === 'system'">
                  <rect x="3" y="4" width="18" height="13" rx="2" />
                  <path d="M9 20h6" />
                </template>
                <template v-else-if="option.value === 'light'">
                  <circle cx="12" cy="12" r="4" />
                  <path d="M12 3v2M12 19v2M3 12h2M19 12h2M5.6 5.6l1.4 1.4M17 17l1.4 1.4M18.4 5.6 17 7M7 17l-1.4 1.4" />
                </template>
                <template v-else>
                  <path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z" />
                </template>
              </svg>
              {{ option.label }}
            </button>
          </div>
        </section>
      </div>

      <section class="card card--raised block">
        <header class="block__head">
          <h2 class="block__title">
            Данные
          </h2>
          <p class="block__hint">
            Имя и адрес, под которыми вас видят в системе.
          </p>
        </header>

        <p v-if="generalError" class="alert alert--danger" role="alert">
          {{ generalError }}
        </p>

        <form class="form" novalidate @submit.prevent="save">
          <div class="field">
            <label class="field-label" for="last-name">Фамилия</label>
            <input id="last-name" v-model.trim="form.last_name" class="input" autocomplete="family-name">
            <p v-if="errors.last_name?.length" class="field-error">
              {{ errors.last_name[0] }}
            </p>
          </div>

          <div class="field">
            <label class="field-label" for="first-name">Имя</label>
            <input id="first-name" v-model.trim="form.first_name" class="input" autocomplete="given-name">
            <p v-if="errors.first_name?.length" class="field-error">
              {{ errors.first_name[0] }}
            </p>
          </div>

          <div class="field">
            <label class="field-label" for="middle-name">
              Отчество <span class="field-optional">— если есть</span>
            </label>
            <input id="middle-name" v-model.trim="form.middle_name" class="input" autocomplete="additional-name">
            <p v-if="errors.middle_name?.length" class="field-error">
              {{ errors.middle_name[0] }}
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
    </div>
  </section>
</template>

<style scoped>
.head {
  margin-bottom: 1.75rem;
}

/*
 * The page fills its column, like the other settings screens do — capping it
 * at a reading measure left the content huddled against the left edge with the
 * header stretching past it, and made the heading jump when you switched tabs.
 *
 * One column until there is room for two. Above that the account form takes a
 * column of its own and the rest stack beside it, which is what keeps the two
 * sides close to the same height.
 */
.blocks {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 1rem;
}

/*
 * The blocks beside the form are a column of their own rather than rows of the
 * page grid. As grid rows they would be pulled apart: the tall form spanning
 * all of them stretches each one, and gaps open between the photo, the password
 * and the theme. Until there is a second column the wrapper is not a layout at
 * all and its cards simply join the single stack.
 */
.blocks__side {
  display: contents;
}

@media (min-width: 68rem) {
  .blocks {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    /* Each column keeps its own height instead of stretching to the other. */
    align-items: start;
  }

  .blocks__side {
    display: flex;
    flex-direction: column;
    gap: 1rem;
  }
}

/*
 * Every block is the same shape — heading, one line of explanation, then the
 * controls — so the cards line up down the page instead of each finding its
 * own rhythm.
 */
.block {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
  padding: 1.4rem 1.5rem;
}

.block__head {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
}

.block__title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 600;
}

.block__hint {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.87rem;
}

.avatar-row {
  display: flex;
  align-items: center;
  gap: 1.1rem;
}

.avatar-row__actions {
  display: flex;
  align-items: center;
  gap: 0.5rem;
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

/* Marks the one field that may be left empty, so nobody hunts for a rule that
   is not there. Quieter than the label it follows. */
.field-optional {
  color: var(--color-text-faint);
  font-weight: 400;
}

.form__actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

/*
 * Three mutually exclusive answers to one question, so they share a single
 * track: the choice reads as a position along it rather than as three cards
 * competing for attention. The chosen segment is a raised lozenge — the same
 * tone a card is — which marks it without spending the accent colour on a
 * setting the reader touches once.
 */
.segmented {
  display: inline-flex;
  align-self: flex-start;
  max-width: 100%;
  padding: 0.25rem;
  gap: 0.15rem;
  border-radius: var(--radius-pill);
  background: var(--color-surface-sunken);
}

.segmented__option {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.4rem;
  padding: 0.45rem 0.95rem;
  border: 0;
  border-radius: var(--radius-pill);
  background: transparent;
  color: var(--color-text-muted);
  font: inherit;
  font-size: 0.9rem;
  white-space: nowrap;
  cursor: pointer;
  transition: background-color 0.15s ease, color 0.15s ease;
}

.segmented__option:hover:not(.segmented__option--on) {
  color: var(--color-text);
}

.segmented__option--on {
  background: var(--color-surface-raised);
  color: var(--color-text);
  font-weight: 500;
  box-shadow: var(--shadow-sm);
}

.segmented__icon {
  flex-shrink: 0;
}

@media (max-width: 48rem) {
  .block {
    padding: 1.15rem 1.15rem 1.25rem;
  }

  /* Nothing left to give: the group stops hugging its labels and shares the
     full width, so three segments still fit on a narrow screen. */
  .segmented {
    align-self: stretch;
  }

  /* Grow from the label's own width rather than from zero: an equal share
     would be narrower than "Системная" and the word would spill out. */
  .segmented__option {
    flex: 1 1 auto;
    padding: 0.45rem 0.5rem;
  }
}

/* On the narrowest screens the icons go rather than the words: a label names
   the choice outright, an icon only hints at it. */
@media (max-width: 24rem) {
  .segmented__icon {
    display: none;
  }
}
</style>
