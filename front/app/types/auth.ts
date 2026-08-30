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
  /** «+79990009977» — один вид на всю систему. Пусто, если не заполнен. */
  phone: string | null
  /** Должность. Необязательна: заполняют, когда есть что написать. */
  job_title: string | null
  avatar_url: string | null
  email_verified_at: string | null
  created_at: string | null
  /** С какого числа человек уволен. У работающих пусто. */
  dismissed_at: string | null
  /** Всё, что человек реально может. У администратора — весь список. */
  permissions: string[]
  /**
   * Где человек в структуре компании. Приходит только со своей учётной
   * записью и с карточки сотрудника — списку это лишний запрос на строку.
   */
  departments?: { id: number, name: string, role: string, role_label: string }[]
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
  phone: string | null
  job_title: string | null
  password: string
}

export interface UserPayload {
  last_name: string
  first_name: string
  middle_name: string | null
  email: string
  phone: string | null
  job_title: string | null
  /** Отправляется только когда администратор сбрасывает пароль. */
  password?: string
}

/**
 * Учётная запись сотрудника в том виде, в каком её правят: телефон здесь
 * лежит с маской, а пароль пустой означает «оставить прежний».
 */
export interface StaffAccountDraft {
  last_name: string
  first_name: string
  middle_name: string
  email: string
  phone: string
  job_title: string
  password: string
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