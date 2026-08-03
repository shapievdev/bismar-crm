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
        <NuxtLink to="/" class="brand">
          <span class="brand__mark" aria-hidden="true">B</span>
          <span class="brand__name">Bismar</span>
        </NuxtLink>

        <nav v-if="isAuthenticated" class="nav">
          <NuxtLink v-for="link in navigation" :key="link.to" :to="link.to" class="nav__link">
            {{ link.label }}
          </NuxtLink>
        </nav>

        <div v-if="isAuthenticated" class="account">
          <span class="avatar" :title="user?.email" aria-hidden="true">{{ initials }}</span>
          <span class="account__name">{{ user?.name }}</span>
          <button
            type="button"
            class="button-ghost button-sm"
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
  background: color-mix(in srgb, var(--color-surface) 88%, transparent);
  backdrop-filter: blur(8px);
  border-bottom: 1px solid var(--color-border);
}

.topbar__inner {
  display: flex;
  align-items: center;
  gap: 1.5rem;
  height: 100%;
  max-width: 76rem;
  margin: 0 auto;
  padding: 0 1.5rem;
}

.brand {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  color: inherit;
  font-weight: 650;
  text-decoration: none;
}

.brand__mark {
  display: grid;
  place-items: center;
  width: 1.6rem;
  height: 1.6rem;
  border-radius: var(--radius-sm);
  background: var(--color-accent);
  color: var(--color-accent-text);
  font-size: 0.9rem;
}

.nav {
  display: flex;
  gap: 0.25rem;
  margin-right: auto;
}

.nav__link {
  padding: 0.35rem 0.7rem;
  border-radius: var(--radius-sm);
  color: var(--color-text-muted);
  font-size: 0.9rem;
  text-decoration: none;
}

.nav__link:hover {
  background: var(--color-surface-sunken);
  color: var(--color-text);
}

.nav__link.router-link-active {
  background: var(--color-accent-soft);
  color: var(--color-accent);
  font-weight: 500;
}

.account {
  display: flex;
  align-items: center;
  gap: 0.6rem;
}

.avatar {
  display: grid;
  place-items: center;
  width: 1.75rem;
  height: 1.75rem;
  border-radius: var(--radius-pill);
  background: var(--color-surface-sunken);
  color: var(--color-text-muted);
  font-size: 0.72rem;
  font-weight: 600;
}

.account__name {
  color: var(--color-text-muted);
  font-size: 0.88rem;
}

@media (max-width: 44rem) {
  .account__name,
  .brand__name {
    display: none;
  }

  .topbar__inner {
    gap: 0.75rem;
    padding: 0 1rem;
  }
}

.main {
  flex: 1;
  width: 100%;
  max-width: 76rem;
  margin: 0 auto;
  padding: 2rem 1.5rem 4rem;
}
</style>