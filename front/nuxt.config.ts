// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },

  css: ['~/assets/css/main.css'],

  runtimeConfig: {
    public: {
      // Base URL of the Laravel API. Override with NUXT_PUBLIC_API_BASE.
      apiBase: 'http://localhost:8000',
    },
  },

  typescript: {
    typeCheck: false,
    strict: true,
  },
})