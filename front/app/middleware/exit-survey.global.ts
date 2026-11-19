export default defineNuxtRouteMiddleware((to) => {
  const { user } = useAuth()
  if (user.value?.exit_survey_pending && to.path !== '/lms/exit-survey') {
    return navigateTo('/lms/exit-survey', { replace: true })
  }
})
