/**
 * Keeps already-authenticated users away from the login and register pages.
 */
export default defineNuxtRouteMiddleware(() => {
  const { isAuthenticated } = useAuth()

  if (isAuthenticated.value) {
    return navigateTo('/')
  }
})