import test from 'node:test'
import assert from 'node:assert/strict'
import { computed, ref, toValue } from 'vue'

// Use the real Vue reactivity and the same cookie state that Nuxt restores in SSR.
const cookies = new Map()
const user = ref({ id: 101 })
Object.assign(globalThis, {
  computed, toValue,
  useAuth: () => ({ user }),
  useCookie: (name, options) => {
    if (!cookies.has(name)) cookies.set(name, ref(options.default()))
    return cookies.get(name)
  },
})
const { useMaterialTestMode } = await import('../app/composables/useMaterialTestMode.ts')

test.beforeEach(() => { cookies.clear(); user.value = { id: 101 } })

test('reading the material does not start a test; explicit start hides it', () => {
  const mode = useMaterialTestMode('lesson:54')
  assert.equal(mode.testActive.value, false)
  mode.beginTest()
  assert.equal(mode.testActive.value, true)
})

test('page reload restores an active test and its selected version', () => {
  useMaterialTestMode('lesson:54').beginTest(72)
  const reloaded = useMaterialTestMode('lesson:54')
  assert.equal(reloaded.testActive.value, true)
  assert.equal(reloaded.testVersion.value, 72)
})

test('the base version is restored even if the user normally has another version', () => {
  useMaterialTestMode('material:12').beginTest(null)
  const reloaded = useMaterialTestMode('material:12')
  assert.equal(reloaded.testActive.value, true)
  assert.equal(reloaded.testVersion.value, null)
})

test('finishing a failed or passed attempt unlocks the material and persists this on reload', () => {
  const mode = useMaterialTestMode('lesson:54')
  mode.beginTest(72)
  mode.finishTest()
  assert.equal(mode.testActive.value, false)
  assert.equal(useMaterialTestMode('lesson:54').testActive.value, false)
  mode.beginTest(72)
  assert.equal(mode.testActive.value, true)
})

test('an unfinished request stays active until the success handler finishes it', () => {
  const mode = useMaterialTestMode('material:12')
  mode.beginTest()
  assert.equal(useMaterialTestMode('material:12').testActive.value, true)
  assert.equal(useMaterialTestMode('material:13').testActive.value, false)
})

test('a different employee cannot inherit the previous employee test mode', () => {
  useMaterialTestMode('lesson:54').beginTest(72)
  user.value = { id: 202 }
  assert.equal(useMaterialTestMode('lesson:54').testActive.value, false)
  user.value = { id: 101 }
  assert.equal(useMaterialTestMode('lesson:54').testActive.value, true)
})

test('switching material routes keeps each material state separate', () => {
  const owner = ref('lesson:54')
  const mode = useMaterialTestMode(owner)
  mode.beginTest(72)
  owner.value = 'lesson:55'
  assert.equal(mode.testActive.value, false)
  owner.value = 'lesson:54'
  assert.equal(mode.testActive.value, true)
})
