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
  /** Matches nested routes too, so a lesson keeps its section lit. */
  match?: (path: string) => boolean
}

/**
 * Quick actions rather than a second copy of the top navigation: the sections
 * already live up there, and repeating them would make the same destination
 * appear twice with different shapes.
 */
const items = computed<RailItem[]>(() => [
  {
    to: '/lms',
    label: 'Вся база знаний',
    icon: 'library',
    visible: can('courses.view'),
    match: (path: string) => path === '/lms',
  },
  {
    to: '/lms/my',
    label: 'Мои материалы',
    icon: 'bookmark',
    visible: can('courses.view'),
  },
  {
    to: '/lms/new',
    label: 'Новый материал',
    icon: 'plus',
    visible: can('courses.create'),
  },
  {
    to: '/lms/categories',
    label: 'Категории',
    icon: 'folders',
    visible: can('courses.update'),
  },
].filter(item => item.visible))

function isActive(item: RailItem): boolean {
  return item.match ? item.match(route.path) : route.path.startsWith(item.to)
}

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
  <aside class="rail" aria-label="Быстрые действия">
    <NuxtLink
      v-for="item in items"
      :key="item.to"
      :to="item.to"
      class="rail__button"
      :class="{ 'rail__button--active': isActive(item) }"
      :title="item.label"
      :aria-label="item.label"
      :aria-current="isActive(item) ? 'page' : undefined"
    >
      <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <template v-if="item.icon === 'library'">
          <path d="M4 5.5A1.5 1.5 0 0 1 5.5 4H8v16H5.5A1.5 1.5 0 0 1 4 18.5z" />
          <path d="M10 4h2.5A1.5 1.5 0 0 1 14 5.5v13a1.5 1.5 0 0 1-1.5 1.5H10z" />
          <path d="m16.5 5.6 2 .5a1.5 1.5 0 0 1 1.1 1.8l-3 12.2" />
        </template>

        <template v-else-if="item.icon === 'bookmark'">
          <path d="M6 4.8A1.8 1.8 0 0 1 7.8 3h8.4A1.8 1.8 0 0 1 18 4.8V21l-6-3.6L6 21z" />
        </template>

        <template v-else-if="item.icon === 'plus'">
          <path d="M12 5v14M5 12h14" />
        </template>

        <template v-else-if="item.icon === 'folders'">
          <path d="M3 7.5A1.5 1.5 0 0 1 4.5 6h3.2l1.6 2H14a1.5 1.5 0 0 1 1.5 1.5v6A1.5 1.5 0 0 1 14 17H4.5A1.5 1.5 0 0 1 3 15.5z" />
          <path d="M18 9h1.5A1.5 1.5 0 0 1 21 10.5v7A1.5 1.5 0 0 1 19.5 19H8" />
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

/* Sits apart from the destinations above it: this one leaves the app. */
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
