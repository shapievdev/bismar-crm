export interface User {
  id: number
  level: AccessLevel
  level_label: string
  /** Права, отмеченные лично этому человеку. У администратора пусто. */
  own_permissions: string[]
  /** Фамилия Имя Отчество, собранное на сервере — то, что показывают экраны. */
  name: string
  last_name: string | null
  first_name: string
  middle_name: string | null
  email: string
  avatar_url: string | null
  email_verified_at: string | null
  created_at: string | null
  /** Всё, что человек реально может. У администратора — весь список. */
  permissions: string[]
}

/** Суперадминистратор, администратор или обычный пользователь. */
export type AccessLevel = 'super-admin' | 'admin' | 'user'

export interface AccessPayload {
  level: AccessLevel
  permissions: string[]
}

export interface NewUserPayload {
  last_name: string
  first_name: string
  middle_name: string | null
  email: string
  password: string
}

export interface UserPayload {
  last_name: string
  first_name: string
  middle_name: string | null
  email: string
  /** Отправляется только когда администратор сбрасывает пароль. */
  password?: string
}

export interface PermissionOption {
  name: string
  label: string
  group: string
  group_label: string
}

export interface LoginCredentials {
  email: string
  password: string
  remember?: boolean
}

export interface RegisterCredentials {
  last_name: string
  first_name: string
  middle_name: string
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