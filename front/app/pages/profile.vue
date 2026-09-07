<script setup lang="ts">
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'
import type { PalettePreference, ThemePreference } from '~/composables/useTheme'
import { applyMask } from '~/utils/maskedInput'
import { maskPhone, phoneForApi } from '~/utils/phone'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Профиль' })

const { user, updateProfile, changePassword, uploadAvatar, removeAvatar } = useAuth()
const { preference, palette, setTheme, setPalette, options, palettes } = useTheme()

/*
 * Уведомления — настройка этого устройства, а не учётной записи: подписка
 * живёт в браузере, и на телефоне её включают отдельно от рабочего компьютера.
 */
const push = usePushNotifications()

// Состояние общее с полосой-предложением; она спрашивает его при загрузке,
// и второй раз ходить незачем.
onMounted(() => {
  if (!push.asked.value) {
    void push.refresh()
  }
})

const form = reactive({
  last_name: user.value?.last_name ?? '',
  first_name: user.value?.first_name ?? '',
  middle_name: user.value?.middle_name ?? '',
  email: user.value?.email ?? '',
  // Хранится «+79990009977», а правится в том же виде, в каком набирается.
  phone: maskPhone(user.value?.phone ?? ''),
  job_title: user.value?.job_title ?? '',
})

/** Телефон набирается под маской — тем же правилом, что и в других формах. */
function onPhoneInput(event: Event) {
  form.phone = applyMask(event.target as HTMLInputElement, maskPhone)
}

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
      // Скобки и дефисы — дело показа: на сервер уходит одно число.
      phone: phoneForApi(form.phone),
      job_title: form.job_title || null,
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

function choosePalette(value: PalettePreference) {
  setPalette(value)
}

/**
 * Строка под именем — то, чем человека находят: телефон и почта через точку.
 * Пустые не показываются, поэтому у нового сотрудника под именем не висит
 * половина разделителя.
 */
const contacts = computed(() => [
  user.value?.phone ? maskPhone(user.value.phone) : null,
  user.value?.email ?? null,
].filter(Boolean).join(' · '))

/**
 * Фотография служит шапке подложкой — размытой до пятна и притушенной. Узнать
 * её там нельзя, да и незачем: она задаёт шапке тон, а сам снимок показан
 * кружком поверх. Без фотографии остаётся спокойная заливка из палитры.
 */
const coverImage = computed(() =>
  user.value?.avatar_url ? `url("${user.value.avatar_url}")` : undefined,
)

/**
 * Уведомления описываются одной строкой вместо лесенки условий в разметке:
 * в карточке это такая же строка настройки, как схема и палитра.
 */
const pushHint = computed(() => {
  // На iPhone уведомления получает только приложение, добавленное на домашний
  // экран: в самом Safari их не бывает вовсе. Сказать об этом честнее, чем
  // «браузер не умеет».
  if (push.needsInstall.value) {
    return 'Добавьте приложение на домашний экран — тогда их можно будет включить.'
  }

  if (!push.supported.value) {
    return 'Этот браузер не умеет присылать уведомления.'
  }

  if (!push.configured.value) {
    return 'Пока не настроены на сервере.'
  }

  return push.enabled.value
    ? 'Сообщения из мессенджера и новости приходят на это устройство.'
    : 'Включите, чтобы сообщения приходили, даже когда приложение закрыто.'
})

/** Кнопка показывается только там, где нажатие и правда что-то изменит. */
const canTogglePush = computed(() =>
  !push.needsInstall.value && push.supported.value && push.configured.value,
)
</script>

<template>
  <section class="profile">
    <!--
      Шапка и есть заголовок страницы: имя человека говорит, чей это профиль,
      точнее слова «Профиль». Всё, что относится к самому человеку — снимок,
      имя, должность, телефон с почтой, — собрано в ней, а карточки ниже
      остаются тем, что правят.
    -->
    <header class="hero card card--raised">
      <div class="hero__cover" :style="{ '--cover': coverImage }" aria-hidden="true" />

      <div class="hero__body">
        <div class="hero__photo">
          <UserAvatar :name="user?.name" :src="user?.avatar_url" :size="112" />

          <button
            type="button"
            class="hero__camera"
            :disabled="isUploadingAvatar"
            :aria-label="user?.avatar_url ? 'Заменить фотографию' : 'Загрузить фотографию'"
            :title="user?.avatar_url ? 'Заменить фотографию' : 'Загрузить фотографию'"
            @click="avatarInput?.click()"
          >
            <svg
              viewBox="0 0 24 24"
              width="17"
              height="17"
              fill="none"
              stroke="currentColor"
              stroke-width="1.7"
              stroke-linecap="round"
              stroke-linejoin="round"
              aria-hidden="true"
            >
              <path d="M4 8h3l1.5-2h7L17 8h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1Z" />
              <circle cx="12" cy="13" r="3.4" />
            </svg>
          </button>

          <input
            ref="avatarInput"
            type="file"
            accept="image/png,image/jpeg,image/webp"
            class="visually-hidden"
            @change="onAvatarChosen"
          >
        </div>

        <h1 class="hero__name">
          {{ user?.name }}
        </h1>

        <p v-if="user?.job_title" class="hero__job">
          {{ user.job_title }}
        </p>

        <p v-if="contacts" class="hero__contacts">
          {{ contacts }}
        </p>

        <div class="hero__marks">
          <span v-if="user && user.level !== 'user'" class="badge badge--highlight">
            {{ user.level_label }}
          </span>
          <span v-for="department in user?.departments ?? []" :key="department.id" class="badge">
            {{ department.name }}
          </span>
        </div>

        <div class="hero__actions">
          <span v-if="isUploadingAvatar" class="faint">Загружаем фото…</span>
          <template v-else>
            <span class="faint">PNG, JPG или WebP, до 4 МБ</span>
            <button v-if="user?.avatar_url" type="button" class="button-ghost button-sm" @click="dropAvatar">
              Удалить фото
            </button>
          </template>
        </div>

        <p v-if="avatarError" class="alert alert--danger hero__alert" role="alert">
          {{ avatarError }}
        </p>
      </div>
    </header>

    <div class="blocks">
      <section class="card card--raised block block--wide">
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
          <!-- Короткие поля стоят парами: столбец в полстраницы, а под каждым
               именем — строка на всю ширину, и форма растягивается вдвое
               длиннее, чем в ней написано. -->
          <div class="form__grid">
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
              <label class="field-label" for="job-title">
                Должность <span class="field-optional">— если есть</span>
              </label>
              <input id="job-title" v-model.trim="form.job_title" class="input" autocomplete="organization-title">
              <p v-if="errors.job_title?.length" class="field-error">
                {{ errors.job_title[0] }}
              </p>
            </div>

            <div class="field">
              <label class="field-label" for="email">Email</label>
              <input id="email" v-model.trim="form.email" type="email" class="input" autocomplete="email">
              <p v-if="errors.email?.length" class="field-error">
                {{ errors.email[0] }}
              </p>
            </div>

            <div class="field">
              <label class="field-label" for="phone">
                Телефон <span class="field-optional">— если есть</span>
              </label>
              <input
                id="phone"
                :value="form.phone"
                type="tel"
                inputmode="tel"
                class="input"
                autocomplete="tel"
                placeholder="+7 (999) 000-99-77"
                @input="onPhoneInput"
              >
              <p v-if="errors.phone?.length" class="field-error">
                {{ errors.phone[0] }}
              </p>
            </div>
          </div>

          <div class="form__actions">
            <button type="submit" class="button-primary" :disabled="isSaving">
              {{ isSaving ? 'Сохраняем…' : 'Сохранить' }}
            </button>
            <span v-if="savedAt" class="faint">Сохранено в {{ savedAt }}</span>
          </div>
        </form>
      </section>

      <div class="blocks__side">
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

        <!--
          Тема и уведомления собраны в одну карточку: обе описывают не учётную
          запись, а экран перед вами — тема живёт в куке браузера, подписка на
          уведомления в самом устройстве. Порознь они читались как две разные
          настройки, и обе объясняли это одной и той же оговоркой.
        -->
        <section class="card card--raised block">
          <header class="block__head">
            <h2 class="block__title">
              Это устройство
            </h2>
            <p class="block__hint">
              Настройки этого браузера, а не аккаунта: на телефоне и на компьютере они свои.
            </p>
          </header>

          <p v-if="push.error.value" class="alert alert--danger" role="alert">
            {{ push.error.value }}
          </p>

          <div class="rows">
            <div class="row">
              <div class="row__text">
                <span class="row__label">Схема</span>
                <span class="row__hint">Светлый экран или тёмный.</span>
              </div>

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
            </div>

            <!-- Вторая ось, независимая от первой: схема говорит, светлый экран
                 или тёмный, палитра — какими цветами он нарисован. Две точки
                 рядом с названием отвечают «как это будет выглядеть» до нажатия. -->
            <div class="row">
              <div class="row__text">
                <span class="row__label">Палитра</span>
                <span class="row__hint">Какими цветами он нарисован.</span>
              </div>

              <div class="segmented" role="radiogroup" aria-label="Цветовая палитра">
                <button
                  v-for="option in palettes"
                  :key="option.value"
                  type="button"
                  role="radio"
                  class="segmented__option"
                  :class="{ 'segmented__option--on': palette === option.value }"
                  :aria-checked="palette === option.value"
                  @click="choosePalette(option.value)"
                >
                  <span class="swatch" aria-hidden="true">
                    <i class="swatch__dot" :style="{ background: option.swatch[0] }" />
                    <i class="swatch__dot" :style="{ background: option.swatch[1] }" />
                  </span>
                  {{ option.label }}
                </button>
              </div>
            </div>

            <div class="row">
              <div class="row__text">
                <span class="row__label">Уведомления</span>
                <span class="row__hint">{{ pushHint }}</span>
              </div>

              <button
                v-if="canTogglePush && push.enabled.value"
                type="button"
                class="button-secondary button-sm"
                :disabled="push.isBusy.value"
                @click="push.disable()"
              >
                {{ push.isBusy.value ? 'Выключаем…' : 'Выключить' }}
              </button>
              <button
                v-else-if="canTogglePush"
                type="button"
                class="button-primary button-sm"
                :disabled="push.isBusy.value || push.permission.value === 'denied'"
                @click="push.enable()"
              >
                {{ push.isBusy.value ? 'Включаем…' : 'Включить' }}
              </button>
            </div>
          </div>
        </section>
      </div>
    </div>
  </section>
</template>

<style scoped>
/* ---------- Шапка ---------- */

.hero {
  position: relative;
  overflow: hidden;
  margin-bottom: 1rem;
}

/*
 * Подложка шапки. Снимок размывается до пятна и растягивается за края — иначе
 * по краю блюра осталась бы светлая кайма, — а книзу гаснет в цвет карточки,
 * чтобы имя читалось на ровном тоне, каким бы ни было фото. Без фотографии
 * остаётся спокойная заливка из палитры, и шапка не проваливается.
 */
.hero__cover {
  position: absolute;
  inset: 0 0 auto;
  height: 14rem;
  overflow: hidden;
}

.hero__cover::before {
  content: '';
  position: absolute;
  inset: -25%;
  background: var(--cover, linear-gradient(120deg, var(--color-accent-soft), var(--color-surface-sunken))) center / cover no-repeat;
  filter: blur(2.25rem) saturate(1.35);
  opacity: 0.55;
}

/*
 * Затухание кончается выше нижнего края подложки, а не ровно на нём: на
 * светлой теме градиент, доведённый до самого низа, оставлял по границе
 * заметную ступеньку — тон карточки он набирал уже за пределами видимого.
 */
.hero__cover::after {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(to bottom, transparent, var(--color-surface-raised) 78%);
}

.hero__body {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.4rem;
  padding: 2.5rem 1.5rem 1.75rem;
  text-align: center;
}

/*
 * Кольцо тоном карточки отделяет снимок от подложки: без него тёмное фото
 * сливается с собственным размытым пятном.
 */
.hero__photo {
  position: relative;
  display: grid;
  margin-bottom: 0.7rem;
  border-radius: var(--radius-pill);
  box-shadow: 0 0 0 5px var(--color-surface-raised), var(--shadow-md);
}

/* Замена фотографии — на самой фотографии: так её и меняют везде, и отдельная
   кнопка «Загрузить» не нужна ни в шапке, ни карточкой ниже. */
.hero__camera {
  position: absolute;
  right: -1px;
  bottom: -1px;
  display: grid;
  place-items: center;
  width: 2.2rem;
  height: 2.2rem;
  padding: 0;
  border: 0;
  border-radius: var(--radius-pill);
  background: var(--color-accent);
  color: var(--color-accent-text);
  box-shadow: 0 0 0 4px var(--color-surface-raised);
  cursor: pointer;
  transition: background-color 0.15s ease;
}

.hero__camera:hover:not(:disabled) {
  background: var(--color-accent-hover);
}

.hero__camera:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.hero__name {
  margin: 0;
  font-size: 1.9rem;
  font-weight: 400;
  line-height: 1.15;
  letter-spacing: -0.03em;
}

.hero__job {
  margin: 0;
  color: var(--color-text-muted);
}

/* Телефон и почта — одной строкой под именем: то, чем человека находят, стоит
   рядом с тем, как его зовут, а не в полях формы ниже. */
.hero__contacts {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.95rem;
  overflow-wrap: anywhere;
}

.hero__marks {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 0.35rem;
}

.hero__marks:empty {
  display: none;
}

.hero__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  margin-top: 0.7rem;
  font-size: 0.85rem;
}

.hero__alert {
  margin-top: 0.9rem;
}

/*
 * The page fills its column, like the other settings screens do — capping it
 * at a reading measure left the content huddled against the left edge with the
 * header stretching past it, and made the heading jump when you switched tabs.
 *
 * One column until there is room for two. Above that the account form takes the
 * full width — its own fields pair up inside it — and the two short cards stand
 * side by side underneath, which is what keeps the page from ending in a column
 * of air beside a tall stack.
 */
.blocks {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 1rem;
}

/* Обёртка ничего не раскладывает: её карточки — прямые ячейки сетки страницы,
   иначе высота одной подгоняла бы под себя соседнюю. */
.blocks__side {
  display: contents;
}

@media (min-width: 68rem) {
  .blocks {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    /* Each card keeps its own height instead of stretching to its neighbour. */
    align-items: start;
  }

  .block--wide {
    grid-column: 1 / -1;
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

/*
 * Строки настройки: подпись с пояснением слева, сам переключатель справа.
 * Разделены волосяной линией, а не воздухом, — иначе три подписи подряд
 * читаются как один список из восьми положений.
 */
.rows {
  display: flex;
  flex-direction: column;
}

.row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.6rem 1rem;
  padding: 0.95rem 0;
}

.row + .row {
  border-top: 1px solid var(--color-border);
}

.row:first-child {
  padding-top: 0;
}

.row:last-child {
  padding-bottom: 0;
}

.row__text {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  min-width: 0;
}

.row__label {
  font-size: 0.95rem;
  font-weight: 500;
}

.row__hint {
  color: var(--color-text-muted);
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

/*
 * Поля стоят рядами по два, а на широком экране — по три: шесть коротких строк
 * делятся на ряды нацело, и форма нигде не кончается одиноким полем в новой
 * строке. Число колонок задано, а не подобрано `auto-fit`: тот набивал пять
 * полей в ряд, и шестое оставалось сиротой.
 */
.form__grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 1rem;
}

@media (min-width: 40rem) {
  .form__grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (min-width: 68rem) {
  .form__grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
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

/*
 * Палитра показывается своими цветами, а не названием: «Графит» и «Синяя»
 * ничего не говорят до первого нажатия. Две точки — главное действие и то, чем
 * отмечено важное, — то самое, что и меняется на экране заметнее всего.
 */
.swatch {
  display: inline-flex;
  flex-shrink: 0;
  align-items: center;
}

.swatch__dot {
  width: 0.7rem;
  height: 0.7rem;
  border-radius: 50%;
  /* Обводка тоном карточки: тёмная точка на тёмной полосе иначе сливается с
     ней, а вторая точка — с первой. */
  box-shadow: 0 0 0 1.5px var(--color-surface-sunken);
}

.swatch__dot + .swatch__dot {
  margin-left: -0.25rem;
}

.segmented__option--on .swatch__dot {
  box-shadow: 0 0 0 1.5px var(--color-surface-raised);
}

@media (max-width: 48rem) {
  .block {
    padding: 1.15rem 1.15rem 1.25rem;
  }

  .hero__body {
    padding: 2.1rem 1.15rem 1.5rem;
  }

  /* Переключателю нужна вся ширина, а рядом с подписью её не остаётся: строка
     разворачивается в две — подпись, под ней полоса. */
  .row {
    flex-direction: column;
    align-items: stretch;
  }

  /* Растянуться должна полоса выбора, а не кнопка: она осталась бы шириной в
     экран ради одного слова. */
  .row > button {
    align-self: flex-start;
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
