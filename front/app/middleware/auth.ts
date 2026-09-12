/**
 * Guards pages that require an authenticated user, and optionally a permission
 * declared by the page itself:
 *
 *     definePageMeta({ middleware: 'auth', permission: 'users.manage' })
 *
 * Списком — «хотя бы одно из»: у базы знаний три раздела со своими правами
 * (курсы, документы, справочники), и есть страницы, общие для всех сразу, —
 * консультант отвечает по тому, что человеку открыто, корзина показывает
 * выброшенное из любого раздела. Требовать там право курсов значило бы закрыть
 * страницу от того, кому открыты одни справочники.
 *
 *     definePageMeta({ middleware: 'auth', permission: ['courses.view', 'handbooks.view'] })
 *
 * The API is the real authority — this only keeps users from rendering a page
 * they cannot populate, and remembers where they were headed.
 */
export default defineNuxtRouteMiddleware((to) => {
  const { isAuthenticated, can } = useAuth()

  if (!isAuthenticated.value) {
    return navigateTo({
      path: '/login',
      query: to.fullPath === '/' ? undefined : { redirect: to.fullPath },
    })
  }

  const permission = to.meta.permission
  const required = typeof permission === 'string' ? [permission] : permission ?? []

  if (required.length > 0 && !required.some(name => can(name))) {
    return abortNavigation({
      statusCode: 403,
      statusMessage: 'Недостаточно прав для доступа к этой странице.',
    })
  }
})