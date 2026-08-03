<script setup lang="ts">
const { user, isAuthenticated, can, logout } = useAuth()
const router = useRouter()

const isLoggingOut = ref(false)

/** Links the current user is allowed to reach; the API enforces the same rules. */
const navigation = computed(() => [
  { to: '/', label: 'Панель', visible: true },
  { to: '/lms', label: 'База знаний', visible: can('courses.view') },
  { to: '/settings/users', label: 'Пользователи', visible: can('users.view') },
  { to: '/settings/roles', label: 'Роли', visible: can('roles.manage') },
].filter(link => link.visible))

const initials = computed(() =>
  (user.value?.name ?? '')
    .split(/\s+/)
    .slice(0, 2)
    .map(word => word[0]?.toUpperCase() ?? '')
    .join(''),
)

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
    <header class="topbar">
      <div class="topbar__inner">
        <NuxtLink to="/" class="brand" aria-label="Bismar">
          <span class="brand__mark" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor">
              <path d="M12 1.5 15.4 8.6 22.5 12 15.4 15.4 12 22.5 8.6 15.4 1.5 12 8.6 8.6z" />
            </svg>
          </span>
          <span class="brand__name">Bismar</span>
        </NuxtLink>

        <nav v-if="isAuthenticated" class="nav">
          <NuxtLink v-for="link in navigation" :key="link.to" :to="link.to" class="nav__pill">
            {{ link.label }}
          </NuxtLink>
        </nav>

        <div v-if="isAuthenticated" class="account">
          <span class="account__name">{{ user?.name }}</span>
          <span class="avatar" :title="user?.email" aria-hidden="true">{{ initials }}</span>
          <button
            type="button"
            class="button-secondary button-sm"
            :disabled="isLoggingOut"
            @click="handleLogout"
          >
            {{ isLoggingOut ? 'Выход…' : 'Выйти' }}
          </button>
        </div>
      </div>
    </header>

    <main class="main">
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

.topbar {
  position: sticky;
  top: 0;
  z-index: 10;
  height: var(--header-height);
  background: color-mix(in srgb, var(--color-bg) 85%, transparent);
  backdrop-filter: blur(12px);
}

.topbar__inner {
  display: flex;
  align-items: center;
  gap: 1.25rem;
  height: 100%;
  max-width: 82rem;
  margin: 0 auto;
  padding: 0 1.75rem;
}

.brand {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  color: inherit;
  font-weight: 500;
  font-size: 1.05rem;
  letter-spacing: -0.01em;
  text-decoration: none;
}

.brand__mark {
  display: grid;
  place-items: center;
  width: 2rem;
  height: 2rem;
  border-radius: var(--radius-sm);
  background: var(--color-accent);
  color: var(--color-accent-text);
}

/* Each destination is its own pill; the current one inverts to solid. */
.nav {
  display: flex;
  gap: 0.4rem;
  margin-right: auto;
}

.nav__pill {
  padding: 0.5rem 1.05rem;
  border-radius: var(--radius-pill);
  background: var(--color-surface-raised);
  color: var(--color-text-muted);
  font-size: 0.92rem;
  text-decoration: none;
  transition: background-color 0.15s ease, color 0.15s ease;
}

.nav__pill:hover {
  color: var(--color-text);
}

.nav__pill.router-link-active {
  background: var(--color-accent);
  color: var(--color-accent-text);
}

.account {
  display: flex;
  align-items: center;
  gap: 0.6rem;
}

.avatar {
  display: grid;
  place-items: center;
  width: 2.25rem;
  height: 2.25rem;
  border-radius: var(--radius-pill);
  background: var(--color-surface-raised);
  color: var(--color-text-muted);
  font-size: 0.75rem;
  font-weight: 500;
}

.account__name {
  color: var(--color-text-muted);
  font-size: 0.88rem;
}

@media (max-width: 56rem) {
  .account__name,
  .brand__name {
    display: none;
  }

  .topbar__inner {
    gap: 0.6rem;
    padding: 0 1rem;
  }

  .nav {
    overflow-x: auto;
    scrollbar-width: none;
  }

  .nav::-webkit-scrollbar {
    display: none;
  }
}

.main {
  flex: 1;
  width: 100%;
  max-width: 82rem;
  margin: 0 auto;
  padding: 1.5rem 1.75rem 5rem;
}
</style>