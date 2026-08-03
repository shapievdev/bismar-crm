export type ThemePreference = 'system' | 'light' | 'dark'

const THEME_COOKIE = 'bismar-theme'
const PREFERENCES: ThemePreference[] = ['system', 'light', 'dark']

/**
 * The reader's colour scheme.
 *
 * Stored in a cookie rather than local storage so the server can read it while
 * rendering and stamp the attribute into the HTML it sends. Local storage is
 * only readable after hydration, by which point the page has already painted —
 * a reader who chose light would see a dark flash on every navigation.
 *
 * The preference is per browser, not per account: it describes the screen in
 * front of you, and the same person may want dark on a laptop at night and
 * light on a bright office monitor.
 */
export function useTheme() {
  const cookie = useCookie<ThemePreference>(THEME_COOKIE, {
    default: () => 'system',
    maxAge: 60 * 60 * 24 * 365,
    sameSite: 'lax',
    path: '/',
  })

  const preference = computed<ThemePreference>(() =>
    PREFERENCES.includes(cookie.value) ? cookie.value : 'system',
  )

  /**
   * `system` writes no attribute at all, which leaves the media query in charge
   * and lets the page follow the reader's setting as it changes.
   */
  const htmlAttribute = computed(() =>
    preference.value === 'system' ? undefined : preference.value,
  )

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

  const options: { value: ThemePreference, label: string, hint: string }[] = [
    { value: 'system', label: 'Как в системе', hint: 'Следует настройке устройства' },
    { value: 'light', label: 'Светлая', hint: 'Всегда светлая' },
    { value: 'dark', label: 'Тёмная', hint: 'Всегда тёмная' },
  ]

  return { preference, htmlAttribute, setTheme, options }
}
