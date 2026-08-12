// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },

  modules: ['@vite-pwa/nuxt'],

  css: ['~/assets/css/main.css'],

  app: {
    head: {
      /*
       * `interactive-widget=resizes-content` — про выезжающую клавиатуру.
       *
       * По умолчанию она сжимает только видимую область, разметочная остаётся
       * прежней, и низ страницы уходит под клавиатуру. С этим значением
       * браузер сжимает саму разметку, и `100dvh` честно означает «то, что
       * видно», — а значит поле ввода остаётся над клавиатурой само, без
       * единой строчки скрипта.
       */
      viewport: 'width=device-width, initial-scale=1, interactive-widget=resizes-content',

      // The SVG mark carries its own light/dark rule, so one file covers both.
      link: [
        { rel: 'icon', type: 'image/svg+xml', href: '/logo.svg' },
        // iOS ignores the manifest's icons and takes this one, which is why it
        // is full-bleed: the system rounds the corners itself.
        { rel: 'apple-touch-icon', href: '/apple-touch-icon.png' },
        /*
         * Ссылка на манифест выписана здесь, а не оставлена модулю: он лишь
         * пишет файл на диск, а в разметку его подставляет плагин Vite через
         * index.html, которого у приложения с серверной отрисовкой нет. Без
         * этой строки манифест лежит рядом и не читается никем, а браузер не
         * предлагает установку.
         */
        { rel: 'manifest', href: '/manifest.webmanifest' },
      ],
      meta: [
        /*
         * Цвет полосы браузера и системных панелей в установленном приложении.
         * Манифест знает только одно значение, поэтому тему держат эти два: у
         * каждого свой медиа-запрос, и полоса следует за системной темой так же,
         * как сами страницы.
         */
        { name: 'theme-color', media: '(prefers-color-scheme: light)', content: '#e8eaea' },
        { name: 'theme-color', media: '(prefers-color-scheme: dark)', content: '#0e100f' },
      ],
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

  /*
   * Устанавливаемое приложение: значок на домашнем экране и собственное окно
   * без адресной строки.
   *
   * Работать без сети оно не умеет и не должно: каждая страница здесь живёт
   * ответом API, и показанный из кэша список задолженностей был бы хуже
   * честного «нет связи». Поэтому кэшируется только оболочка — сборка, шрифты,
   * значки, — а к API service worker не притрагивается вовсе.
   */
  pwa: {
    // Оболочка обновляется сама: свежая версия важнее, чем спрошенное согласие,
    // а несохранённого состояния между вкладками приложение не держит.
    registerType: 'autoUpdate',

    manifest: {
      name: 'Bismar CRM',
      short_name: 'Bismar',
      description: 'База знаний, аналитика продаж и мессенджер сотрудников',
      lang: 'ru',
      dir: 'ltr',
      display: 'standalone',
      orientation: 'any',
      start_url: '/',
      scope: '/',
      background_color: '#e8eaea',
      theme_color: '#e8eaea',
      icons: [
        { src: '/icons/pwa-192x192.png', sizes: '192x192', type: 'image/png' },
        { src: '/icons/pwa-512x512.png', sizes: '512x512', type: 'image/png' },
        // Значок под маску системы: подложка во всё поле, знак внутри круга
        // безопасной зоны, поэтому его не срежет ни одна форма.
        { src: '/icons/maskable-512x512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
      ],
    },

    workbox: {
      globPatterns: ['**/*.{js,css,svg,png,ico,woff2}'],
      cleanupOutdatedCaches: true,

      /*
       * Переходы по страницам service worker не перехватывает: разметку отдаёт
       * сервер, и подменять её сохранённой оболочкой значит показать вчерашний
       * каркас поверх сегодняшних данных.
       *
       * Ключ выписан со значением undefined намеренно, и убирать его нельзя.
       * Модуль подставляет сюда «/», когда ключа НЕТ вовсе (`!('navigateFallback'
       * in workbox)`), а страницы «/» в предзагруженном списке у приложения с
       * серверной отрисовкой не бывает — workbox на ненайденный адрес бросает
       * исключение, и service worker не переживает активацию.
       */
      navigateFallback: undefined,

      /*
       * Правил runtimeCaching нет намеренно: не описанное здесь не кэшируется,
       * и запросы к API проходят мимо service worker. Иначе после выхода из
       * системы в кэше остались бы чужие данные.
       */
    },

    // В разработке service worker только мешает: правка отдаётся из кэша, и
    // непонятно, почему её не видно.
    devOptions: { enabled: false },
  },

  typescript: {
    typeCheck: false,
    strict: true,
  },
})