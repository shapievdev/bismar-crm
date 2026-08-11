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