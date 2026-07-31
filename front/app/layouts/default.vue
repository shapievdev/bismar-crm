<script setup lang="ts">
const { user, isAuthenticated, can, logout } = useAuth()
const router = useRouter()

const isLoggingOut = ref(false)

/** Links the current user is allowed to reach; the API enforces the same rules. */
const navigation = computed(() => [
  { to: '/', label: 'Панель', visible: true },
  { to: '/lms', label: 'Обучение', visible: can('courses.view') },
  { to: '/settings/users', label: 'Пользователи', visible: can('users.view') },
  { to: '/settings/roles', label: 'Роли', visible: can('roles.manage') },
].filter(link => link.visible))

async function handleLogout() {
  isLoggingOut.value = true

  try {
    await logout()
    await router.push('/login')
  }
  finally {
    isLoggingOut.value = false
  }
}
</script>

<template>
  <div class="shell">
    <header class="shell__header">
      <NuxtLink to="/" class="shell__brand">
        Bismar CRM
      </NuxtLink>

      <nav v-if="isAuthenticated" class="shell__nav">
        <NuxtLink v-for="link in navigation" :key="link.to" :to="link.to">
          {{ link.label }}
        </NuxtLink>
      </nav>

      <div v-if="isAuthenticated" class="shell__account">
        <span class="shell__user">{{ user?.name }}</span>
        <button type="button" :disabled="isLoggingOut" @click="handleLogout">
          {{ isLoggingOut ? 'Выход…' : 'Выйти' }}
        </button>
      </div>
    </header>

    <main class="shell__main">
      <slot />
    </main>
  </div>
</template>

<style scoped>
.shell {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

.shell__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.875rem 1.5rem;
  background: var(--color-surface);
  border-bottom: 1px solid var(--color-border);
}

.shell__brand {
  font-weight: 600;
  color: inherit;
  text-decoration: none;
}

.shell__nav {
  display: flex;
  gap: 1rem;
  margin-right: auto;
  font-size: 0.9rem;
}

.shell__nav a {
  color: var(--color-text-muted);
  text-decoration: none;
}

.shell__nav a.router-link-exact-active {
  color: var(--color-text);
  font-weight: 500;
}

.shell__account {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.shell__user {
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.shell__account button {
  padding: 0.4rem 0.85rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface);
  color: var(--color-text);
  font: inherit;
  font-size: 0.9rem;
  cursor: pointer;
}

.shell__account button:disabled {
  opacity: 0.6;
  cursor: default;
}

.shell__main {
  flex: 1;
  width: 100%;
  max-width: 60rem;
  margin: 0 auto;
  padding: 2rem 1.5rem;
}
</style>