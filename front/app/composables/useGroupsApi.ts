import type { ResourceResponse } from '~/types/auth'
import type { Group, GroupPayload } from '~/types/structure'
import { toValidationError } from '~/composables/useAuth'

/**
 * Группы сотрудников — списки людей, собранные вручную.
 *
 * Читают их все, кто вошёл: без названия группы не выбрать адресата новости.
 * Заводит и правит администратор — сервер проверяет это сам
 * (EnsureAdministrator), а экран лишь не предлагает того, чего нельзя.
 *
 * Ответ на любую правку состава — карточка группы целиком: состав меняется
 * вместе с числом людей, и собирать их на клиенте по кусочкам значит однажды
 * разойтись с тем, что в базе.
 */
export function useGroupsApi() {
  const { $api } = useNuxtApp()

  return {
    /** Список групп — с числом людей, без состава. */
    fetchGroups: (search = ''): Promise<ResourceResponse<Group[]>> =>
      $api<ResourceResponse<Group[]>>('/api/groups', { query: { search } }),

    /** Одна группа — с составом. */
    fetchGroup: (id: number): Promise<ResourceResponse<Group>> =>
      $api<ResourceResponse<Group>>(`/api/groups/${id}`),

    createGroup: (body: GroupPayload): Promise<ResourceResponse<Group>> =>
      $api<ResourceResponse<Group>>('/api/groups', { method: 'POST', body })
        .catch(toValidationError),

    updateGroup: (id: number, body: GroupPayload): Promise<ResourceResponse<Group>> =>
      $api<ResourceResponse<Group>>(`/api/groups/${id}`, { method: 'PUT', body })
        .catch(toValidationError),

    deleteGroup: (id: number): Promise<void> =>
      $api(`/api/groups/${id}`, { method: 'DELETE' }),

    /** Добавляет людей разом: их набирают подряд, и запрос на каждого означал
     * бы, что половина состава добавилась, а половина нет. */
    addGroupPeople: (id: number, userIds: number[]): Promise<ResourceResponse<Group>> =>
      $api<ResourceResponse<Group>>(`/api/groups/${id}/people`, {
        method: 'POST',
        body: { user_ids: userIds },
      }).catch(toValidationError),

    removeGroupPerson: (id: number, userId: number): Promise<ResourceResponse<Group>> =>
      $api<ResourceResponse<Group>>(`/api/groups/${id}/people/${userId}`, { method: 'DELETE' }),
  }
}
