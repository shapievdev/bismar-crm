/**
 * Guards pages that require an authenticated user.
 *
 * The API is the real authority — this only keeps unauthenticated visitors from
 * rendering a page they cannot populate, and remembers where they were headed.
 */
export default defineNuxtRouteMiddleware((to) => {
  const { isAuthenticated } = useAuth()

  if (isAuthenticated.value) {
    return
  }

  return navigateTo({
    path: '/login',
    query: to.fullPath === '/' ? undefined : { redirect: to.fullPath },
  })
})