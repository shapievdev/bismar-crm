import type { MaybeRefOrGetter } from 'vue'

/** UI state only: the same test stays open after reload, including its version. */
export function useMaterialTestMode(owner: MaybeRefOrGetter<string>) {
  const { user } = useAuth()
  const sessions = useCookie<Record<string, number | null>>('bismar-material-test-v1', {
    default: () => ({}),
    sameSite: 'lax',
    maxAge: 60 * 60 * 24 * 60,
  })
  const key = computed(() => `${user.value?.id ?? 'guest'}:${toValue(owner)}`)
  const testActive = computed(() => Object.hasOwn(sessions.value ?? {}, key.value))
  const testVersion = computed(() => sessions.value?.[key.value] ?? null)

  function beginTest(versionId: number | null = null) {
    sessions.value = { ...sessions.value, [key.value]: versionId }
    if (import.meta.client) {
      void nextTick(() => document.querySelector('[data-material-test]')?.scrollIntoView({ block: 'start' }))
    }
  }

  function finishTest() {
    const next = { ...sessions.value }
    delete next[key.value]
    sessions.value = next
  }

  return { testActive, testVersion, beginTest, finishTest }
}
