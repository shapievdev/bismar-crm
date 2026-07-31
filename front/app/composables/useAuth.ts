import type { FetchError } from 'ofetch'
import type {
  LoginCredentials,
  RegisterCredentials,
  ResourceResponse,
  User,
  ValidationErrorResponse,
} from '~/types/auth'

/**
 * Field-level validation errors returned by Laravel, keyed by input name.
 */
export type ValidationErrors = Record<string, string[]>

export class ApiValidationError extends Error {
  constructor(message: string, readonly errors: ValidationErrors) {
    super(message)
    this.name = 'ApiValidationError'
  }
}

function toValidationError(error: unknown): never {
  const fetchError = error as FetchError<ValidationErrorResponse>

  if (fetchError.response?.status === 422 && fetchError.data) {
    throw new ApiValidationError(fetchError.data.message, fetchError.data.errors ?? {})
  }

  throw error
}

export function useAuth() {
  const { $api } = useNuxtApp()
  const user = useState<User | null>('auth.user', () => null)
  const isAuthenticated = computed(() => user.value !== null)

  /**
   * Whether the user holds a permission.
   *
   * This drives what the UI offers, not what it is allowed to do — the API
   * re-checks every request, so a stale answer here is a cosmetic issue only.
   */
  function can(permission: string): boolean {
    return user.value?.permissions.includes(permission) ?? false
  }

  function hasRole(role: string): boolean {
    return user.value?.roles.includes(role) ?? false
  }

  /**
   * Restores the session after a page load. A 401 simply means "not logged in",
   * so it resolves to null rather than throwing.
   */
  async function fetchUser(): Promise<User | null> {
    try {
      const { data } = await $api<ResourceResponse<User>>('/api/auth/user')
      user.value = data
    }
    catch {
      user.value = null
    }

    return user.value
  }

  async function login(credentials: LoginCredentials): Promise<User> {
    const { data } = await $api<ResourceResponse<User>>('/api/auth/login', {
      method: 'POST',
      body: credentials,
    }).catch(toValidationError)

    user.value = data

    return data
  }

  async function register(credentials: RegisterCredentials): Promise<User> {
    const { data } = await $api<ResourceResponse<User>>('/api/auth/register', {
      method: 'POST',
      body: credentials,
    }).catch(toValidationError)

    user.value = data

    return data
  }

  /**
   * Clears local state even if the request fails, so a user who clicks "log out"
   * is never left looking at an authenticated UI.
   */
  async function logout(): Promise<void> {
    try {
      await $api('/api/auth/logout', { method: 'POST' })
    }
    finally {
      user.value = null
    }
  }

  return {
    user: readonly(user),
    isAuthenticated,
    can,
    hasRole,
    fetchUser,
    login,
    register,
    logout,
  }
}