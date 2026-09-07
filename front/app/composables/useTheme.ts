export type ThemePreference = 'system' | 'light' | 'dark'
export type PalettePreference = 'graphite' | 'crimson'

const THEME_COOKIE = 'bismar-theme'
const PALETTE_COOKIE = 'bismar-palette'
const PREFERENCES: ThemePreference[] = ['system', 'light', 'dark']
const PALETTES: PalettePreference[] = ['graphite', 'crimson']

const COOKIE = {
  maxAge: 60 * 60 * 24 * 365,
  sameSite: 'lax',
  path: '/',
} as const

/**
 * The reader's colour scheme.
 *
 * Two independent choices, not one list: the theme says whether the screen is
 * light or dark, the palette says which colours light and dark are painted in.
 * Folding them into a single list would mean naming every combination, and the
 * list would double with each palette added.
 *
 * Both are stored in cookies rather than local storage so the server can read
 * them while rendering and stamp the attributes into the HTML it sends. Local
 * storage is only readable after hydration, by which point the page has already
 * painted — a reader who chose light would see a dark flash on every
 * navigation.
 *
 * Both are per browser, not per account: they describe the screen in front of
 * you, and the same person may want dark on a laptop at night and light on a
 * bright office monitor.
 */
export function useTheme() {
  const cookie = useCookie<ThemePreference>(THEME_COOKIE, { default: () => 'system', ...COOKIE })
  const paletteCookie = useCookie<PalettePreference>(PALETTE_COOKIE, { default: () => 'graphite', ...COOKIE })

  const preference = computed<ThemePreference>(() =>
    PREFERENCES.includes(cookie.value) ? cookie.value : 'system',
  )

  const palette = computed<PalettePreference>(() =>
    PALETTES.includes(paletteCookie.value) ? paletteCookie.value : 'graphite',
  )

  /**
   * `system` writes no attribute at all, which leaves the media query in charge
   * and lets the page follow the reader's setting as it changes.
   */
  const htmlAttribute = computed(() =>
    preference.value === 'system' ? undefined : preference.value,
  )

  /**
   * The palette, in contrast, is always written out: there is no system
   * preference for it to follow, and the default is a value of its own.
   */
  const paletteAttribute = computed(() => palette.value)

  function setTheme(next: ThemePreference) {
    cookie.value = next

    if (import.meta.client) {
      const root = document.documentElement

      if (next === 'system') {
        root.removeAttribute('data-theme')
      }
      else {
        root.setAttribute('data-theme', next)
      }
    }
  }

  function setPalette(next: PalettePreference) {
    paletteCookie.value = next

    if (import.meta.client) {
      document.documentElement.setAttribute('data-palette', next)
    }
  }

  /**
   * One word each: they sit side by side in a segmented control, where a long
   * label would push the group past the width of a phone.
   */
  const options: { value: ThemePreference, label: string }[] = [
    { value: 'system', label: 'Системная' },
    { value: 'light', label: 'Светлая' },
    { value: 'dark', label: 'Тёмная' },
  ]

  /**
   * Палитры показываются двумя точками — тем цветом, который в каждой несёт
   * главное действие, и тем, который отмечает важное. Названия цветов человеку
   * ничего не говорят, а два кружка отвечают на вопрос «как это будет
   * выглядеть» до того, как он нажмёт.
   */
  const palettes: { value: PalettePreference, label: string, swatch: [string, string] }[] = [
    { value: 'graphite', label: 'Графит', swatch: ['#111413', '#d3f84b'] },
    { value: 'crimson', label: 'Красная', swatch: ['#d51f2a', '#2e3f4f'] },
  ]

  return { preference, palette, htmlAttribute, paletteAttribute, setTheme, setPalette, options, palettes }
}
