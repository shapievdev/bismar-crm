/**
 * Lets a page declare the permission it requires, which the `auth` middleware
 * enforces: definePageMeta({ middleware: 'auth', permission: 'users.manage' })
 */
declare module '#app' {
  interface PageMeta {
    permission?: string
  }
}

declare module 'vue-router' {
  interface RouteMeta {
    permission?: string
  }
}

export {}