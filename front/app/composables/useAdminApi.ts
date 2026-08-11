import type {
  AccessPayload,
  NewUserPayload,
  PermissionOption,
  ResourceResponse,
  User,
  UserPayload,
} from '~/types/auth'

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

    fetchUsers: (): Promise<ResourceResponse<User[]>> =>
      $api<ResourceResponse<User[]>>('/api/users'),

    createUser: (body: NewUserPayload): Promise<ResourceResponse<User>> =>
      $api<ResourceResponse<User>>('/api/users', { method: 'POST', body }),

    updateUser: (user: User, body: UserPayload): Promise<ResourceResponse<User>> =>
      $api<ResourceResponse<User>>(`/api/users/${user.id}`, { method: 'PUT', body }),

    /** Standing and permissions, saved together — they are one decision. */
    updateAccess: (user: User, body: AccessPayload): Promise<ResourceResponse<User>> =>
      $api<ResourceResponse<User>>(`/api/users/${user.id}/access`, { method: 'PUT', body }),
  }
}
