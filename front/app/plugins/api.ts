const MUTATING_METHODS = new Set(['POST', 'PUT', 'PATCH', 'DELETE'])

/**
 * Provides `$api`, the single entry point for talking to the Laravel API.
 *
 * It takes care of the three things Sanctum's cookie-based SPA authentication
 * needs, so that callers never have to:
 *
 *  - sending the session cookie with every request (`credentials: 'include'`);
 *  - obtaining a CSRF cookie and echoing it back on mutating requests;
 *  - replaying the visitor's cookies and origin to Laravel during SSR, since
 *    the Nitro server has no browser to do it for us.
 */
export default defineNuxtPlugin({
  name: 'api',

  setup() {
    const { public: { apiBase } } = useRuntimeConfig()

    // On the server this runs once per incoming request, so the visitor's
    // cookies and origin can safely be captured here.
    const serverCookie = import.meta.server ? useRequestHeaders(['cookie']).cookie : undefined
    const serverOrigin = import.meta.server ? useRequestURL().origin : undefined

    // Set when Laravel rejects a token as stale so the next attempt refetches it.
    let csrfCookieIsStale = false

    async function ensureCsrfCookie(): Promise<void> {
      if (readCookie('XSRF-TOKEN') && !csrfCookieIsStale) {
        return
      }

      await $fetch('/sanctum/csrf-cookie', { baseURL: apiBase, credentials: 'include' })
      csrfCookieIsStale = false
    }

    const api = $fetch.create({
      baseURL: apiBase,
      credentials: 'include',
      // Laravel answers a stale CSRF token with 419; one retry after refreshing
      // the cookie is enough, and the request never reached application logic.
      retry: 1,
      retryStatusCodes: [419],

      async onRequest({ options }) {
        const headers = new Headers(options.headers)
        headers.set('Accept', 'application/json')

        if (import.meta.server) {
          if (serverCookie) {
            headers.set('cookie', serverCookie)
          }

          // Sanctum only starts a session for requests whose origin is listed in
          // `sanctum.stateful`; the server-side fetch has to set it by hand.
          if (serverOrigin) {
            headers.set('origin', serverOrigin)
          }
        }
        else {
          if (MUTATING_METHODS.has((options.method ?? 'GET').toUpperCase())) {
            await ensureCsrfCookie()
          }

          const xsrfToken = readCookie('XSRF-TOKEN')

          if (xsrfToken) {
            headers.set('X-XSRF-TOKEN', xsrfToken)
          }
        }

        options.headers = headers
      },

      onResponseError({ response }) {
        if (response.status === 419) {
          csrfCookieIsStale = true
        }
      },
    })

    return {
      provide: { api },
    }
  },
})