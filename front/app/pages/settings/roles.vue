<script setup lang="ts">
import type { PermissionOption, Role } from '~/types/auth'

definePageMeta({ middleware: 'auth', permission: 'roles.manage' })
useHead({ title: 'Роли и права' })

const { fetchRoles, fetchPermissions, updateRolePermissions } = useAdminApi()

const { data, pending, error, refresh } = await useAsyncData('settings.roles', async () => {
  const [roles, permissions] = await Promise.all([fetchRoles(), fetchPermissions()])

  return { roles: roles.data, permissions: permissions.data }
})

/** Permissions grouped by their prefix, e.g. all `contacts.*` together. */
const permissionGroups = computed<[string, PermissionOption[]][]>(() => {
  const groups = new Map<string, PermissionOption[]>()

  for (const permission of data.value?.permissions ?? []) {
    const bucket = groups.get(permission.group) ?? []
    bucket.push(permission)
    groups.set(permission.group, bucket)
  }

  return [...groups.entries()]
})

const selectedRoleName = ref<string | null>(null)
const draft = ref<string[]>([])
const savingState = ref<'idle' | 'saving' | 'saved' | 'failed'>('idle')

const roles = computed(() => data.value?.roles ?? [])

const selectedRole = computed<Role | null>(
  () => roles.value.find(role => role.name === selectedRoleName.value) ?? null,
)

const isDirty = computed(() => {
  const original = [...(selectedRole.value?.permissions ?? [])].sort()
  const current = [...draft.value].sort()

  return JSON.stringify(original) !== JSON.stringify(current)
})

function selectRole(role: Role) {
  selectedRoleName.value = role.name
  draft.value = [...role.permissions]
  savingState.value = 'idle'
}

// Select the first role once the list arrives, and keep the draft in step when
// the underlying data is refreshed.
watch(roles, (list) => {
  const stillPresent = list.some(role => role.name === selectedRoleName.value)

  if (list.length > 0 && (!selectedRoleName.value || !stillPresent)) {
    selectRole(list[0]!)
  }
}, { immediate: true })

async function save() {
  if (!selectedRole.value) {
    return
  }

  savingState.value = 'saving'

  try {
    await updateRolePermissions(selectedRole.value, draft.value)
    await refresh()
    savingState.value = 'saved'
  }
  catch {
    savingState.value = 'failed'
  }
}
</script>

<template>
  <section>
    <header class="page-header">
      <h1>Роли и права</h1>
      <p class="muted">
        Права проверяются на сервере при каждом запросе — этот экран лишь
        настраивает, что роль может делать.
      </p>
    </header>

    <p v-if="pending" class="muted">
      Загрузка…
    </p>

    <p v-else-if="error" class="auth-alert" role="alert">
      Не удалось загрузить роли.
    </p>

    <div v-else class="layout">
      <nav class="roles" aria-label="Роли">
        <button
          v-for="role in roles"
          :key="role.name"
          type="button"
          class="roles__item"
          :class="{ 'roles__item--active': role.name === selectedRoleName }"
          :aria-current="role.name === selectedRoleName"
          @click="selectRole(role)"
        >
          <span class="roles__label">{{ role.label }}</span>
          <span class="roles__meta">
            {{ role.permissions.length }} прав · {{ role.users_count ?? 0 }} польз.
          </span>
        </button>
      </nav>

      <div v-if="selectedRole" class="editor">
        <div
          v-for="[group, permissions] in permissionGroups"
          :key="group"
          class="editor__group"
        >
          <h2 class="editor__group-title">
            {{ group }}
          </h2>

          <label
            v-for="permission in permissions"
            :key="permission.name"
            class="editor__permission"
          >
            <input v-model="draft" type="checkbox" :value="permission.name">
            <span>{{ permission.label }}</span>
            <code>{{ permission.name }}</code>
          </label>
        </div>

        <footer class="editor__actions">
          <button
            type="button"
            class="button-primary"
            :disabled="!isDirty || savingState === 'saving'"
            @click="save"
          >
            {{ savingState === 'saving' ? 'Сохраняем…' : 'Сохранить' }}
          </button>

          <span v-if="savingState === 'saved' && !isDirty" class="muted">Сохранено</span>
          <span v-else-if="savingState === 'failed'" class="error">Не удалось сохранить</span>
        </footer>
      </div>
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
  font-size: 0.9rem;
}

.error {
  color: var(--color-danger);
  font-size: 0.9rem;
}

.layout {
  display: grid;
  grid-template-columns: minmax(12rem, 16rem) 1fr;
  gap: 1.5rem;
  align-items: start;
}

@media (max-width: 48rem) {
  .layout {
    grid-template-columns: minmax(0, 1fr);
  }

  /* Roles become a scrolling strip rather than a column that pushes the editor
     off the bottom of the screen. */
  .roles {
    flex-direction: row;
    overflow-x: auto;
    gap: 0.4rem;
    scrollbar-width: none;
  }

  .roles::-webkit-scrollbar {
    display: none;
  }

  .roles__item {
    flex-shrink: 0;
    border: 1px solid var(--color-border);
  }

  .editor {
    padding: 1rem;
  }

  .editor__permission {
    grid-template-columns: auto 1fr;
    row-gap: 0.1rem;
  }

  .editor__permission code {
    grid-column: 2;
  }
}

.roles {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.roles__item {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  padding: 0.6rem 0.75rem;
  border: 1px solid transparent;
  border-radius: var(--radius);
  background: transparent;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.roles__item:hover {
  background: var(--color-surface);
}

.roles__item--active {
  background: var(--color-surface);
  border-color: var(--color-border);
}

.roles__label {
  font-weight: 500;
}

.roles__meta {
  color: var(--color-text-muted);
  font-size: 0.8rem;
}

.editor {
  padding: 1.25rem;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
}

.editor__group + .editor__group {
  margin-top: 1.25rem;
}

.editor__group-title {
  margin: 0 0 0.5rem;
  font-size: 0.8rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-text-muted);
}

.editor__permission {
  display: grid;
  grid-template-columns: auto 1fr auto;
  align-items: center;
  gap: 0.6rem;
  padding: 0.3rem 0;
  font-size: 0.9rem;
}

.editor__permission code {
  color: var(--color-text-muted);
  font-size: 0.78rem;
}

.editor__actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-top: 1.5rem;
  padding-top: 1rem;
  border-top: 1px solid var(--color-border);
}
</style>