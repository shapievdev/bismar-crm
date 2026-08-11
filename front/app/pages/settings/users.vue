<script setup lang="ts">
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'
import type { AccessLevel, PermissionOption, User } from '~/types/auth'

definePageMeta({ middleware: 'auth', permission: 'users.view' })
useHead({ title: 'Пользователи' })

const { fetchUsers, fetchPermissions, createUser, updateUser, updateAccess } = useAdminApi()
const { can, isSuperAdmin, user: currentUser } = useAuth()

const canManage = computed(() => can('users.manage'))

const { data, pending, error, refresh } = await useAsyncData('settings.users', async () => {
  const [users, permissions] = await Promise.all([
    fetchUsers(),
    // Only someone who may edit access needs the catalogue to tick through.
    canManage.value ? fetchPermissions() : Promise.resolve({ data: [] }),
  ])

  return { users: users.data, permissions: permissions.data }
})

const users = computed(() => data.value?.users ?? [])

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

const errorMessage = ref<string | null>(null)
const isSaving = ref(false)

/* ---------- Учётная запись ---------- */

const form = ref<{ mode: 'create' | 'edit', user: User | null } | null>(null)
const formErrors = ref<ValidationErrors>({})

const blank = () => ({ last_name: '', first_name: '', middle_name: '', email: '', password: '' })
const account = ref(blank())

function openCreateForm() {
  editingAccessFor.value = null
  errorMessage.value = null
  formErrors.value = {}
  account.value = blank()
  form.value = { mode: 'create', user: null }
}

function openEditForm(user: User) {
  editingAccessFor.value = null
  errorMessage.value = null
  formErrors.value = {}
  account.value = {
    last_name: user.last_name ?? '',
    first_name: user.first_name,
    middle_name: user.middle_name ?? '',
    email: user.email,
    // Left blank means "leave the current one alone".
    password: '',
  }
  form.value = { mode: 'edit', user }
}

async function submitForm() {
  if (!form.value) {
    return
  }

  isSaving.value = true
  errorMessage.value = null
  formErrors.value = {}

  const payload = {
    last_name: account.value.last_name,
    first_name: account.value.first_name,
    middle_name: account.value.middle_name || null,
    email: account.value.email,
  }

  try {
    if (form.value.mode === 'create') {
      await createUser({ ...payload, password: account.value.password })
      await refresh()
    }
    else {
      const target = form.value.user!

      await updateUser(target, {
        ...payload,
        ...(account.value.password ? { password: account.value.password } : {}),
      })
      await afterChange(target)
    }

    form.value = null
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      formErrors.value = caught.errors
    }
    else {
      errorMessage.value = messageFrom(caught, 'Не удалось сохранить пользователя.')
    }
  }
  finally {
    isSaving.value = false
  }
}

/* ---------- Доступ ---------- */

const editingAccessFor = ref<number | null>(null)
const draft = ref<{ level: AccessLevel, permissions: string[] }>({ level: 'user', permissions: [] })
const copyFrom = ref('')

/** An administrator carries everything, so there is nothing left to tick. */
const draftIsAdmin = computed(() => draft.value.level !== 'user')

const levels = computed(() => {
  const all: { value: AccessLevel, label: string, hint: string }[] = [
    { value: 'user', label: 'Пользователь', hint: 'Может только то, что отмечено ниже' },
    { value: 'admin', label: 'Администратор', hint: 'Может всё, кроме назначения администраторов' },
    { value: 'super-admin', label: 'Суперадминистратор', hint: 'Может всё, включая назначение администраторов' },
  ]

  // Only a superadmin may appoint one; the API refuses the rest anyway.
  return isSuperAdmin.value ? all : all.filter(level => level.value === 'user')
})

/** Everyone else, as a source to copy a ready-made set of permissions from. */
const copyOptions = computed(() => users.value
  .filter(user => user.id !== editingAccessFor.value && user.level === 'user')
  .map(user => ({ value: String(user.id), label: user.name, hint: permissionsLabel(user) })))

function openAccessEditor(user: User) {
  form.value = null
  errorMessage.value = null
  copyFrom.value = ''
  editingAccessFor.value = user.id
  draft.value = { level: user.level, permissions: [...user.own_permissions] }
}

function applyCopiedPermissions() {
  const source = users.value.find(user => String(user.id) === copyFrom.value)

  if (source) {
    // Copied, not linked: from here the two sets drift apart independently.
    draft.value.permissions = [...source.own_permissions]
  }
}

async function saveAccess(user: User) {
  isSaving.value = true
  errorMessage.value = null

  try {
    await updateAccess(user, {
      level: draft.value.level,
      permissions: draft.value.permissions,
    })
    await afterChange(user)
    editingAccessFor.value = null
  }
  catch (caught) {
    errorMessage.value = messageFrom(caught, 'Не удалось сохранить доступ.')
  }
  finally {
    isSaving.value = false
  }
}

/** Changing your own record changes what you may see next. */
async function afterChange(user: User) {
  await refresh()

  if (user.id === currentUser.value?.id) {
    await useAuth().fetchUser()
  }
}

function messageFrom(caught: unknown, fallback: string): string {
  return (caught as { data?: { message?: string } }).data?.message ?? fallback
}

function permissionsLabel(user: User): string {
  const count = user.own_permissions.length

  return count === 0
    ? 'без прав'
    : `${count} ${pluralise(count, 'право', 'права', 'прав')}`
}
</script>

<template>
  <section>
    <header class="page-header">
      <div class="page-header__row">
        <div>
          <h1>Пользователи</h1>
          <p class="muted">
            {{ canManage
              ? 'Заводите сотрудников и отмечайте, что каждому доступно.'
              : 'Только просмотр: у вас нет права управлять пользователями.' }}
          </p>
        </div>

        <button v-if="canManage" type="button" class="button-primary" @click="openCreateForm">
          Новый пользователь
        </button>
      </div>
    </header>

    <p v-if="errorMessage" class="auth-alert" role="alert">
      {{ errorMessage }}
    </p>

    <form v-if="form" class="card panel" @submit.prevent="submitForm">
      <h2 class="panel__title">
        {{ form.mode === 'create' ? 'Новый пользователь' : 'Учётная запись' }}
      </h2>

      <div class="panel__grid">
        <FormField id="last_name" v-model="account.last_name" label="Фамилия" autocomplete="off" :errors="formErrors.last_name" />
        <FormField id="first_name" v-model="account.first_name" label="Имя" autocomplete="off" :errors="formErrors.first_name" />
        <FormField id="middle_name" v-model="account.middle_name" label="Отчество — если есть" autocomplete="off" :errors="formErrors.middle_name" />
        <FormField id="email" v-model="account.email" label="Email" type="email" autocomplete="off" :errors="formErrors.email" />
        <FormField
          id="password"
          v-model="account.password"
          :label="form.mode === 'create' ? 'Пароль' : 'Новый пароль — пусто, чтобы не менять'"
          type="password"
          autocomplete="new-password"
          :errors="formErrors.password"
        />
      </div>

      <p class="muted">
        {{ form.mode === 'create'
          ? 'Прав у нового сотрудника нет: отметьте их отдельно, кнопкой «Доступ».'
          : 'Права меняются отдельно — кнопкой «Доступ» в строке.' }}
      </p>

      <div class="panel__actions">
        <button type="submit" class="button-primary" :disabled="isSaving">
          {{ isSaving ? 'Сохраняем…' : 'Сохранить' }}
        </button>
        <button type="button" class="button-secondary" :disabled="isSaving" @click="form = null">
          Отмена
        </button>
      </div>
    </form>

    <p v-if="pending" class="muted">
      Загрузка…
    </p>

    <p v-else-if="error" class="auth-alert" role="alert">
      Не удалось загрузить пользователей.
    </p>

    <div v-else class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Пользователь</th>
            <th>Доступ</th>
            <th v-if="canManage" />
          </tr>
        </thead>

        <tbody>
          <template v-for="row in users" :key="row.id">
            <tr>
              <td>
                <div class="user__name">
                  {{ row.name }}
                </div>
                <div class="muted">
                  {{ row.email }}
                </div>
              </td>

              <td>
                <!-- Lime marks the rare standing, not the ordinary one: it is
                     what tells you at a glance who runs the place. -->
                <span class="badge" :class="{ 'badge--highlight': row.level !== 'user' }">
                  {{ row.level_label }}
                </span>
                <span v-if="row.level === 'user'" class="muted permissions-count">
                  {{ permissionsLabel(row) }}
                </span>
              </td>

              <td v-if="canManage" class="cell-actions">
                <div class="actions">
                  <button type="button" class="button-secondary button-sm" @click="openAccessEditor(row)">
                    Доступ
                  </button>
                  <button type="button" class="button-secondary button-sm" @click="openEditForm(row)">
                    Изменить
                  </button>
                </div>
              </td>
            </tr>

            <tr v-if="editingAccessFor === row.id" class="access-row">
              <td :colspan="canManage ? 3 : 2">
                <div class="access">
                  <div class="access__section">
                    <h3 class="access__section-title">Кто это в системе</h3>

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
                    <h3 class="access__section-title">Что доступно</h3>

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

                  <div class="access__actions">
                    <button type="button" class="button-primary" :disabled="isSaving" @click="saveAccess(row)">
                      {{ isSaving ? 'Сохраняем…' : 'Сохранить доступ' }}
                    </button>
                    <button type="button" class="button-secondary" :disabled="isSaving" @click="editingAccessFor = null">
                      Отмена
                    </button>
                  </div>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
  </section>
</template>

<style scoped>
.page-header {
  margin-bottom: 1.5rem;
}

.page-header__row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
}

.page-header h1 {
  margin: 0 0 0.25rem;
  font-size: 1.5rem;
}

.muted {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.panel {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1.25rem 1.4rem;
  margin-bottom: 1.25rem;
}

.panel__title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 600;
}

.panel__grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr));
  gap: 0.9rem;
}

.panel__actions {
  display: flex;
  gap: 0.5rem;
}

.table-wrap {
  overflow-x: auto;
  background: var(--color-surface);
  border-radius: var(--radius);
}

table {
  width: 100%;
  border-collapse: collapse;
}

th,
td {
  padding: 0.85rem 1rem;
  text-align: left;
  /* Middle, so a one-line cell lines up with the two-line name beside it. */
  vertical-align: middle;
  border-bottom: 1px solid var(--color-border);
}

/*
 * Shrink-to-fit: the actions column takes only the width of its buttons, and
 * the name column absorbs whatever is left. `width: 1%` is the table idiom for
 * "as narrow as the content allows".
 */
.cell-actions {
  width: 1%;
  white-space: nowrap;
}

th {
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-text-muted);
}

tbody tr:last-child td {
  border-bottom: none;
}

.user__name {
  font-weight: 500;
}

.permissions-count {
  margin-left: 0.5rem;
}

/*
 * Flex lives on a wrapper, never on the <td> itself: `display: flex` on a cell
 * takes it out of table layout, and the row's other cells then draw their
 * borders at a different height. That is what made the rules look misaligned.
 */
.actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
}

/*
 * The editor opens underneath its own row rather than in a dialog, so the
 * person being edited stays visible right above it. A sunken panel with real
 * padding keeps it from reading as more table.
 */
.access-row td {
  padding: 0;
  background: var(--color-surface-sunken);
}

.access {
  display: flex;
  flex-direction: column;
  gap: 1.4rem;
  padding: 1.4rem 1.5rem;
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

/* Three standings side by side on a wide screen, stacked when they stop
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

.access__actions {
  display: flex;
  gap: 0.5rem;
  padding-top: 0.2rem;
}

@media (max-width: 48rem) {
  .page-header__row {
    flex-direction: column;
  }

  th,
  td {
    padding: 0.6rem 0.7rem;
  }

  .access {
    padding: 1rem;
  }

  .actions {
    flex-direction: column;
    gap: 0.35rem;
  }
}
</style>