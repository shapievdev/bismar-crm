/** Пресеты периода, которые предлагает панель фильтров. */
export type PeriodPreset = 'today' | 'week' | 'month' | 'quarter' | 'half-year' | 'year' | 'two-years'

/** Шаг, с которым период разбит на точки графика. */
export type Granularity = 'day' | 'week' | 'month'

/** Разрезы рейтинга продаж. */
export type SalesDimension = 'item' | 'manager' | 'customer' | 'warehouse' | 'seller' | 'direction' | 'organisation'

/** Разрезы товарного рейтинга. */
export type ProductDimension = 'item' | 'brand' | 'group' | 'segment' | 'matrix'

/** Списки отбора — все четыре устроены одинаково. */
export type FilterList = 'channels' | 'warehouses' | 'managers' | 'segments'

export interface PeriodMeta {
  from: string
  to: string
  granularity: Granularity
  days: number
}

/** Срез, за который спрашиваются цифры. Общий для всех вкладок. */
export interface AnalyticsFilters {
  preset?: PeriodPreset
  from?: string
  to?: string
  granularity?: Granularity
  channels: string[]
  warehouses: string[]
  managers: string[]
  segments: string[]
  /** Возвраты входят в выручку, пока их явно не исключили. */
  withReturns: boolean
}

export interface Option {
  value: string
  label: string
}

/** Значение фильтра с оборотом за ним — крупные склады встают выше мелких. */
export interface DirectoryValue {
  value: string
  revenue: number
}

/** По какое число доехала витрина. */
export interface Freshness {
  source: string
  label: string
  last_date: string
  age_days: number
}

/**
 * Аналитика обучения. Считается по нашей же базе, а не по витрине продаж:
 * ClickHouse об уроках ничего не знает.
 */
export interface LearningSummary {
  staff: number

  /* Материал: сколько его собрано и сколько из этого опубликовано. */
  courses: number
  published_courses: number
  lessons: number
  /** Документы и справочники — разные разделы со своими правами. */
  documents: number
  published_documents: number
  handbooks: number
  published_handbooks: number
  /** Версий у документов: каждый читает свою, и это разные тексты. */
  versions: number

  /* Курсы: назначено, начато, пройдено. */
  enrollments: number
  learners: number
  /** Записей, к которым не приступали: это не «медленно», а «не открывали». */
  not_started: number
  completed: number
  average_progress: number

  /* Проверки. */
  quiz_attempts: number
  quiz_passed: number
  quiz_average_score: number
  attestations: number
  /** Работ, ждущих проверки человеком. */
  attestations_pending: number

  /* Опросы. Ни планки, ни балла у опроса нет — сравнивать не с чем. */
  surveys: number
  surveys_required: number
  /** Сколько раз опросы прошли. Считается по отметкам, а не по ответам. */
  survey_answered: number

  /* Документы. */
  acknowledgements: number
  acknowledged_by: number

  /* План обучения. */
  plan_people: number
  plan_steps: number
  plan_done: number
}

/**
 * Курс в рейтинге.
 *
 * `audience` — круг допущенных: записанные плюс те, кому курс назначен планом.
 * Главная величина строки: «прошли семеро» ничего не значит, пока не сказано,
 * из скольких.
 */
export interface LearningCourseRow {
  id: number
  title: string
  slug: string
  is_published: boolean
  lessons: number
  audience: number
  enrolled: number
  started: number
  completed: number
  average_progress: number
}

/** Документ или справочник в рейтинге — с тем же кругом допущенных. */
export interface LearningMaterialRow {
  id: number
  title: string
  slug: string
  is_published: boolean
  audience: number
  /** Сколько версий: у документа с версиями каждый читает свою. */
  versions: number
  acknowledged: number
}

/**
 * Строка отчёта по проверкам — одна проверка, где бы она ни висела: при уроке,
 * при документе или при его версии. `attempted` и `passed` считаются по людям,
 * а не по попыткам.
 */
export interface LearningQuizRow {
  id: number
  title: string
  /** Аттестацию читает человек: «не сдал» у неё до проверки ничего не значит. */
  is_attestation: boolean
  owner: 'lesson' | 'regulation' | 'regulation_version'
  /** Документ или справочник — по нему строится ссылка. */
  document_kind?: 'document' | 'handbook' | null
  material: string | null
  course_title: string | null
  course_slug: string | null
  lesson_id: number | null
  document_slug: string | null
  /** Чья версия, если проверка висит на версии документа. */
  version_name: string | null
  questions: number
  attempted: number
  passed: number
  /** Сколько работ по этой проверке ждут человека. */
  pending: number
  average_score: number
}

/** Кто и как прошёл одну проверку. */
export interface LearningQuizResult {
  id: number
  name: string
  attempts: number
  best_score: number
  passed: boolean
  /** Ждёт проверки: спрашивать надо не с него. */
  awaiting: boolean
  last_at: string | null
}

/**
 * Человек за цифрой сводки — строка одна на все срезы.
 *
 * Срезы отвечают на разные вопросы, но читают их одной таблицей: семь таблиц на
 * одном экране означали бы семь способов прочитать фамилию.
 */
export interface LearningPerson {
  user_id: number
  name: string
  /** Что за ним: курс, документ или «Пройдено 1 из 3» у самого ученика. */
  title: string
  /** Куда ведёт строка. `null` — материала уже нет или его и не было. */
  path: string | null
  /** Состояние словом: «не приступал», «в очереди», «ждёт проверки». */
  state: string
  tone: 'success' | 'warning' | 'danger' | 'muted'
  /** Доля пройденного там, где цифра о прогрессе. */
  progress: number | null
  at: string | null
}

export interface LearningPayload {
  summary: LearningSummary
  courses: LearningCourseRow[]
  documents: LearningMaterialRow[]
  handbooks: LearningMaterialRow[]
  quizzes: LearningQuizRow[]
  surveys: LearningSurveyRow[]
}

/**
 * Опрос в отчёте — по образцу проверки, и с той же разницей, что в самом
 * опросе: ни планки, ни балла, ни попыток, а значит ни «сдали», ни «средний
 * балл». Вместо них — сколько прошли и чем опрос помечен.
 */
export interface LearningSurveyRow {
  id: number
  title: string
  /** Обязательный держит зачёт материала. */
  is_required: boolean
  /** Анонимный не покажет, кто что ответил. */
  is_anonymous: boolean
  closes_at: string | null
  /** У опроса четвёртый владелец, которого у проверок не бывает, — новость. */
  owner: 'lesson' | 'regulation' | 'regulation_version' | 'news'
  document_kind?: 'document' | 'handbook' | null
  material: string | null
  course_title: string | null
  course_slug: string | null
  lesson_id: number | null
  document_slug: string | null
  version_name: string | null
  news_slug: string | null
  questions: number
  /** Сколько человек прошли. Уволенные не в счёт. */
  answered: number
}

/** Кто прошёл опрос. Что ответил — не здесь: это сводка, см. SurveySummary. */
export interface LearningSurveyParticipant {
  id: number
  name: string
  answered_at: string | null
}


/* ---------- Аналитика штата ---------- */

/**
 * Движение персонала за период.
 *
 * `null` там, где считать не из чего: без единого уволенного средний срок
 * работы ушедших — не ноль, а «некого спросить», и ноль на экране соврал бы.
 */
export interface StaffSummary {
  headcount_start: number
  headcount_end: number
  hired: number
  left: number
  /** Среднее по дням периода — знаменатель текучести. */
  average_headcount: number
  turnover: number
  /** Ушедшие в первые 90 дней к принятым за тот же период. */
  early_turnover: number
  early_left: number
  average_tenure: number | null
  average_life: number | null
  /** Доля новичков, сдавших аттестацию. `null` — аттестаций не заведено. */
  onboarding: number | null
  /** Сколько карточек ещё без даты приёма: мера доверия ко всему отчёту. */
  without_hire_date: number
}

export interface StaffDepartmentRow {
  id: number
  name: string
  headcount: number
  hired: number
  left: number
  turnover: number
}

export interface StaffReasonRow {
  reason: string
  label: string
  count: number
  share: number
}

export interface StaffTenureRow {
  tag: string
  label: string
  range: string
  count: number
}

/** Человек в списке, в который проваливаются из цифры. */
export interface StaffPerson {
  id: number
  name: string
  job_title: string | null
  departments: string[]
  hired_at: string | null
  dismissed_at: string | null
  status: string
  status_label: string
  work_mode_label: string | null
  tenure_months: number | null
  dismissal_reason_label: string | null
  tenure_tag: string | null
  tenure_tag_label: string | null
  tags: string[]
}

export interface StaffPayload {
  summary: StaffSummary
  movement: { month: string, hired: number, left: number }[]
  departments: StaffDepartmentRow[]
  reasons: { total: number, unknown: number, rows: StaffReasonRow[] }
  tenure: StaffTenureRow[]
  filters: {
    period: { from: string, to: string }
    departments: { id: number, name: string }[]
    job_titles: string[]
    work_modes: { value: string, label: string }[]
    reasons: { value: string, label: string }[]
    tags: { id: number, name: string }[]
    /** Видит ли человек компанию целиком или только своё направление. */
    sees_everything: boolean
  }
}

/**
 * Ссылка на отчёт для того, у кого нет учётной записи.
 *
 * Сам токен приходит один раз — в ответе на создание. Дальше от него остаётся
 * только хвост: ссылку показывают однажды, как и положено секрету.
 */
export interface StaffLink {
  id: number
  hint: string
  created_at: string | null
  created_by: string | null
  expires_at: string
  revoked_at: string | null
  live: boolean
  period: { from: string | null, to: string | null }
  token?: string
}

/** Что именно выгружают файлом. */
export interface StaffExportRequest {
  format: 'xlsx' | 'csv'
  /** Со списком людей или только цифрами — от этого зависит запись в журнале. */
  names: boolean
  slice?: string
}

/** Отчёт, каким его видит открывший ссылку: цифры и ни одной фамилии. */
export interface SharedStaffReport {
  period: { from: string, to: string }
  expires_at: string
  summary: Omit<StaffSummary, 'without_hire_date'>
  movement: { month: string, hired: number, left: number }[]
  departments: StaffDepartmentRow[]
  reasons: { total: number, unknown: number, rows: StaffReasonRow[] }
  tenure: StaffTenureRow[]
}

/** Срез, в котором смотрят движение персонала. */
export interface StaffQuery {
  from?: string
  to?: string
  departments?: number[]
  job_title?: string
  work_mode?: string
  tags?: number[]
}

export interface Directory {
  channels: DirectoryValue[]
  warehouses: DirectoryValue[]
  managers: DirectoryValue[]
  segments: DirectoryValue[]
  periods: Option[]
  granularities: Granularity[]
  dimensions: Option[]
  product_dimensions: Option[]
  freshness: Freshness[]
}

export interface SalesTotals {
  revenue: number
  profit: number
  margin: number
  /** Доля выручки, для которой витрина не знает себестоимости. */
  without_cost_share: number
  returns: number
  return_orders: number
  return_rate: number
  orders: number
  average_order: number
  quantity: number
  lines: number
  items: number
  customers: number
}

export type SalesTrendPoint = {
  bucket: string
  revenue: number
  profit: number
  orders: number
  average_order: number
}

export interface ChannelRow {
  channel: string
  revenue: number
  profit: number
  margin: number
  orders: number
  average_order: number
}

export interface BreakdownRow {
  name: string
  revenue: number
  profit: number
  margin: number
  without_cost_share: number
  quantity: number
  orders: number
}

/** Одна доля столбца, сложенного из каналов. */
export interface ChannelTrendPoint {
  bucket: string
  channel: string
  revenue: number
}

/** Текущий период и предыдущий, наложенные по номеру дня, а не по дате. */
export type ComparisonPoint = {
  offset: number
  label: string
  current: number
  previous: number
}

export interface WeekdayRow {
  weekday: number
  label: string
  revenue: number
  orders: number
}

/** Выручка минус себестоимость — три величины, сходящиеся арифметически. */
export interface CostStructure {
  revenue: number
  cost: number
  profit: number
  margin: number
}

export interface SalesPayload {
  summary: { current: SalesTotals, previous: SalesTotals }
  trend: SalesTrendPoint[]
  channels: ChannelRow[]
  channel_trend: ChannelTrendPoint[]
  comparison: ComparisonPoint[]
  weekday: WeekdayRow[]
  cost_structure: CostStructure
}

export interface CustomerTotals {
  customers: number
  new_customers: number
  new_share: number
  revived_customers: number
  returning_customers: number
  revenue: number
  profit: number
  orders: number
  revenue_per_customer: number
  orders_per_customer: number
}

export interface SegmentRow {
  segment: string
  customers: number
  revenue: number
  profit: number
  orders: number
  revenue_per_customer: number
}

export interface OrderTypeRow {
  type: string
  orders: number
  customers: number
  revenue: number
}

export interface CohortRow {
  cohort: string
  customers: number
  revenue: number
  orders: number
  revenue_per_customer: number
}

export interface TopCustomerRow {
  name: string
  segment: string
  rfm: string
  revenue: number
  profit: number
  margin: number
  orders: number
  average_order: number
  /** Оборот клиента за всю жизнь, а не за выбранный период. */
  ltv: number
  lifetime_orders: number
  days_since_purchase: number
}

export interface CustomersPayload {
  summary: { current: CustomerTotals, previous: CustomerTotals }
  segments: SegmentRow[]
  order_types: OrderTypeRow[]
  cohorts: CohortRow[]
  top: TopCustomerRow[]
}

export interface EmployeeTotals {
  revenue: number
  profit: number
  margin: number
  /** Доля выручки, для которой витрина не знает себестоимости. */
  without_cost_share: number
  /**
   * Выручка, у которой в документе не оказалось ни продавца, ни ответственного.
   *
   * Показывается отдельной величиной, а не растворяется в итоге: «ничьё» и
   * «ноль» — разные вещи, и без неё расхождение с вкладкой продаж читалось бы
   * как ошибка в одной из них.
   */
  revenue_unattributed: number
  orders: number
  average_order: number
  /** Сколько имён торговало за период. */
  people: number
  customers: number
  revenue_per_person: number
  orders_per_person: number
}

export interface EmployeeRow {
  name: string
  revenue: number
  profit: number
  margin: number
  without_cost_share: number
  orders: number
  average_order: number
  customers: number
}

export interface EmployeesPayload {
  summary: { current: EmployeeTotals, previous: EmployeeTotals }
  ranking: EmployeeRow[]
}

/**
 * Продавец в отчёте план-факт.
 *
 * `plan_source` отвечает, откуда взялся план: `person` — личный, `member` —
 * норматив отдела, подставленный тому, кому личного не ставили. Без этого
 * нельзя отличить «ему поставили ровно столько» от «ему не ставили ничего».
 */
export interface DepartmentSeller {
  external_name: string
  sales_person_id: number | null
  department_id: number | null
  user_id: number | null
  /** Имя из базы знаний. Пусто у кассы: за ней смена, а не человек. */
  user_name: string | null
  revenue: number
  orders: number
  plan: number | null
  completion: number | null
  /** Сколько из плана должно быть сделано к сегодняшнему дню, если идти ровно. */
  pace: number | null
  pace_completion: number | null
  plan_source: 'person' | 'member' | null
}

export interface DepartmentSalesRow {
  id: number
  name: string
  /** Уровень в дереве структуры: отделы показываются с отступом. */
  depth: number
  parent_id: number | null
  /** Выручка со всеми, кто под этим отделом. */
  revenue: number
  /** Своя — только с продавцов самого отдела, без подчинённых. */
  own_revenue: number
  orders: number
  plan: number | null
  completion: number | null
  pace: number | null
  pace_completion: number | null
  /** Сумма планов продавцов отдела: план отдела не обязан с ней совпадать. */
  sellers_plan: number
  /** Норматив на любого продавца отдела. */
  member_plan: number | null
  sellers: DepartmentSeller[]
}

export interface DepartmentSalesPayload {
  period: {
    month: string
    from: string
    to: string
    days_in_month: number
    days_elapsed: number
    is_current: boolean
  }
  totals: {
    /** Выручка компании целиком, включая нераспределённую. */
    revenue: number
    /** Сумма планов верхних из запланированных отделов: без двойного счёта. */
    plan: number | null
    /** Выручка тех же отделов — только с ней план и сравнивают. */
    planned_revenue: number
    completion: number | null
    pace: number | null
    pace_completion: number | null
    unassigned_revenue: number
    unassigned_share: number
    sellers: number
  }
  departments: DepartmentSalesRow[]
  /** Продавцы, которых ещё не развели по отделам. */
  unassigned: DepartmentSeller[]
}

/* ---------- Битва отделов ---------- */

/** Отдел в гонке: место определяет темп месяца, а не сегодняшний процент. */
export interface RaceDepartment {
  id: number
  name: string
  /** Руководитель из структуры — фамилия и инициал. */
  head: string | null
  revenue_month: number
  orders_month: number
  plan_month: number
  completion_month: number | null
  pace_month: number
  pace_completion: number | null
  revenue_day: number
  plan_day: number
  completion_day: number | null
  orders_day: number
  lines_day: number
  /** К прошлому такому же дню недели. */
  change_day: number | null
  status: 'ahead' | 'on-track' | 'behind' | 'unknown'
}

export interface RaceConsultant {
  name: string
  external_name: string
  department: string | null
  revenue: number
  plan: number
  completion: number
  pace_completion: number | null
  plan_source: 'person' | 'member'
}

export interface RaceRecordHolder {
  name: string
  department: string | null
}

export interface RacePayload {
  period: {
    month: string
    days_in_month: number
    days_elapsed: number
    is_current: boolean
  }
  day: {
    /** Последний полный день выгрузки — не сегодня. */
    date: string | null
    previous_date: string | null
    /** По какое число вообще дошла выгрузка. */
    loaded_to: string | null
    share_of_month: number
  }
  store: {
    revenue_month: number
    plan_month: number | null
    completion_month: number | null
    pace_month: number | null
    pace_completion: number | null
    /** Если дальше пойдёт как шло. */
    forecast: number | null
    forecast_completion: number | null
    revenue_day: number
    plan_day: number | null
    completion_day: number | null
    orders_day: number
    average_order_day: number
    lines_per_order_day: number
    /** Торговля вне гонки: отделы без плана и неразобранные продавцы. */
    outside_month: number
    outside_day: number
    against_previous: {
      orders: number | null
      average_order: number | null
      lines_per_order: number | null
      revenue: number | null
    }
  }
  departments: RaceDepartment[]
  consultants: RaceConsultant[]
  records: {
    leader: (RaceRecordHolder & { share: number, revenue: number }) | null
    surge: { name: string, change: number } | null
    average_order: (RaceRecordHolder & { value: number, change: number | null }) | null
    lines_per_order: (RaceRecordHolder & { value: number, change: number | null }) | null
  }
}

export interface MatrixCell {
  abc: string
  xyz: string
  revenue: number
  profit: number
  items: number
}

export interface IlliquidRow {
  status: string
  revenue: number
  profit: number
  margin: number
  items: number
  quantity: number
}

export interface ProductBreakdownRow {
  name: string
  revenue: number
  profit: number
  margin: number
  quantity: number
  orders: number
  items: number
}

export interface ProductsPayload {
  matrix: MatrixCell[]
  illiquid: IlliquidRow[]
}

/**
 * Срез, в котором сервер понял вопрос.
 *
 * Отвечает не эхом на присланное, а тем, что получилось: пресет он разворачивает
 * в даты, а шаг графика при «авто» выбирает сам. Подписи под графиком берутся
 * отсюда, а не угадываются по числу точек, — иначе они однажды разойдутся с тем,
 * по чему сгруппирован запрос.
 */
export interface ReportMeta {
  period: PeriodMeta
  channels: string[]
  warehouses: string[]
  managers: string[]
  segments: string[]
  with_returns: boolean
}

export interface AnalyticsResponse<T> {
  data: T
  meta?: ReportMeta
}
/* ---------- ИИ-аналитик ---------- */

/**
 * Панель, собранная моделью и посчитанная сервером.
 *
 * Поля разные у разных видов, и это не небрежность типа: плитке нужны число и
 * прошлое, графику — точки и серии, рейтингу — список имён со значениями.
 * Общего знаменателя у них нет, а свести их в объединение значило бы проверять
 * вид дважды — здесь и в разметке.
 */
export interface ComposedPanel {
  type: 'kpi' | 'line' | 'bars' | 'columns' | 'donut'
  span?: number
  rows?: number

  /** Плитка. */
  label?: string
  value?: number
  previous?: number
  growth?: 'good' | 'bad' | 'neutral'
  hint?: string

  /** Всё остальное. */
  title?: string
  format?: 'money' | 'number' | 'percent' | 'decimal'

  /** График. */
  points?: Record<string, unknown>[]
  series?: { key: string, label: string, slot: 1 | 2, format?: 'money' | 'number' }[]
  granularity?: Granularity

  /** Рейтинг, столбцы, кольцо. */
  items?: { name: string, value: number, meta?: string, uncertain?: boolean }[]
}

export interface ComposedDashboard {
  title: string
  slice?: ReportMeta
  panels: ComposedPanel[]
}

/** Один круг разговора с ИИ-аналитиком. */
export interface AnalystTurn {
  id: number | null
  question: string
  answer: string
  /** Собрал ли он панель: половина вопросов отвечается словами. */
  composed?: boolean
  dashboard: ComposedDashboard | null
  asked_at?: string
}
