import type { ResourceResponse } from '~/types/auth'
import type { FavouriteItem, FavouriteKind, FavouriteRoute } from '~/types/lms'

/**
 * Избранное: материалы, которые человек отложил себе сам.
 *
 * Своё и только своё — чужого избранного в приложении нет нигде, поэтому и
 * номера человека в адресах нет: сервер отвечает про того, кто спросил.
 *
 * Вид в адресе стоит словом раздела, а не именем вида: у документа и
 * справочника вид один на двоих («regulation»), а путь к ним один и тот же
 * («materials»). Перевод из одного в другое живёт здесь — см. ROUTES.
 */
const ROUTES: Record<FavouriteKind, FavouriteRoute> = {
  course: 'courses',
  lesson: 'lessons',
  regulation: 'materials',
}

export function useFavouritesApi() {
  const { $api } = useNuxtApp()
  const base = '/api/lms/favourites'

  return {
    fetchFavourites: (): Promise<ResourceResponse<FavouriteItem[]>> =>
      $api<ResourceResponse<FavouriteItem[]>>(base),

    /**
     * Отложить. Повторное нажатие ничего не ломает: звезда — положение, а не
     * счётчик, и сервер отвечает тем же «отложено».
     */
    keepFavourite: (kind: FavouriteKind, id: number): Promise<ResourceResponse<{ is_favourite: boolean }>> =>
      $api<ResourceResponse<{ is_favourite: boolean }>>(`${base}/${ROUTES[kind]}/${id}`, { method: 'POST' }),

    forgetFavourite: (kind: FavouriteKind, id: number): Promise<ResourceResponse<{ is_favourite: boolean }>> =>
      $api<ResourceResponse<{ is_favourite: boolean }>>(`${base}/${ROUTES[kind]}/${id}`, { method: 'DELETE' }),
  }
}
