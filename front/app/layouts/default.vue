<script setup lang="ts">
const { user, isAuthenticated, can } = useAuth()

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
</script>

<template>
  <div class="shell">
    <header class="topbar">
      <div class="topbar__inner">
        <NuxtLink to="/" class="brand" aria-label="Bismar">
          <BrandMark :size="24" />
        </NuxtLink>

        <nav v-if="isAuthenticated" class="nav">
          <NuxtLink v-for="link in navigation" :key="link.to" :to="link.to" class="nav__pill">
            {{ link.label }}
          </NuxtLink>
        </nav>

        <div v-if="isAuthenticated" class="account">
          <span class="account__name">{{ user?.name }}</span>
          <span class="avatar" :title="user?.email" aria-hidden="true">{{ initials }}</span>
        </div>
      </div>
    </header>

    <div class="body" :class="{ 'body--railed': isAuthenticated }">
      <SideRail v-if="isAuthenticated" />

      <main class="main">
        <slot />
      </main>
    </div>
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

/* The mark stands on its own, unboxed, and takes the page's text colour. */
.brand {
  display: flex;
  align-items: center;
  color: var(--color-text);
  text-decoration: none;
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
  .account__name {
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

/*
 * One column by default. The rail's column only exists when the rail does —
 * otherwise a guest's login card would be dropped into the narrow slot meant
 * for it.
 */
.body {
  flex: 1;
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 1.5rem;
  width: 100%;
  max-width: 82rem;
  margin: 0 auto;
  padding: 1.5rem 1.75rem 5rem;
  align-items: start;
}

.body--railed {
  grid-template-columns: 2.9rem minmax(0, 1fr);
}

.main {
  min-width: 0;
}

@media (max-width: 60rem) {
  .body,
  .body--railed {
    grid-template-columns: minmax(0, 1fr);
    gap: 1rem;
    padding: 1rem 1rem 4rem;
  }
}
</style>