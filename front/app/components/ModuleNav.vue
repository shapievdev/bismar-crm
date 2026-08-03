<script setup lang="ts">
const { can } = useAuth()
const route = useRoute()

interface NavLink {
  to: string
  label: string
  visible: boolean
  /** Exact by default; a section with child routes says so explicitly. */
  matches?: (path: string) => boolean
}

/**
 * Pages within the module the reader is currently in.
 *
 * The rail decides which module; this decides where inside it. A module with a
 * single page renders nothing rather than a lone pill that cannot be left.
 */
const links = computed<NavLink[]>(() => {
  const path = route.path

  if (path.startsWith('/lms')) {
    return [
      { to: '/lms', label: 'Материалы', visible: true, matches: (p: string) => p === '/lms' },
      { to: '/lms/my', label: 'Мои материалы', visible: true },
      { to: '/lms/categories', label: 'Категории', visible: can('courses.update') },
    ].filter(link => link.visible)
  }

  if (path.startsWith('/settings')) {
    return [
      { to: '/settings/profile', label: 'Профиль', visible: true },
      { to: '/settings/users', label: 'Пользователи', visible: can('users.view') },
      { to: '/settings/roles', label: 'Роли', visible: can('roles.manage') },
    ].filter(link => link.visible)
  }

  return []
})

function isActive(link: NavLink): boolean {
  return link.matches ? link.matches(route.path) : route.path.startsWith(link.to)
}
</script>

<template>
  <nav v-if="links.length > 1" class="module-nav" aria-label="Разделы модуля">
    <NuxtLink
      v-for="link in links"
      :key="link.to"
      :to="link.to"
      class="module-nav__pill"
      :class="{ 'module-nav__pill--active': isActive(link) }"
      :aria-current="isActive(link) ? 'page' : undefined"
    >
      {{ link.label }}
    </NuxtLink>
  </nav>
</template>

<style scoped>
.module-nav {
  display: flex;
  gap: 0.4rem;
  margin-right: auto;
  min-width: 0;
}

.module-nav__pill {
  /* Never wrap: a two-line pill breaks the row's rhythm and, once one wraps,
     the ones after it fall off the edge. The row scrolls instead. */
  white-space: nowrap;
  flex-shrink: 0;
  padding: 0.5rem 1.05rem;
  border-radius: var(--radius-pill);
  background: var(--color-surface-raised);
  color: var(--color-text-muted);
  font-size: 0.92rem;
  text-decoration: none;
  transition: background-color 0.15s ease, color 0.15s ease;
}

.module-nav__pill:hover {
  color: var(--color-text);
}

.module-nav__pill--active {
  background: var(--color-accent);
  color: var(--color-accent-text);
}

@media (max-width: 56rem) {
  .module-nav {
    overflow-x: auto;
    scrollbar-width: none;
    /* Fades the right edge so a clipped pill reads as "there is more to
       scroll" rather than as a layout that ran out of room. */
    mask-image: linear-gradient(to right, #000 calc(100% - 1.5rem), transparent);
  }

  .module-nav::-webkit-scrollbar {
    display: none;
  }
}
</style>