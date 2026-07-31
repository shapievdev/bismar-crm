export interface User {
  id: number
  name: string
  email: string
  email_verified_at: string | null
  created_at: string | null
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