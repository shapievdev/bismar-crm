import type {
  AccessPayload,
  NewUserPayload,
  PermissionOption,
  ResourceResponse,
  StaffTag,
  User,
  UserPayload,
} from '~/types/auth'
import type { PaginatedResponse } from '~/types/lms'
import { toValidationError } from '~/composables/useAuth'

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

    /*
     * Отправляющие форму разбирают отказ по полям — как и везде, где форму
     * отправляют. Без `.catch(toValidationError)` ответ 422 доезжал до экрана
     * обычной ошибкой сети: занятый номер, слабый пароль и неверно набранный
     * телефон выглядели одинаково — «не удалось завести сотрудника», и человеку
     * оставалось гадать, что именно не так.
     */
    createUser: (body: NewUserPayload): Promise<ResourceResponse<User>> =>
      $api<ResourceResponse<User>>('/api/users', { method: 'POST', body })
        .catch(toValidationError),

    updateUser: (user: User, body: UserPayload): Promise<ResourceResponse<User>> =>
      $api<ResourceResponse<User>>(`/api/users/${user.id}`, { method: 'PUT', body })
        .catch(toValidationError),

    /** Standing and permissions, saved together — they are one decision. */
    updateAccess: (user: User, body: AccessPayload): Promise<ResourceResponse<User>> =>
      $api<ResourceResponse<User>>(`/api/users/${user.id}/access`, { method: 'PUT', body })
        .catch(toValidationError),

    /** Увольнение: запись остаётся, платформа для человека закрывается. */
    /**
     * Увольнение. Причина необязательна: человека надо отключить сейчас, а не
     * когда кадровик решит, какой из шести пунктов ближе. Без неё он просто не
     * попадёт в доли причин — отчёт называет число таких отдельно.
     */
    dismissUser: (user: User, reason?: string | null): Promise<ResourceResponse<User>> =>
      $api<ResourceResponse<User>>(`/api/users/${user.id}/dismissal`, {
        method: 'POST',
        body: { reason: reason || null },
      }),

    /** Возвращение в строй — с прежним уровнем доступа и прежними правами. */
    reinstateUser: (user: User): Promise<ResourceResponse<User>> =>
      $api<ResourceResponse<User>>(`/api/users/${user.id}/dismissal`, { method: 'DELETE' }),

    /** Удаление насовсем: только суперадминистратором и только уволенного. */
    deleteUser: (user: User): Promise<void> =>
      $api(`/api/users/${user.id}`, { method: 'DELETE' }),

    /* ---------- Ручные теги ---------- */

    /** Справочник целиком: его читает всякий, кто видит людей. */
    fetchStaffTags: (): Promise<ResourceResponse<StaffTag[]>> =>
      $api<ResourceResponse<StaffTag[]>>('/api/staff-tags'),

    createStaffTag: (name: string): Promise<ResourceResponse<StaffTag>> =>
      $api<ResourceResponse<StaffTag>>('/api/staff-tags', { method: 'POST', body: { name } })
        .catch(toValidationError),

    renameStaffTag: (tag: StaffTag, name: string): Promise<ResourceResponse<StaffTag>> =>
      $api<ResourceResponse<StaffTag>>(`/api/staff-tags/${tag.id}`, { method: 'PUT', body: { name } })
        .catch(toValidationError),

    /** Удаление снимает тег со всех, на ком он висел, — он ярлык, а не событие. */
    deleteStaffTag: (tag: StaffTag): Promise<void> =>
      $api(`/api/staff-tags/${tag.id}`, { method: 'DELETE' }),

    /** Теги одного человека целиком: что прислали, то на нём и останется. */
    updateStaffTagsOf: (user: User, tags: number[]): Promise<ResourceResponse<User>> =>
      $api<ResourceResponse<User>>(`/api/users/${user.id}/tags`, { method: 'PUT', body: { tags } }),
  }
}
