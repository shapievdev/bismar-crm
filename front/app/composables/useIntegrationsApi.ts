import { toValidationError } from '~/composables/useAuth'
import type { ResourceResponse } from '~/types/auth'

/** Связка с Google: чем открывается окно выбора файла на Диске. */
export interface GoogleSettings {
  /** Что сохранено в настройках. Пусто — значит берётся из окружения сервера. */
  client_id: string | null
  api_key: string | null

  /** Что применится на самом деле, с учётом переменных окружения. */
  effective: {
    client_id: string | null
    api_key: string | null
  }

  /** Оба значения на месте — только тогда окно Google открывается. */
  is_configured: boolean

  updated_at: string | null
  updated_by?: string | null
}

export interface GoogleSettingsPayload {
  client_id: string | null
  api_key: string | null
}

/**
 * Настройки чужих служб.
 *
 * Читает их всякий, кто вошёл: окно Google открывается в его браузере, и без
 * этих значений оно не откроется. Меняет — администратор, и это проверяет
 * сервер.
 */
export function useIntegrationsApi() {
  const { $api } = useNuxtApp()

  return {
    fetchGoogleSettings: (): Promise<ResourceResponse<GoogleSettings>> =>
      $api<ResourceResponse<GoogleSettings>>('/api/integrations/google'),

    saveGoogleSettings: (body: GoogleSettingsPayload): Promise<ResourceResponse<GoogleSettings>> =>
      $api<ResourceResponse<GoogleSettings>>('/api/integrations/google', {
        method: 'PUT',
        body,
      }).catch(toValidationError),
  }
}
