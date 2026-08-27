<script setup lang="ts">
const { can, isSuperAdmin } = useAuth()
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
      // Рядом с материалами: по одному учатся, по другому работают.
      {
        to: '/lms/regulations',
        label: 'Регламенты',
        visible: true,
        matches: (p: string) => p.startsWith('/lms/regulations') && p !== '/lms/regulations/categories',
      },
      // «Мой план» — назначенное, «Мои материалы» — всё, за что человек брался
      // сам. Первое идёт раньше: с него начинают.
      { to: '/lms/plan', label: 'Мой план', visible: true },
      { to: '/lms/my', label: 'Мои материалы', visible: true },
      { to: '/lms/assistant', label: 'Консультант', visible: true },
      { to: '/lms/categories', label: 'Категории', visible: can('courses.update') },
      {
        to: '/lms/regulations/categories',
        label: 'Категории регламентов',
        visible: can('courses.update'),
      },
      { to: '/lms/plans', label: 'Планы обучения', visible: can('enrollments.manage') },
    ].filter(link => link.visible)
  }

  // Главная и новости — один модуль: рельса ведёт на «Панель», а лента на ней
  // и есть первое, ради чего сюда заходят.
  if (path === '/' || path.startsWith('/news')) {
    return [
      { to: '/', label: 'Главная', visible: true, matches: (p: string) => p === '/' },
      { to: '/news', label: 'Новости', visible: true, matches: (p: string) => p.startsWith('/news') },
    ]
  }

  if (path.startsWith('/analytics')) {
    // Все вкладки под одним правом и на одной витрине: цифры приходят из
    // одного источника, и разделять «кто видит выручку» и «кто видит клиентов»
    // здесь нечем.
    return [
      { to: '/analytics', label: 'Продажи', visible: true, matches: (p: string) => p === '/analytics' },
      { to: '/analytics/customers', label: 'Клиенты', visible: true },
      { to: '/analytics/products', label: 'Товары', visible: true },
    ].filter(link => link.visible)
  }

  if (path.startsWith('/settings')) {
    return [
      { to: '/settings/profile', label: 'Профиль', visible: true },
      { to: '/settings/users', label: 'Пользователи', visible: can('users.view') },
      // Пробелы в базе закрывают авторы курсов — журнал открыт им.
      { to: '/settings/questions', label: 'Вопросы', visible: can('courses.update') },
      // Платёжный ключ и модель — только суперадминистратору.
      { to: '/settings/ai', label: 'Консультант', visible: isSuperAdmin.value },
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

/*
 * Held off the active pill on purpose. A bare `:hover` outranks `--active` on
 * specificity, so it would repaint the text of the accent-filled pill in the
 * page's text colour — near-black on near-black in light, near-white on lime in
 * dark. Each state gets its own hover instead.
 */
.module-nav__pill:hover:not(.module-nav__pill--active) {
  color: var(--color-text);
}

.module-nav__pill--active {
  background: var(--color-accent);
  color: var(--color-accent-text);
}

.module-nav__pill--active:hover {
  background: var(--color-accent-hover);
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