// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },

  css: ['~/assets/css/main.css'],

  app: {
    head: {
      // The SVG mark carries its own light/dark rule, so one file covers both.
      link: [{ rel: 'icon', type: 'image/svg+xml', href: '/logo.svg' }],
    },
  },

  // 3000 is left free for other local projects.
  devServer: { port: 3100 },

  runtimeConfig: {
    public: {
      // Base URL of the Laravel API. Override with NUXT_PUBLIC_API_BASE.
      apiBase: 'http://localhost:8100',

      /*
       * Сокет-сервер мессенджера — тот же Reverb, что поднят рядом с Laravel.
       *
       * Ключ здесь публичный по устройству: им клиент лишь называет себя при
       * подключении, а право читать канал выдаёт приложение подписью (см.
       * routes/channels.php). Секрет остаётся на сервере.
       *
       * Пустой ключ — рабочий случай: мессенджер тогда живёт без сокетов, и
       * сообщения появляются при обновлении страницы, а не сами.
       */
      reverbKey: '',
      reverbHost: 'localhost',
      reverbPort: 8080,
      reverbScheme: 'http',
    },
  },

  typescript: {
    typeCheck: false,
    strict: true,
  },
})