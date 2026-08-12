/**
 * Lets a page declare the permission it requires, which the `auth` middleware
 * enforces: definePageMeta({ middleware: 'auth', permission: 'users.manage' })
 *
 * `fills` marks a page that measures itself against the screen instead of
 * growing with its content — the messenger, whose composer has to stay on the
 * bottom edge. The shell then drops its bottom padding, because anything left
 * below such a page makes the document taller than the screen, and on a phone
 * that shows up as a composer pushed out of reach behind the keyboard.
 */
declare module '#app' {
  interface PageMeta {
    permission?: string
    fills?: boolean
  }
}

declare module 'vue-router' {
  interface RouteMeta {
    permission?: string
    fills?: boolean
  }
}

export {}