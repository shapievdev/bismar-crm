import type { PermissionOption, ResourceResponse, Role, User } from '~/types/auth'

/**
 * Thin typed wrapper over the roles and users endpoints, so pages describe what
 * they want rather than repeating URLs and response shapes.
 */
export function useAdminApi() {
  const { $api } = useNuxtApp()

  return {
    fetchRoles: (): Promise<ResourceResponse<Role[]>> =>
      $api<ResourceResponse<Role[]>>('/api/roles'),

    fetchPermissions: (): Promise<ResourceResponse<PermissionOption[]>> =>
      $api<ResourceResponse<PermissionOption[]>>('/api/permissions'),

    updateRolePermissions: (role: Role, permissions: string[]): Promise<ResourceResponse<Role>> =>
      $api<ResourceResponse<Role>>(`/api/roles/${role.name}`, {
        method: 'PUT',
        body: { permissions },
      }),

    fetchUsers: (): Promise<ResourceResponse<User[]>> =>
      $api<ResourceResponse<User[]>>('/api/users'),

    updateUserRoles: (user: User, roles: string[]): Promise<ResourceResponse<User>> =>
      $api<ResourceResponse<User>>(`/api/users/${user.id}/roles`, {
        method: 'PUT',
        body: { roles },
      }),
  }
}