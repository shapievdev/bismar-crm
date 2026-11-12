import { toValidationError } from '~/composables/useAuth'
import type {
  Achievement,
  AchievementPayload,
  AwardedAchievement,
} from '~/types/achievements'
import type { ResourceResponse } from '~/types/auth'

/**
 * Достижения: каталог значков и свои заслуги.
 *
 * Каталог читает всякий, кто вошёл, — его спрашивает и редактор этапа, когда
 * предлагает выбрать значок. Правка под правом `achievements.manage`.
 *
 * Выдачи здесь нет: значок зарабатывают закрытым шагом маршрута, и раздаёт их
 * сервер при пересчёте — руками их не вручают и не отбирают.
 */
export function useAchievementsApi() {
  const { $api } = useNuxtApp()

  return {
    /** Каталог. `hidden` — убранные из выбора, экран администратора. */
    fetchAchievements: (hidden = false): Promise<{ data: Achievement[] }> =>
      $api<{ data: Achievement[] }>('/api/achievements', {
        query: hidden ? { hidden: 1 } : undefined,
      }),

    createAchievement: (payload: AchievementPayload): Promise<ResourceResponse<Achievement>> =>
      $api<ResourceResponse<Achievement>>('/api/achievements', {
        method: 'POST',
        body: payload,
      }).catch(toValidationError),

    updateAchievement: (id: number, payload: AchievementPayload): Promise<ResourceResponse<Achievement>> =>
      $api<ResourceResponse<Achievement>>(`/api/achievements/${id}`, {
        method: 'PUT',
        body: payload,
      }).catch(toValidationError),

    /** Картинка — формой, как аватарка: остальные поля идут обычным JSON. */
    uploadImage: (id: number, file: File): Promise<ResourceResponse<Achievement>> => {
      const body = new FormData()
      body.append('image', file)

      return $api<ResourceResponse<Achievement>>(`/api/achievements/${id}/image`, {
        method: 'POST',
        body,
      }).catch(toValidationError)
    },

    removeImage: (id: number): Promise<ResourceResponse<Achievement>> =>
      $api<ResourceResponse<Achievement>>(`/api/achievements/${id}/image`, { method: 'DELETE' }),

    /** Прячет значок: из выбора пропадает, из чужих профилей — нет. */
    hideAchievement: (id: number): Promise<void> =>
      $api(`/api/achievements/${id}`, { method: 'DELETE' }),

    restoreAchievement: (id: number): Promise<ResourceResponse<Achievement>> =>
      $api<ResourceResponse<Achievement>>(`/api/achievements/${id}/restore`, { method: 'POST' }),

    /** Свои заслуги — для профиля. */
    fetchMyAchievements: (): Promise<{ data: AwardedAchievement[] }> =>
      $api<{ data: AwardedAchievement[] }>('/api/profile/achievements'),
  }
}
