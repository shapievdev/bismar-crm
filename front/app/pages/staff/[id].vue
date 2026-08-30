<script setup lang="ts">
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'
import type { AccessLevel, PermissionOption, StaffAccountDraft, User } from '~/types/auth'
import { formatDate } from '~/utils/numbers'
import { maskPhone, phoneForApi } from '~/utils/phone'

definePageMeta({ middleware: 'auth', permission: 'users.view' })

const route = useRoute()
const router = useRouter()
const memberId = Number(route.params.id)

const {
  fetchStaffMember,
  fetchUsers,
  fetchPermissions,
  updateUser,
  updateAccess,
  dismissUser,
  reinstateUser,
  deleteUser,
} = useAdminApi()
const { can, isAdmin, isSuperAdmin, user: currentUser } = useAuth()
const { confirm } = useAppDialog()

const canManage = computed(() => can('users.manage'))

/**
 * Чужой план открывает тот, кому доверено обучение (`enrollments.manage`);
 * должность администратора это право включает. Сам сотрудник видит свой план
 * у себя, на «Моём плане».
 */
const canSeePlans = computed(() => can('enrollments.manage'))

/**
 * Карточка человека — то, ради чего сюда заходят; каталог прав и список коллег
 * нужны только тому, кто правит доступ, и без права управлять не спрашиваются.
 */
const { data, pending, error, refresh } = await useAsyncData(
  `staff.member.${memberId}`,
  async () => {
    const [member, permissions, colleagues] = await Promise.all([
      fetchStaffMember(memberId),
      canManage.value ? fetchPermissions() : Promise.resolve({ data: [] }),
      canManage.value ? fetchUsers() : Promise.resolve({ data: [] }),
    ])

    return { member: member.data, permissions: permissions.data, colleagues: colleagues.data }
  },
)

const member = computed<User | null>(() => data.value?.member ?? null)

useHead(() => ({ title: member.value?.name ?? 'Сотрудник' }))

const errorMessage = ref<string | null>(null)
const isSaving = ref(false)
const isBusy = ref(false)

/* ---------- Сведения ---------- */

const isDismissed = computed(() => Boolean(member.value?.dismissed_at))

/** Права, отмеченные лично, — подписями из каталога, а не машинными именами. */
const ownPermissionLabels = computed<string[]>(() => {
  const catalogue = new Map(
    (data.value?.permissions ?? []).map((option: PermissionOption) => [option.name, option.label]),
  )

  return (member.value?.own_permissions ?? []).map(name => catalogue.get(name) ?? name)
})

function permissionsCount(user: User): string {
  const count = user.own_permissions.length

  return count === 0
    ? 'без прав'
    : `${count} ${pluralise(count, 'право', 'права', 'прав')}`
}

/* ---------- План обучения ---------- */

/**
 * План раскрывается кнопкой, а не висит открытым: в карточку заходят чаще за
 * телефоном и должностью, а план — отдельный разговор и целый экран высотой.
 */
const isPlanOpen = ref(false)

/**
 * Менять план — дело должности: администратор и суперадминистратор, и никто
 * ниже, даже с отмеченным правом вести обучение. То же правило проверяет
 * сервер — UpdateLearningPlanRequest::authorize().
 *
 * Уволенному план не меняют и они: проходить назначенное ему уже негде.
 */
const mayEditPlan = computed(() => isAdmin.value && !isDismissed.value)

/* ---------- Учётная запись ---------- */

/**
 * Правка раскрывается под карточкой, а не заменяет её: видно, кого правишь, и
 * закрыв форму, остаёшься там же, где был.
 */
const isEditing = ref(false)
const formErrors = ref<ValidationErrors>({})
const account = ref<StaffAccountDraft>(blankAccount())

function blankAccount(): StaffAccountDraft {
  return { last_name: '', first_name: '', middle_name: '', email: '', phone: '', job_title: '', password: '' }
}

function openAccountForm() {
  const person = member.value

  if (!person) {
    return
  }

  isEditingAccess.value = false
  errorMessage.value = null
  formErrors.value = {}
  account.value = {
    last_name: person.last_name ?? '',
    first_name: person.first_name,
    middle_name: person.middle_name ?? '',
    email: person.email,
    // Хранится «+79990009977», а правится в том же виде, в каком набирается.
    phone: maskPhone(person.phone ?? ''),
    job_title: person.job_title ?? '',
    // Left blank means "leave the current one alone".
    password: '',
  }
  isEditing.value = true
}

async function saveAccount() {
  const person = member.value

  if (!person) {
    return
  }

  isSaving.value = true
  errorMessage.value = null
  formErrors.value = {}

  try {
    await updateUser(person, {
      last_name: account.value.last_name,
      first_name: account.value.first_name,
      middle_name: account.value.middle_name || null,
      email: account.value.email,
      // Скобки и дефисы — дело показа: на сервер уходит одно число.
      phone: phoneForApi(account.value.phone),
      job_title: account.value.job_title || null,
      ...(account.value.password ? { password: account.value.password } : {}),
    })

    await afterChange()
    isEditing.value = false
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      formErrors.value = caught.errors
    }
    else {
      errorMessage.value = messageFrom(caught, 'Не удалось сохранить сотрудника.')
    }
  }
  finally {
    isSaving.value = false
  }
}

/* ---------- Доступ ---------- */

const isEditingAccess = ref(false)
const draft = ref<{ level: AccessLevel, permissions: string[] }>({ level: 'user', permissions: [] })
const copyFrom = ref('')

/** An administrator carries everything, so there is nothing left to tick. */
const draftIsAdmin = computed(() => draft.value.level !== 'user')

/** Permissions grouped by area, in the order the server lists them. */
const permissionGroups = computed(() => {
  const groups = new Map<string, { label: string, permissions: PermissionOption[] }>()

  for (const permission of data.value?.permissions ?? []) {
    const bucket = groups.get(permission.group)
      ?? { label: permission.group_label, permissions: [] }

    bucket.permissions.push(permission)
    groups.set(permission.group, bucket)
  }

  return [...groups.entries()].map(([key, group]) => ({ key, ...group }))
})

/**
 * The standings this person may hand out: an administrator appoints
 * administrators, a superadmin appoints anyone, and below that nobody moves
 * anyone. The API re-checks all of it, so this only keeps the form honest.
 */
const appointable = computed<AccessLevel[]>(() => {
  if (isSuperAdmin.value) {
    return ['user', 'admin', 'super-admin']
  }

  return isAdmin.value ? ['user', 'admin'] : []
})

const levels = computed(() => {
  const all: { value: AccessLevel, label: string, hint: string }[] = [
    { value: 'user', label: 'Пользователь', hint: 'Может только то, что отмечено ниже' },
    { value: 'admin', label: 'Администратор', hint: 'Может всё, кроме назначения суперадминистраторов' },
    { value: 'super-admin', label: 'Суперадминистратор', hint: 'Может всё, включая назначение суперадминистраторов' },
  ]

  // The standing already held stays in the list even when it is not on offer:
  // otherwise a superadmin's record would show up as an ordinary user to an
  // administrator reading it.
  return all.filter(level =>
    appointable.value.includes(level.value) || level.value === draft.value.level)
})

/** Коллеги — как готовый набор прав, который можно перенести целиком. */
const copyOptions = computed(() => (data.value?.colleagues ?? [])
  .filter(person => person.id !== memberId && person.level === 'user' && !person.dismissed_at)
  .map(person => ({ value: String(person.id), label: person.name, hint: permissionsCount(person) })))

function openAccessEditor() {
  const person = member.value

  if (!person) {
    return
  }

  isEditing.value = false
  errorMessage.value = null
  copyFrom.value = ''
  draft.value = { level: person.level, permissions: [...person.own_permissions] }
  isEditingAccess.value = true
}

function applyCopiedPermissions() {
  const source = (data.value?.colleagues ?? []).find(person => String(person.id) === copyFrom.value)

  if (source) {
    // Copied, not linked: from here the two sets drift apart independently.
    draft.value.permissions = [...source.own_permissions]
  }
}

async function saveAccess() {
  const person = member.value

  if (!person) {
    return
  }

  isSaving.value = true
  errorMessage.value = null

  try {
    await updateAccess(person, { level: draft.value.level, permissions: draft.value.permissions })
    await afterChange()
    isEditingAccess.value = false
  }
  catch (caught) {
    errorMessage.value = messageFrom(caught, 'Не удалось сохранить доступ.')
  }
  finally {
    isSaving.value = false
  }
}

/* ---------- Увольнение ---------- */

/**
 * Кого этот человек вправе уволить: всех, кроме себя, а суперадминистратора —
 * только другой суперадминистратор. Та же лестница, что и у назначений.
 */
const mayDismiss = computed(() => {
  const person = member.value

  if (!person || person.id === currentUser.value?.id) {
    return false
  }

  return isSuperAdmin.value || person.level !== 'super-admin'
})

async function dismiss() {
  const person = member.value

  if (!person) {
    return
  }

  const confirmed = await confirm({
    title: `Уволить ${person.name}?`,
    message: 'Учётная запись останется, но войти на платформу человек больше не сможет. Вернуть в строй можно в любой момент.',
    confirmLabel: 'Уволить',
    danger: true,
  })

  if (confirmed) {
    await act(() => dismissUser(person), 'Не удалось уволить сотрудника.')
  }
}

function reinstate() {
  const person = member.value

  return person
    ? act(() => reinstateUser(person), 'Не удалось вернуть сотрудника в строй.')
    : undefined
}

/** Удалённого профиля больше нет — смотреть на его карточку уже нечего. */
async function remove() {
  const person = member.value

  if (!person) {
    return
  }

  const confirmed = await confirm({
    title: `Удалить ${person.name} навсегда?`,
    message: 'Учётная запись исчезнет вместе с прогрессом обучения и планом. Написанное останется, но уже без подписи. Отменить это будет нельзя.',
    confirmLabel: 'Удалить навсегда',
    danger: true,
  })

  if (!confirmed) {
    return
  }

  isBusy.value = true
  errorMessage.value = null

  try {
    await deleteUser(person)
    await router.push('/staff')
  }
  catch (caught) {
    errorMessage.value = messageFrom(caught, 'Не удалось удалить учётную запись.')
    isBusy.value = false
  }
}

async function act(action: () => Promise<unknown>, fallback: string) {
  isEditing.value = false
  isEditingAccess.value = false
  errorMessage.value = null
  isBusy.value = true

  try {
    await action()
    await afterChange()
  }
  catch (caught) {
    errorMessage.value = messageFrom(caught, fallback)
  }
  finally {
    isBusy.value = false
  }
}

/** Changing your own record changes what you may see next. */
async function afterChange() {
  await refresh()

  if (memberId === currentUser.value?.id) {
    await useAuth().fetchUser()
  }
}

function messageFrom(caught: unknown, fallback: string): string {
  return (caught as { data?: { message?: string } }).data?.message ?? fallback
}
</script>

<template>
  <section>
    <NuxtLink to="/staff" class="back">
      ← Сотрудники
    </NuxtLink>

    <p v-if="pending" class="muted">
      Загрузка…
    </p>

    <p v-else-if="error || !member" class="auth-alert" role="alert">
      Такого сотрудника нет — возможно, его запись удалили.
    </p>

    <template v-else>
      <header class="profile card">
        <UserAvatar :name="member.name" :src="member.avatar_url" :size="72" />

        <div class="profile__about">
          <h1 class="profile__name">
            {{ member.name }}
          </h1>
          <p v-if="member.job_title" class="profile__title">
            {{ member.job_title }}
          </p>

          <div class="profile__marks">
            <span v-if="member.dismissed_at" class="badge badge--warning">
              Уволен {{ formatDate(member.dismissed_at) }}
            </span>
            <span v-else class="badge" :class="{ 'badge--highlight': member.level !== 'user' }">
              {{ member.level_label }}
            </span>
          </div>
        </div>

        <div v-if="canManage || canSeePlans" class="profile__actions">
          <button
            v-if="canSeePlans"
            type="button"
            class="button-secondary"
            :aria-expanded="isPlanOpen"
            aria-controls="learning-plan"
            @click="isPlanOpen = !isPlanOpen"
          >
            План обучения
          </button>

          <!-- У уволенного одна дорога — обратно в строй: пока он не вернулся,
               править ему карточку и права незачем. -->
          <template v-if="canManage && member.dismissed_at">
            <button type="button" class="button-secondary" :disabled="isBusy" @click="reinstate">
              Вернуть в строй
            </button>
            <button v-if="isSuperAdmin" type="button" class="button-danger" :disabled="isBusy" @click="remove">
              Удалить
            </button>
          </template>

          <template v-else-if="canManage">
            <button type="button" class="button-secondary" :disabled="isBusy" @click="openAccountForm">
              Изменить
            </button>
            <button type="button" class="button-secondary" :disabled="isBusy" @click="openAccessEditor">
              Доступ
            </button>
            <!-- Себя не увольняют, а суперадминистратора увольняет один
                 суперадминистратор. API проверяет то же самое — здесь кнопка
                 просто не предлагает того, чего нельзя. -->
            <button v-if="mayDismiss" type="button" class="button-secondary" :disabled="isBusy" @click="dismiss">
              Уволить
            </button>
          </template>
        </div>
      </header>

      <p v-if="errorMessage" class="auth-alert" role="alert">
        {{ errorMessage }}
      </p>

      <div class="cards">
        <section class="card panel">
          <h2 class="panel__title">
            Сведения
          </h2>

          <dl class="facts">
            <div class="facts__row">
              <dt>Почта</dt>
              <dd>
                <a :href="`mailto:${member.email}`">{{ member.email }}</a>
              </dd>
            </div>

            <div class="facts__row">
              <dt>Телефон</dt>
              <dd>
                <a v-if="member.phone" :href="`tel:${member.phone}`">{{ maskPhone(member.phone) }}</a>
                <span v-else class="muted">не указан</span>
              </dd>
            </div>

            <div class="facts__row">
              <dt>Должность</dt>
              <dd>
                <span v-if="member.job_title">{{ member.job_title }}</span>
                <span v-else class="muted">не указана</span>
              </dd>
            </div>

            <div class="facts__row">
              <dt>В системе с</dt>
              <dd>{{ member.created_at ? formatDate(member.created_at) : '—' }}</dd>
            </div>

            <!-- Написать коллеге — то же действие, что и позвонить, и стоит
                 рядом с телефоном, а не среди распоряжений о человеке. -->
            <div v-if="!member.dismissed_at && member.id !== currentUser?.id" class="facts__row">
              <dt>Сообщение</dt>
              <dd>
                <NuxtLink :to="`/messenger?write=${member.id}`">
                  Написать в мессенджере
                </NuxtLink>
              </dd>
            </div>
          </dl>
        </section>

        <section class="card panel">
          <h2 class="panel__title">
            Что доступно
          </h2>

          <p v-if="member.level !== 'user'" class="muted">
            {{ member.level_label }} — доступно всё, отмечать права по отдельности не нужно.
          </p>

          <template v-else-if="member.own_permissions.length === 0">
            <p class="muted">
              Прав не отмечено: платформа открывается, но внутри ничего не доступно.
            </p>
          </template>

          <ul v-else-if="ownPermissionLabels.length" class="rights">
            <li v-for="label in ownPermissionLabels" :key="label" class="badge">
              {{ label }}
            </li>
          </ul>

          <p v-else class="muted">
            {{ permissionsCount(member) }}
          </p>
        </section>
      </div>

      <section v-if="isPlanOpen" id="learning-plan" class="card panel">
        <header class="panel__head">
          <h2 class="panel__title">
            План обучения
          </h2>
          <NuxtLink to="/lms/plans" class="button-ghost button-sm">
            Все планы
          </NuxtLink>
        </header>

        <p v-if="!mayEditPlan" class="muted">
          {{ isDismissed
            ? 'Уволенному план не меняют — сначала верните его в строй.'
            : 'Только просмотр: менять план может администратор или суперадминистратор.' }}
        </p>

        <LearningPlanEditor :learner-id="member.id" :editable="mayEditPlan" />
      </section>

      <form v-if="isEditing" class="card panel form" novalidate @submit.prevent="saveAccount">
        <h2 class="panel__title">
          Учётная запись
        </h2>

        <StaffAccountFields v-model="account" mode="edit" :errors="formErrors" />

        <p class="muted">
          Права меняются отдельно — кнопкой «Доступ».
        </p>

        <div class="panel__actions">
          <button type="submit" class="button-primary" :disabled="isSaving">
            {{ isSaving ? 'Сохраняем…' : 'Сохранить' }}
          </button>
          <button type="button" class="button-secondary" :disabled="isSaving" @click="isEditing = false">
            Отмена
          </button>
        </div>
      </form>

      <section v-if="isEditingAccess" class="card panel">
        <h2 class="panel__title">
          Доступ
        </h2>

        <div class="access__section">
          <h3 class="access__section-title">
            Кто это в системе
          </h3>

          <div class="access__levels">
            <label
              v-for="option in levels"
              :key="option.value"
              class="access__level"
              :class="{ 'access__level--on': draft.level === option.value }"
            >
              <input v-model="draft.level" type="radio" :value="option.value">
              <span>
                <span class="access__level-name">{{ option.label }}</span>
                <span class="muted">{{ option.hint }}</span>
              </span>
            </label>
          </div>
        </div>

        <p v-if="draftIsAdmin" class="muted">
          Администратору доступно всё — отмечать права по отдельности не нужно.
        </p>

        <div v-else class="access__section">
          <h3 class="access__section-title">
            Что доступно
          </h3>

          <div v-if="copyOptions.length" class="access__copy">
            <label class="field-label" for="copy_from">Скопировать права у</label>
            <UiSelect
              id="copy_from"
              v-model="copyFrom"
              :options="copyOptions"
              placeholder="Выберите сотрудника"
              auto
            />
            <button
              type="button"
              class="button-secondary button-sm"
              :disabled="!copyFrom"
              @click="applyCopiedPermissions"
            >
              Подставить
            </button>
          </div>

          <div class="access__groups">
            <fieldset v-for="group in permissionGroups" :key="group.key" class="access__group">
              <legend class="access__group-title">
                {{ group.label }}
              </legend>

              <label
                v-for="permission in group.permissions"
                :key="permission.name"
                class="access__permission"
              >
                <input v-model="draft.permissions" type="checkbox" :value="permission.name">
                <span>{{ permission.label }}</span>
              </label>
            </fieldset>
          </div>
        </div>

        <div class="panel__actions">
          <button type="button" class="button-primary" :disabled="isSaving" @click="saveAccess">
            {{ isSaving ? 'Сохраняем…' : 'Сохранить доступ' }}
          </button>
          <button type="button" class="button-secondary" :disabled="isSaving" @click="isEditingAccess = false">
            Отмена
          </button>
        </div>
      </section>
    </template>
  </section>
</template>

<style scoped>
.back {
  display: inline-block;
  margin-bottom: 0.9rem;
  color: var(--color-text-muted);
  font-size: 0.85rem;
  text-decoration: none;
}

.back:hover {
  color: var(--color-text);
}

.muted {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

/* Аватар, имя и распоряжения о человеке — одной строкой; на узком экране
   складываются в столбец, чтобы кнопки не выдавливали имя. */
.profile {
  display: flex;
  align-items: center;
  gap: 1.1rem;
  padding: 1.25rem 1.4rem;
  margin-bottom: 1rem;
}

.profile__about {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  min-width: 0;
}

.profile__name {
  margin: 0;
  font-size: 1.4rem;
  line-height: 1.2;
}

.profile__title {
  margin: 0;
  color: var(--color-text-muted);
}

.profile__marks {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.profile__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-left: auto;
}

/* Сведения и права — две равные карточки, пока для них есть ширина. */
.cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(18rem, 1fr));
  gap: 1rem;
  align-items: start;
  margin-bottom: 1rem;
}

.panel {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1.25rem 1.4rem;
}

.panel__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}

.panel__title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 600;
}

.panel__actions {
  display: flex;
  gap: 0.5rem;
}

.facts {
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
  margin: 0;
}

/*
 * Подпись и значение в две колонки: подпись узкая и одинаковой ширины у всех
 * строк, значение занимает остальное. Так глаз читает список сверху вниз по
 * одному краю, а не ищет каждое значение заново.
 */
.facts__row {
  display: grid;
  grid-template-columns: 8rem minmax(0, 1fr);
  gap: 0.6rem;
  align-items: baseline;
}

.facts dt {
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.facts dd {
  margin: 0;
  min-width: 0;
  overflow-wrap: anywhere;
}

.facts a {
  color: inherit;
  text-decoration: none;
}

.facts a:hover {
  text-decoration: underline;
}

.rights {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.access__section {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.access__section-title {
  margin: 0;
  font-size: 0.8rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-text-muted);
}

/* Три standing side by side on a wide screen, stacked when they stop
   fitting — never a ragged row with one card stranded on its own line. */
.access__levels {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(17rem, 1fr));
  gap: 0.6rem;
}

.access__level {
  display: flex;
  align-items: flex-start;
  gap: 0.6rem;
  padding: 0.75rem 0.9rem;
  border: 1px solid transparent;
  border-radius: var(--radius);
  background: var(--color-surface-raised);
  cursor: pointer;
  transition: border-color 0.15s ease;
}

.access__level:hover {
  border-color: var(--color-border-strong);
}

/* The chosen standing, marked the same way a ticked box is. */
.access__level--on {
  border-color: var(--color-highlight-strong);
  background: color-mix(in srgb, var(--color-highlight) 12%, var(--color-surface-raised));
}

.access__level--on:hover {
  border-color: var(--color-highlight-strong);
}

.access__level > span {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
}

.access__level-name {
  font-size: 0.94rem;
  font-weight: 550;
}

.access__copy {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

/*
 * Columns of a fixed measure rather than auto-fit stretching: every group is a
 * short list, and letting them size themselves left one lonely column trailing
 * under the others.
 */
.access__groups {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(15rem, 1fr));
  gap: 1rem;
  align-items: start;
}

.access__group {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  margin: 0;
  padding: 0.85rem 1rem;
  border: 0;
  border-radius: var(--radius);
  background: var(--color-surface-raised);
}

.access__group-title {
  padding: 0;
  margin-bottom: 0.45rem;
  font-size: 0.8rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-text-muted);
}

/*
 * Aligned to the first line, not to the middle of the block: a two-line label
 * with a centred box beside it is what made the list look scattered.
 */
.access__permission {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  align-items: start;
  gap: 0.55rem;
  padding: 0.3rem 0;
  font-size: 0.9rem;
  line-height: 1.35;
  cursor: pointer;
}

.access__permission input {
  /* Nudged down so the box sits on the text baseline rather than above it. */
  margin-top: 0.15rem;
}

@media (max-width: 48rem) {
  .profile {
    flex-direction: column;
    align-items: flex-start;
  }

  .profile__actions {
    margin-left: 0;
  }

  .facts__row {
    grid-template-columns: minmax(0, 1fr);
    gap: 0.15rem;
  }
}
</style>
