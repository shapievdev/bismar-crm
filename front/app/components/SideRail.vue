<script setup lang="ts">
const { can, logout } = useAuth()
const router = useRouter()
const route = useRoute()

const isLoggingOut = ref(false)

interface RailItem {
  to: string
  label: string
  icon: string
  visible: boolean
  /** Which paths count as being inside this module. */
  matches: (path: string) => boolean
}

/**
 * Navigation for the platform as a whole: one entry per CRM module.
 *
 * Moving between modules is a rarer, heavier move than moving inside one, so
 * it lives here as icons and the top bar is left to the current module's own
 * pages.
 */
const items = computed<RailItem[]>(() => [
  {
    to: '/',
    label: 'Панель',
    icon: 'dashboard',
    visible: true,
    matches: (path: string) => path === '/',
  },
  {
    to: '/lms',
    label: 'База знаний',
    icon: 'library',
    visible: can('courses.view'),
    matches: (path: string) => path.startsWith('/lms'),
  },
  {
    // Everyone has a profile, so this module is never hidden; which pages it
    // offers is decided by ModuleNav from the reader's permissions.
    to: '/settings/profile',
    label: 'Настройки',
    icon: 'settings',
    visible: true,
    matches: (path: string) => path.startsWith('/settings'),
  },
].filter(item => item.visible))

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
  <aside class="rail" aria-label="Разделы платформы">
    <NuxtLink
      v-for="item in items"
      :key="item.to"
      :to="item.to"
      class="rail__button"
      :class="{ 'rail__button--active': item.matches(route.path) }"
      :title="item.label"
      :aria-label="item.label"
      :aria-current="item.matches(route.path) ? 'page' : undefined"
    >
      <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <template v-if="item.icon === 'dashboard'">
          <rect x="3.5" y="3.5" width="7" height="7" rx="1.6" />
          <rect x="13.5" y="3.5" width="7" height="7" rx="1.6" />
          <rect x="3.5" y="13.5" width="7" height="7" rx="1.6" />
          <rect x="13.5" y="13.5" width="7" height="7" rx="1.6" />
        </template>

        <template v-else-if="item.icon === 'library'">
          <path d="M4 5.5A1.5 1.5 0 0 1 5.5 4H8v16H5.5A1.5 1.5 0 0 1 4 18.5z" />
          <path d="M10 4h2.5A1.5 1.5 0 0 1 14 5.5v13a1.5 1.5 0 0 1-1.5 1.5H10z" />
          <path d="m16.5 5.6 2 .5a1.5 1.5 0 0 1 1.1 1.8l-3 12.2" />
        </template>

        <template v-else-if="item.icon === 'settings'">
          <circle cx="12" cy="12" r="3" />
          <path d="M19.4 14.5a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5v.2a2 2 0 1 1-4 0v-.1a1.6 1.6 0 0 0-1-1.5 1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.6 1.6 0 0 0 1.5-1 1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1h.2a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z" />
        </template>
      </svg>
    </NuxtLink>

    <button
      type="button"
      class="rail__button rail__button--foot"
      :disabled="isLoggingOut"
      title="Выйти"
      aria-label="Выйти"
      @click="handleLogout"
    >
      <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <path d="M15 4h2.5A2.5 2.5 0 0 1 20 6.5v11a2.5 2.5 0 0 1-2.5 2.5H15" />
        <path d="M10 8.5 6.5 12 10 15.5M6.5 12H16" />
      </svg>
    </button>
  </aside>
</template>

<style scoped>
.rail {
  position: sticky;
  top: calc(var(--header-height) + 1rem);
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  padding-top: 0.25rem;
}

.rail__button {
  display: grid;
  place-items: center;
  width: 2.9rem;
  height: 2.9rem;
  flex-shrink: 0;
  border: 0;
  border-radius: var(--radius-pill);
  background: var(--color-surface-raised);
  color: var(--color-text-muted);
  cursor: pointer;
  text-decoration: none;
  transition: background-color 0.15s ease, color 0.15s ease;
}

.rail__button:hover:not(:disabled) {
  color: var(--color-text);
  background: var(--color-surface-sunken);
}

.rail__button--active {
  background: var(--color-accent);
  color: var(--color-accent-text);
}

.rail__button--active:hover {
  background: var(--color-accent-hover);
  color: var(--color-accent-text);
}

/* Sits apart from the modules above it: this one leaves the application. */
.rail__button--foot {
  margin-top: 1.4rem;
}

.rail__button:disabled {
  opacity: 0.45;
  cursor: default;
}

/*
 * On narrow screens the rail lies down under the header instead of eating a
 * column of an already narrow page.
 */
@media (max-width: 60rem) {
  .rail {
    position: static;
    flex-direction: row;
    gap: 0.5rem;
    overflow-x: auto;
    padding-bottom: 0.25rem;
    scrollbar-width: none;
  }

  .rail::-webkit-scrollbar {
    display: none;
  }

  .rail__button--foot {
    margin-top: 0;
    margin-left: auto;
  }
}
</style>
