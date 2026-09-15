import type { DepartmentRoleKind } from '~/types/structure'

/** Отдел, в котором человек числится, — с ролью, в которой он там стоит. */
export interface UserDepartment {
  id: number
  name: string
  role: DepartmentRoleKind
  role_label: string
}

/**
 * Ручной тег из справочника кадровика.
 *
 * `people` приходит только в самом справочнике: перед тем как удалить тег,
 * кадровик вправе знать, на скольких он висит.
 */
export interface StaffTag {
  id: number
  name: string
  position?: number
  people?: number
}

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
  /** Способ связи, а не логин: адрес есть не у каждого. */
  email: string | null
  /** «+79990009977» — один вид на всю систему. Пусто, если не заполнен. */
  phone: string | null
  /** Должность. Необязательна: заполняют, когда есть что написать. */
  job_title: string | null
  avatar_url: string | null
  email_verified_at: string | null
  created_at: string | null
  /** С какого числа человек уволен. У работающих пусто. */
  dismissed_at: string | null

  /*
   * Кадровое: когда принят, в каком положении числится, как работает и кто его
   * ведёт. Заведено 2026-09-15 вместе с аналитикой штата.
   */
  /** День приёма, «2025-03-14». Пусто у заведённых до того, как её спрашивали. */
  hired_at: string | null
  /** Работает / в декрете / ВРИО / уволен — последнее выводится из даты ухода. */
  status: 'working' | 'parental-leave' | 'acting' | 'dismissed'
  status_label: string
  work_mode: 'shift' | 'office' | null
  work_mode_label: string | null
  /** Полных месяцев в компании. Пусто, когда неизвестна дата приёма. */
  tenure_months: number | null
  /** Почему ушёл. Пусто у работающих и у уволенных до того, как причину спрашивали. */
  dismissal_reason: string | null
  dismissal_reason_label: string | null
  /** Кто ведёт этого человека. Приходит с карточки сотрудника. */
  mentor?: { id: number, name: string } | null
  /**
   * Ручные теги: «Кадровый резерв», «Испытательный продлён».
   *
   * Только повешенные рукой. Теги по стажу — «стажёр», «новичок», «старожил» —
   * сюда не попадают: они считаются из даты приёма и живут в отчёте о движении
   * персонала, а не в карточке.
   */
  tags?: StaffTag[]
  /** Всё, что человек реально может. У администратора — весь список. */
  permissions: string[]
  /**
   * Где человек в структуре компании. Приходит со своей учётной записью, с
   * карточки сотрудника и со списка сотрудников; в подсказках выбора людей
   * отделов нет.
   */
  departments?: UserDepartment[]
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
  email: string | null
  phone: string | null
  job_title: string | null
  /** День приёма. Спрашивается сразу: задним числом он не заполняется почти никогда. */
  hired_at: string | null
  work_mode: 'shift' | 'office' | null
  password: string
}

export interface UserPayload {
  last_name: string
  first_name: string
  middle_name: string | null
  email: string | null
  phone: string | null
  job_title: string | null

  /*
   * Кадровое. Пустое значит «убрать» — форма присылает запись целиком, и
   * непришедшее сервер тоже понял бы как «убрать».
   */
  hired_at: string | null
  employment_status: 'working' | 'parental-leave' | 'acting'
  work_mode: 'shift' | 'office' | null
  mentor_id: number | null

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
  /** День приёма, «2025-03-14». Пустая строка значит «не знаем». */
  hired_at: string
  employment_status: 'working' | 'parental-leave' | 'acting'
  /** Пустая строка — режим не указан. */
  work_mode: '' | 'shift' | 'office'
  /** Номер наставника строкой: поле выбора отдаёт строку. */
  mentor_id: string
  password: string
}

export interface PermissionOption {
  name: string
  label: string
  group: string
  group_label: string
}

export interface LoginCredentials {
  /** Логин — номер телефона: «+79990009977», уже без скобок и дефисов. */
  phone: string
  password: string
  remember?: boolean
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