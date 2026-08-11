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

    /**
     * Sends a file and reports how far it has got.
     *
     * Separate from `$api` because $fetch cannot report request-body progress —
     * see `sendUpload`. Everything else Sanctum needs is shared with the code
     * above, which is why this lives in the plugin rather than in a composable.
     */
    async function upload<T>(path: string, body: FormData, options: UploadOptions = {}): Promise<T> {
      const url = apiBase.replace(/\/$/, '') + path

      const send = async (): Promise<T> => {
        await ensureCsrfCookie()

        return sendUpload<T>({ url, body, xsrfToken: readCookie('XSRF-TOKEN'), ...options })
      }

      try {
        return await send()
      }
      catch (error) {
        // The same single retry $api gets. A 419 means the token went stale
        // while the page sat open; the request never reached application logic,
        // so re-sending is safe — it just costs the transfer again.
        if (error instanceof UploadError && error.status === 419) {
          csrfCookieIsStale = true

          return await send()
        }

        throw error
      }
    }

    return {
      provide: { api, upload },
    }
  },
})