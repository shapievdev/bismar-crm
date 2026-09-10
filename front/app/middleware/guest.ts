/**
 * Keeps already-authenticated users away from the login page.
 */
export default defineNuxtRouteMiddleware(() => {
  const { isAuthenticated } = useAuth()

  if (isAuthenticated.value) {
    return navigateTo('/')
  }
})