import type { Ref } from 'vue'

export interface DebouncedSearch<T> {
  /** Что набрали. Годится для v-model. */
  query: Ref<string>
  results: Ref<T[]>
  isSearching: Ref<boolean>
  clear: () => void
}

/**
 * Подсказка поиска: ищет с задержкой и показывает только ответ на последний
 * запрос.
 *
 * Задержка — чтобы не спрашивать сервер на каждую букву. Отметка запроса — от
 * гонки: набранное целиком нередко возвращается раньше, чем ответ на первую
 * букву, и без неё список мигал бы результатами уже стёртого слова.
 *
 * Пустая строка гасит подсказку, а не ищет пустоту: показывать всех подряд
 * там, где ждут одного, — не помощь.
 */
export function useDebouncedSearch<T>(
  find: (term: string) => Promise<T[]>,
  delayMs = 250,
): DebouncedSearch<T> {
  const query = ref('')
  const results = ref<T[]>([]) as Ref<T[]>
  const isSearching = ref(false)

  let timer: ReturnType<typeof setTimeout> | undefined
  let latest = 0

  watch(query, (value) => {
    clearTimeout(timer)

    const term = value.trim()

    if (term === '') {
      results.value = []
      isSearching.value = false

      return
    }

    isSearching.value = true

    timer = setTimeout(async () => {
      const mine = ++latest

      try {
        const found = await find(term)

        if (mine === latest) {
          results.value = found
        }
      }
      catch {
        if (mine === latest) {
          results.value = []
        }
      }
      finally {
        if (mine === latest) {
          isSearching.value = false
        }
      }
    }, delayMs)
  })

  onBeforeUnmount(() => clearTimeout(timer))

  return {
    query,
    results,
    isSearching,
    clear: () => {
      query.value = ''
      results.value = []
    },
  }
}
