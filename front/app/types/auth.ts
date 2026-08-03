export interface User {
  id: number
  name: string
  email: string
  avatar_url: string | null
  email_verified_at: string | null
  created_at: string | null
  roles: string[]
  permissions: string[]
}

export interface Role {
  id: number
  name: string
  label: string
  is_built_in: boolean
  permissions: string[]
  users_count?: number
}

export interface PermissionOption {
  name: string
  label: string
  group: string
}

export interface LoginCredentials {
  email: string
  password: string
  remember?: boolean
}

export interface RegisterCredentials {
  name: string
  email: string
  password: string
  password_confirmation: string
}

/** Laravel wraps API resources in a `data` envelope. */
export interface ResourceResponse<T> {
  data: T
}

/** Shape of a Laravel 422 validation error body. */
export interface ValidationErrorResponse {
  message: string
  errors: Record<string, string[]>
}