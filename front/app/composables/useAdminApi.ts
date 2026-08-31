import type {
  AccessPayload,
  NewUserPayload,
  PermissionOption,
  ResourceResponse,
  User,
  UserPayload,
} from '~/types/auth'
import type { PaginatedResponse } from '~/types/lms'

/** Чего просят у списка сотрудников: страницу и строку поиска. */
export interface StaffQuery {
  search?: string
  page?: number
}

/**
 * Typed access to the user endpoints, so pages describe what they want rather
 * than repeating URLs and response shapes.
 */
export function useAdminApi() {
  const { $api } = useNuxtApp()

  return {
    /** The catalogue of grantable permissions, for the access editor. */
    fetchPermissions: (): Promise<ResourceResponse<PermissionOption[]>> =>
      $api<ResourceResponse<PermissionOption[]>>('/api/permissions'),

    /**
     * Список сотрудников — постранично и с поиском по фамилии, имени и почте.
     * Отбор делает сервер: на странице двадцать пять человек из всех, и искать
     * по ней значило бы находить только тех, кто и так на виду.
     */
    fetchUsers: (query: StaffQuery = {}): Promise<PaginatedResponse<User>> =>
      $api<PaginatedResponse<User>>('/api/users', { query }),

    /** Один сотрудник — для его профиля. */
    fetchStaffMember: (id: number): Promise<ResourceResponse<User>> =>
      $api<ResourceResponse<User>>(`/api/users/${id}`),

    createUser: (body: NewUserPayload): Promise<ResourceResponse<User>> =>
      $api<ResourceResponse<User>>('/api/users', { method: 'POST', body }),

    updateUser: (user: User, body: UserPayload): Promise<ResourceResponse<User>> =>
      $api<ResourceResponse<User>>(`/api/users/${user.id}`, { method: 'PUT', body }),

    /** Standing and permissions, saved together — they are one decision. */
    updateAccess: (user: User, body: AccessPayload): Promise<ResourceResponse<User>> =>
      $api<ResourceResponse<User>>(`/api/users/${user.id}/access`, { method: 'PUT', body }),

    /** Увольнение: запись остаётся, платформа для человека закрывается. */
    dismissUser: (user: User): Promise<ResourceResponse<User>> =>
      $api<ResourceResponse<User>>(`/api/users/${user.id}/dismissal`, { method: 'POST' }),

    /** Возвращение в строй — с прежним уровнем доступа и прежними правами. */
    reinstateUser: (user: User): Promise<ResourceResponse<User>> =>
      $api<ResourceResponse<User>>(`/api/users/${user.id}/dismissal`, { method: 'DELETE' }),

    /** Удаление насовсем: только суперадминистратором и только уволенного. */
    deleteUser: (user: User): Promise<void> =>
      $api(`/api/users/${user.id}`, { method: 'DELETE' }),
  }
}
