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

/**
 * Разделы профиля открываются по одному и поверх страницы, а не лежат на ней
 * четырьмя развёрнутыми формами. Сама страница остаётся списком: с неё видно,
 * что здесь вообще можно поменять, и не приходится прокручивать чужую форму,
 * чтобы добраться до своей.
 */
type Section = 'account' | 'password' | 'appearance' | 'push'

const sheet = ref<Section | null>(null)

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
      // Пустое поле значит «нет адреса»: логин — телефон, а не почта.
      email: form.email || null,
      // Скобки и дефисы — дело показа: на сервер уходит одно число.
      phone: phoneForApi(form.phone),
      job_title: form.job_title || null,
    })
    savedAt.value = new Date().toLocaleTimeString('ru-RU')

    // Раздел закрывается сам: сохранённое видно в шапке и в строке списка, и
    // окно, оставшееся стоять поверх страницы, только прячет свой же итог.
    sheet.value = null
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
    sheet.value = null
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

/**
 * Строка называет раздел, а справа отвечает, что в нём сейчас: оформление —
 * выбранной парой, уведомления — своим состоянием. Ради этого ответа список и
 * заведён: иначе, чтобы узнать, включены ли уведомления, пришлось бы открывать
 * раздел.
 */
const appearanceValue = computed(() => [
  options.find(option => option.value === preference.value)?.label,
  palettes.find(option => option.value === palette.value)?.label,
].filter(Boolean).join(' · '))

const pushValue = computed(() => {
  if (push.needsInstall.value) {
    return 'Нужна установка'
  }

  if (!push.supported.value) {
    return 'Недоступны'
  }

  if (!push.configured.value) {
    return 'Не настроены'
  }

  return push.enabled.value ? 'Включены' : 'Выключены'
})

/*
 * Значки строк — по одному на раздел. Список из четырёх одинаковых строк
 * читается по подписям, а со значками — с одного взгляда, и рука тянется к
 * нужной, не перечитывая соседние.
 *
 * Нарисованы они в разных сетках и заливкой, а не обводкой, поэтому у каждого
 * своя система координат; правило заливки указано только там, где оно задано в
 * исходном значке, — навязанное всем, оно превратило бы дужку замка в дыру.
 */
const ICONS: Record<Section, { box: string, rule?: 'evenodd', paths: string[] }> = {
  account: {
    box: '0 0 16 16',
    rule: 'evenodd',
    paths: ['M8.00259 1.33301C8.87807 1.333 9.74498 1.50544 10.5538 1.84047C11.3627 2.1755 12.0976 2.66656 12.7167 3.28562C13.3357 3.90467 13.8268 4.6396 14.1618 5.44844C14.4968 6.25728 14.6693 7.12418 14.6693 7.99966C14.6693 11.6816 11.6845 14.6664 8.00259 14.6664C4.32072 14.6664 1.33594 11.6816 1.33594 7.99966C1.33594 4.31779 4.32072 1.33301 8.00259 1.33301ZM8.66928 8.66635H7.33594C5.68547 8.66635 4.2685 9.66595 3.65734 11.0929C4.62434 12.4488 6.21022 13.333 8.00259 13.333C9.79497 13.333 11.3808 12.4488 12.3479 11.0928C11.7367 9.66595 10.3198 8.66635 8.66928 8.66635ZM8.00259 3.33301C6.89803 3.33301 6.00259 4.22845 6.00259 5.33301C6.00259 6.43757 6.89803 7.33301 8.00259 7.33301C9.10716 7.33301 10.0026 6.43757 10.0026 5.33301C10.0026 4.22845 9.10719 3.33301 8.00259 3.33301Z'],
  },
  password: {
    box: '0 0 24 24',
    paths: ['M6 22H18C19.1 22 20 21.1 20 20V11C20 9.9 19.1 9 18 9H17V7C17 4.24 14.76 2 12 2C9.24 2 7 4.24 7 7V9H6C4.9 9 4 9.9 4 11V20C4 21.1 4.9 22 6 22ZM9 7C9 5.35 10.35 4 12 4C13.65 4 15 5.35 15 7V9H9V7Z'],
  },
  appearance: {
    box: '0 0 24 24',
    paths: [
      'M10.9717 20.3898C12.1717 19.1898 12.7717 17.2898 12.4917 15.5498C12.2517 14.0498 11.3817 12.8498 10.0517 12.1898C8.78168 11.5498 7.06168 11.6498 5.79168 12.4398C4.62168 13.1598 3.98168 14.3298 3.98168 15.7298C3.98168 16.0298 4.00168 16.3198 4.02168 16.5898C4.10168 17.6198 4.13168 18.0098 2.55168 18.7998C2.23168 18.9598 2.02168 19.2698 2.00168 19.6198C1.98168 19.9698 2.14168 20.3098 2.43168 20.5098C3.43168 21.1998 5.19168 21.9998 7.05168 21.9998C8.40168 21.9998 9.79168 21.5798 10.9717 20.3998V20.3898Z',
      'M16.6691 2.91028L8.78906 10.7903C9.39906 10.8603 9.97906 11.0203 10.4991 11.2803C12.0091 12.0403 13.0191 13.3603 13.3891 15.0103L21.0791 7.32028C22.2991 6.10028 22.2991 4.12028 21.0791 2.91028C19.8591 1.70028 17.8791 1.69028 16.6691 2.91028Z',
    ],
  },
  push: {
    box: '0 0 16 16',
    paths: ['M13.7498 10.6659C13.698 10.6034 13.647 10.5409 13.597 10.4806C12.9095 9.64906 12.4936 9.14719 12.4936 6.79313C12.4936 5.57438 12.202 4.57437 11.6273 3.82437C11.2036 3.27031 10.6308 2.85 9.87576 2.53938C9.86605 2.53397 9.85737 2.52688 9.85014 2.51844C9.57858 1.60906 8.83545 1 7.99733 1C7.1592 1 6.41639 1.60906 6.14483 2.5175C6.13759 2.52565 6.12903 2.53251 6.11951 2.53781C4.35764 3.26312 3.50139 4.65469 3.50139 6.79219C3.50139 9.14719 3.08608 9.64906 2.39795 10.4797C2.34795 10.54 2.29701 10.6012 2.24514 10.665C2.11114 10.8266 2.02624 11.0232 2.00049 11.2316C1.97473 11.4399 2.00921 11.6513 2.09983 11.8406C2.29264 12.2469 2.70358 12.4991 3.17264 12.4991H12.8255C13.2923 12.4991 13.7005 12.2472 13.8939 11.8428C13.9849 11.6534 14.0197 11.4419 13.9942 11.2333C13.9686 11.0247 13.8838 10.8278 13.7498 10.6659ZM7.99733 15C8.4489 14.9996 8.89196 14.8771 9.2795 14.6453C9.66705 14.4135 9.98463 14.0811 10.1986 13.6834C10.2087 13.6644 10.2136 13.643 10.213 13.6215C10.2124 13.5999 10.2063 13.5789 10.1951 13.5604C10.184 13.542 10.1683 13.5267 10.1495 13.5161C10.1307 13.5055 10.1095 13.5 10.088 13.5H5.90733C5.88574 13.4999 5.8645 13.5054 5.84568 13.516C5.82686 13.5266 5.8111 13.5418 5.79993 13.5603C5.78876 13.5788 5.78256 13.5998 5.78194 13.6214C5.78132 13.643 5.7863 13.6644 5.79639 13.6834C6.01031 14.0811 6.32784 14.4134 6.71533 14.6452C7.10281 14.877 7.54581 14.9996 7.99733 15Z'],
  },
}

/**
 * Учётная запись и это устройство разведены по двум спискам: одно правится для
 * всех, кто вас видит, второе живёт в этом браузере и на другом устройстве
 * будет своим. Раньше об этом говорила оговорка под заголовком карточки, теперь
 * — сама разбивка.
 */
const groups = computed(() => [
  {
    caption: 'Учётная запись',
    items: [
      {
        key: 'account' as const,
        label: 'Данные',
        value: savedAt.value ? `Сохранено в ${savedAt.value}` : null,
      },
      {
        key: 'password' as const,
        label: 'Пароль',
        value: passwordChangedAt.value ? `Изменён в ${passwordChangedAt.value}` : null,
      },
    ],
  },
  {
    caption: 'Это устройство',
    items: [
      { key: 'appearance' as const, label: 'Оформление', value: appearanceValue.value },
      { key: 'push' as const, label: 'Уведомления', value: pushValue.value },
    ],
  },
])
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

    <!--
      Дальше страница — список разделов, а не стопка развёрнутых форм. С него
      видно, что здесь вообще можно поменять, и не приходится прокручивать
      чужую форму, чтобы добраться до своей; сама правка открывается окном
      поверх страницы.
    -->
    <div class="groups">
      <section v-for="group in groups" :key="group.caption" class="group">
        <h2 class="group__caption">
          {{ group.caption }}
        </h2>

        <div class="card card--raised list">
          <button
            v-for="item in group.items"
            :key="item.key"
            type="button"
            class="entry"
            @click="sheet = item.key"
          >
            <span class="entry__tile" aria-hidden="true">
              <svg
                :viewBox="ICONS[item.key].box"
                :fill-rule="ICONS[item.key].rule"
                :clip-rule="ICONS[item.key].rule"
                width="17"
                height="17"
                fill="currentColor"
              >
                <path v-for="path in ICONS[item.key].paths" :key="path" :d="path" />
              </svg>
            </span>

            <span class="entry__label">{{ item.label }}</span>

            <span v-if="item.value" class="entry__value">{{ item.value }}</span>

            <svg
              class="entry__chevron"
              viewBox="0 0 24 24"
              width="16"
              height="16"
              fill="none"
              stroke="currentColor"
              stroke-width="1.8"
              stroke-linecap="round"
              stroke-linejoin="round"
              aria-hidden="true"
            >
              <path d="m9.5 6 6 6-6 6" />
            </svg>
          </button>
        </div>
      </section>
    </div>

    <AppSheet
      :open="sheet === 'account'"
      title="Данные"
      hint="Имя и адрес, под которыми вас видят в системе."
      @close="sheet = null"
    >
      <p v-if="generalError" class="alert alert--danger" role="alert">
        {{ generalError }}
      </p>

      <form class="form" novalidate @submit.prevent="save">
        <!-- Поля идут в одну колонку: окно узкое, и пара коротких полей в ряд
             оставляла бы обоим по половине телефонного экрана. -->
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
          <label class="field-label" for="email">
            Email <span class="field-optional">— если есть</span>
          </label>
          <input id="email" v-model.trim="form.email" type="email" class="input" autocomplete="email">
          <p v-if="errors.email?.length" class="field-error">
            {{ errors.email[0] }}
          </p>
        </div>

        <div class="field">
          <label class="field-label" for="phone">
            Телефон <span class="field-optional">— по нему вы входите</span>
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

        <div class="form__actions">
          <button type="submit" class="button-primary" :disabled="isSaving">
            {{ isSaving ? 'Сохраняем…' : 'Сохранить' }}
          </button>
        </div>
      </form>
    </AppSheet>

    <AppSheet
      :open="sheet === 'password'"
      title="Пароль"
      hint="Не короче восьми знаков. После смены войти останется только здесь — на остальных устройствах спросят заново."
      @close="sheet = null"
    >
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
        </div>
      </form>
    </AppSheet>

    <!--
      Тема и палитра стоят в одном окне: обе описывают не учётную запись, а
      экран перед вами, и выбирают их разом — светлый экран или тёмный, и
      какими цветами он нарисован.
    -->
    <AppSheet
      :open="sheet === 'appearance'"
      title="Оформление"
      hint="Настройки этого браузера, а не аккаунта: на телефоне и на компьютере они свои."
      @close="sheet = null"
    >
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
      </div>
    </AppSheet>

    <AppSheet
      :open="sheet === 'push'"
      title="Уведомления"
      hint="Подписка живёт в самом устройстве: на телефоне её включают отдельно от рабочего компьютера."
      @close="sheet = null"
    >
      <p v-if="push.error.value" class="alert alert--danger" role="alert">
        {{ push.error.value }}
      </p>

      <div class="note">
        <p class="note__text">
          {{ pushHint }}
        </p>

        <button
          v-if="canTogglePush && push.enabled.value"
          type="button"
          class="button-secondary"
          :disabled="push.isBusy.value"
          @click="push.disable()"
        >
          {{ push.isBusy.value ? 'Выключаем…' : 'Выключить' }}
        </button>
        <button
          v-else-if="canTogglePush"
          type="button"
          class="button-primary"
          :disabled="push.isBusy.value || push.permission.value === 'denied'"
          @click="push.enable()"
        >
          {{ push.isBusy.value ? 'Включаем…' : 'Включить' }}
        </button>
      </div>
    </AppSheet>
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

  /*
   * Скругление подложка режет себе сама, и только сверху. Размытие выносит
   * снимок в отдельный слой, а его Safari на iPhone обрезает по прямоугольнику:
   * ни `overflow` карточки, ни `border-radius` такому слою не указ. Верхние углы
   * шапки выходили из-за этого рублеными; `border-radius` их не спасал, а низ
   * подложки только портил — затухание в нижних углах он срезал, размытие
   * оставлял, и в прорехи проступал снимок. `clip-path` действует на оба слоя
   * разом, а низ подложки резать незачем: там она уже сошла в цвет карточки.
   */
  clip-path: inset(0 round var(--radius-lg) var(--radius-lg) 0 0);
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
 * Список разделов. Две группы, а не одна: учётная запись правится для всех, кто
 * вас видит, а оформление с уведомлениями живут в этом браузере и на другом
 * устройстве будут своими. Раньше об этом говорила оговорка под заголовком
 * карточки, теперь — сама разбивка.
 */
.groups {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 1.4rem;
}

/* Рядом, когда есть куда: два коротких списка друг под другом оставляли бы
   полстраницы воздуха справа. */
@media (min-width: 68rem) {
  .groups {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    align-items: start;
  }
}

.group {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

/* Подпись группы стоит над карточкой и говорит вполголоса: она делит список, а
   не соперничает с именами разделов внутри. */
.group__caption {
  margin: 0;
  padding-left: 0.35rem;
  color: var(--color-text-muted);
  font-size: 0.82rem;
  font-weight: 500;
  letter-spacing: 0.02em;
  text-transform: uppercase;
}

.list {
  overflow: hidden;
}

/*
 * Строка целиком — кнопка: на телефоне попадают пальцем, а не в подпись, и
 * промахнуться мимо неё негде. Стрелка справа обещает, что раздел откроется, а
 * не переключится на месте.
 */
.entry {
  position: relative;
  display: flex;
  align-items: center;
  gap: 0.85rem;
  width: 100%;
  padding: 0.9rem 1.1rem;
  border: 0;
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
  transition: background-color 0.15s ease;
}

/* Линия начинается там же, где подпись: доведённая до самого края, она резала
   бы список на клетки, а так — просто отделяет строку от строки. */
.entry + .entry::before {
  content: '';
  position: absolute;
  top: 0;
  right: 0;
  left: 3.85rem;
  border-top: 1px solid var(--color-border);
}

.entry:hover {
  background: var(--color-surface-sunken);
}

/*
 * Значок сидит в плитке — скруглённом квадрате, как в списках настроек на
 * телефоне: он выравнивает строки по общей левой границе и разводит значки, у
 * которых разный вес и своя сетка. Цвета берутся из палитры — приглушённая
 * подложка главного цвета и он же в самом значке, — поэтому список остаётся
 * своим и в светлой теме, и в тёмной, и в любой из палитр.
 */
.entry__tile {
  display: grid;
  place-items: center;
  flex-shrink: 0;
  width: 1.9rem;
  height: 1.9rem;
  border-radius: var(--radius-sm);
  background: var(--color-accent-soft);
  color: var(--color-accent);
}

.entry__label {
  flex: 1;
  min-width: 0;
  font-size: 0.98rem;
}

/* Ответ строки — что в разделе сейчас — прижат к стрелке и приглушён: его
   читают вторым, после названия. */
.entry__value {
  min-width: 0;
  color: var(--color-text-muted);
  font-size: 0.88rem;
  text-align: right;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.entry__chevron {
  flex-shrink: 0;
  color: var(--color-text-faint);
}

/* Пояснение и кнопка в окне уведомлений: строка настройки без второй половины
   осталась бы подписью, висящей над пустым местом. */
.note {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.9rem;
}

.note__text {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.92rem;
  line-height: 1.5;
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
  .hero__body {
    padding: 2.1rem 1.15rem 1.5rem;
  }

  /* Переключателю нужна вся ширина, а рядом с подписью её не остаётся: строка
     разворачивается в две — подпись, под ней полоса. */
  .row {
    flex-direction: column;
    align-items: stretch;
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
