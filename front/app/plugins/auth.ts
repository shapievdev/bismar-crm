/**
 * Restores the authenticated user once, before the app renders, so that
 * `useAuth().user` is populated everywhere — including during SSR and in route
 * middleware — without each caller having to fetch it.
 */
export default defineNuxtPlugin({
  name: 'auth',
  dependsOn: ['api'],

  async setup() {
    const { fetchUser } = useAuth()

    // Nuxt transfers the SSR payload to the client, so this runs once per visit.
    await callOnce('auth.restore', fetchUser)
  },
})