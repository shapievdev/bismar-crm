/**
 * Guards pages that require an authenticated user, and optionally a permission
 * declared by the page itself:
 *
 *     definePageMeta({ middleware: 'auth', permission: 'roles.manage' })
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

  if (typeof permission === 'string' && !can(permission)) {
    return abortNavigation({
      statusCode: 403,
      statusMessage: 'Недостаточно прав для доступа к этой странице.',
    })
  }
})