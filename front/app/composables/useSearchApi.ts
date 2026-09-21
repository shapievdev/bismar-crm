import type { SearchSection } from '~/types/search'

/**
 * Поиск по платформе — одно обращение на всю шапку.
 *
 * Разделы не запрашиваются по одному: спрашивать каталог курсов, каталог
 * документов и список людей по отдельности значило бы на каждую букву будить
 * три запроса, а порядок разделов собирать на экране — то есть в том месте,
 * которое о правах человека знает меньше всех.
 */
export function useSearchApi() {
  const { $api } = useNuxtApp()

  return {
    searchEverywhere: (q: string): Promise<{ data: SearchSection[] }> =>
      $api<{ data: SearchSection[] }>('/api/search', { query: { q } }),
  }
}
