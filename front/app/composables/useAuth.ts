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

  /**
   * Standing rather than permission: a superadmin is the only one who may
   * appoint administrators, so some controls are shown only to them.
   */
  const level = computed(() => user.value?.level ?? 'user')
  const isSuperAdmin = computed(() => level.value === 'super-admin')

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

  /** Saves the signed-in user's own name and address. */
  async function updateProfile(payload: {
    last_name: string
    first_name: string
    middle_name: string | null
    email: string
  }): Promise<User> {
    const { data } = await $api<ResourceResponse<User>>('/api/profile', {
      method: 'PUT',
      body: payload,
    }).catch(toValidationError)

    user.value = data

    return data
  }

  async function uploadAvatar(file: File): Promise<User> {
    const form = new FormData()
    form.append('avatar', file)

    const { data } = await $api<ResourceResponse<User>>('/api/profile/avatar', {
      method: 'POST',
      body: form,
    }).catch(toValidationError)

    user.value = data

    return data
  }

  async function removeAvatar(): Promise<User> {
    const { data } = await $api<ResourceResponse<User>>('/api/profile/avatar', {
      method: 'DELETE',
    })

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
    level,
    isSuperAdmin,
    fetchUser,
    login,
    register,
    logout,
    updateProfile,
    uploadAvatar,
    removeAvatar,
  }
}