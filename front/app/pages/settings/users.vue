<script setup lang="ts">
import type { User } from '~/types/auth'

definePageMeta({ middleware: 'auth', permission: 'users.view' })
useHead({ title: 'Пользователи' })

const { fetchRoles, fetchUsers, updateUserRoles } = useAdminApi()
const { can, user: currentUser } = useAuth()

const { data, pending, error, refresh } = await useAsyncData('settings.users', async () => {
  const [users, roles] = await Promise.all([fetchUsers(), fetchRoles()])

  return { users: users.data, roles: roles.data }
})

const canManage = computed(() => can('users.manage'))
const editingUserId = ref<number | null>(null)
const draft = ref<string[]>([])
const errorMessage = ref<string | null>(null)
const isSaving = ref(false)

function startEditing(user: User) {
  editingUserId.value = user.id
  draft.value = [...user.roles]
  errorMessage.value = null
}

function cancelEditing() {
  editingUserId.value = null
  errorMessage.value = null
}

async function save(user: User) {
  isSaving.value = true
  errorMessage.value = null

  try {
    await updateUserRoles(user, draft.value)
    await refresh()

    // Changing your own roles changes what you may see next.
    if (user.id === currentUser.value?.id) {
      await useAuth().fetchUser()
    }

    editingUserId.value = null
  }
  catch (caught) {
    const conflict = caught as { data?: { message?: string } }
    errorMessage.value = conflict.data?.message ?? 'Не удалось сохранить роли.'
  }
  finally {
    isSaving.value = false
  }
}
</script>

<template>
  <section>
    <header class="page-header">
      <h1>Пользователи</h1>
      <p class="muted">
        {{ canManage ? 'Назначайте роли — они определяют доступные разделы.' : 'Только просмотр: у вас нет права управлять пользователями.' }}
      </p>
    </header>

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
            <th>Роли</th>
            <th v-if="canManage" />
          </tr>
        </thead>

        <tbody>
          <tr v-for="row in data?.users ?? []" :key="row.id">
            <td>
              <div class="user__name">
                {{ row.name }}
              </div>
              <div class="muted">
                {{ row.email }}
              </div>
            </td>

            <td>
              <div v-if="editingUserId === row.id" class="role-picker">
                <label
                  v-for="role in data?.roles ?? []"
                  :key="role.name"
                  class="role-picker__option"
                >
                  <input v-model="draft" type="checkbox" :value="role.name">
                  {{ role.label }}
                </label>

                <p v-if="errorMessage" class="error" role="alert">
                  {{ errorMessage }}
                </p>
              </div>

              <span v-else-if="row.roles.length" class="badges">
                <span v-for="role in row.roles" :key="role" class="badge">{{ role }}</span>
              </span>

              <span v-else class="muted">без ролей</span>
            </td>

            <td v-if="canManage" class="actions">
              <template v-if="editingUserId === row.id">
                <button type="button" class="button-primary" :disabled="isSaving" @click="save(row)">
                  {{ isSaving ? 'Сохраняем…' : 'Сохранить' }}
                </button>
                <button type="button" class="button-plain" :disabled="isSaving" @click="cancelEditing">
                  Отмена
                </button>
              </template>

              <button v-else type="button" class="button-plain" @click="startEditing(row)">
                Изменить
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<style scoped>
.page-header {
  margin-bottom: 1.5rem;
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

.error {
  margin: 0.5rem 0 0;
  color: var(--color-danger);
  font-size: 0.85rem;
}

.table-wrap {
  overflow-x: auto;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
}

table {
  width: 100%;
  border-collapse: collapse;
}

th,
td {
  padding: 0.75rem 1rem;
  text-align: left;
  vertical-align: top;
  border-bottom: 1px solid var(--color-border);
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

.badges {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
}

.badge {
  padding: 0.1rem 0.5rem;
  border: 1px solid var(--color-border);
  border-radius: 999px;
  font-size: 0.78rem;
}

.role-picker {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}

.role-picker__option {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  font-size: 0.9rem;
}

.actions {
  display: flex;
  gap: 0.5rem;
  white-space: nowrap;
}

.button-plain {
  padding: 0.4rem 0.85rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface);
  color: var(--color-text);
  font: inherit;
  font-size: 0.9rem;
  cursor: pointer;
}

.button-plain:disabled {
  opacity: 0.6;
  cursor: default;
}
</style>