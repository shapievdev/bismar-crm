import type {
  AnalyticsFilters,
  AnalyticsResponse,
  BreakdownRow,
  CustomersPayload,
  Directory,
  ProductBreakdownRow,
  ProductDimension,
  LearningPayload,
  LearningQuizResult,
  ProductsPayload,
  SalesDimension,
  SalesPayload,
} from '~/types/analytics'

/**
 * Обращения к аналитике.
 *
 * Фильтры разворачиваются в query-параметры одинаково для всех вкладок: сервер
 * ждёт списки как `warehouses[]`, и собирать их в каждом вызове заново значит
 * однажды собрать по-разному.
 */
export function useAnalyticsApi() {
  const { $api } = useNuxtApp()

  /**
   * Скобки в имени обязательны.
   *
   * `ofetch` разворачивает массив в повторяющийся ключ без них —
   * `warehouses=Оптовый&warehouses=Розничный`, — а PHP из повторов оставляет
   * последний и кладёт в параметр строку. Правило `array` такое не принимает,
   * и весь запрос возвращался отказом: любой выбор в списочном фильтре гасил
   * панель вместо того, чтобы её отфильтровать.
   */
  function list(key: string, values: string[]): Record<string, string[]> {
    return values.length ? { [`${key}[]`]: values } : {}
  }

  function query(filters: AnalyticsFilters, limit?: number): Record<string, unknown> {
    return {
      // Явные даты сильнее пресета, и посылать оба сразу незачем.
      ...(filters.from && filters.to
        ? { from: filters.from, to: filters.to }
        : { preset: filters.preset ?? 'month' }),
      ...(filters.granularity ? { granularity: filters.granularity } : {}),
      ...list('channels', filters.channels),
      ...list('warehouses', filters.warehouses),
      ...list('managers', filters.managers),
      ...list('segments', filters.segments),
      // Значение по умолчанию на сервере — «с возвратами», поэтому посылается
      // только отказ от них.
      ...(filters.withReturns ? {} : { with_returns: 0 }),
      ...(limit ? { limit } : {}),
    }
  }

  return {
    fetchDirectory: (): Promise<AnalyticsResponse<Directory>> =>
      $api<AnalyticsResponse<Directory>>('/api/analytics/directory'),

    fetchSales: (filters: AnalyticsFilters): Promise<AnalyticsResponse<SalesPayload>> =>
      $api<AnalyticsResponse<SalesPayload>>('/api/analytics/sales', { query: query(filters) }),

    fetchSalesBreakdown: (
      filters: AnalyticsFilters,
      dimension: SalesDimension,
      limit = 15,
    ): Promise<AnalyticsResponse<BreakdownRow[]>> =>
      $api<AnalyticsResponse<BreakdownRow[]>>(`/api/analytics/sales/breakdown/${dimension}`, {
        query: query(filters, limit),
      }),

    fetchCustomers: (filters: AnalyticsFilters): Promise<AnalyticsResponse<CustomersPayload>> =>
      $api<AnalyticsResponse<CustomersPayload>>('/api/analytics/customers', { query: query(filters) }),

    /**
     * Обучение: сводка и рейтинги. Фильтров по периоду здесь нет — прохождение
     * копится с начала времён, и «курс за март» ничего не значит.
     */
    fetchLearning: (): Promise<AnalyticsResponse<LearningPayload>> =>
      $api<AnalyticsResponse<LearningPayload>>('/api/analytics/learning'),

    /** Кто и как прошёл один тест — раскрывается у одной строки отчёта. */
    fetchQuizResults: (quizId: number): Promise<AnalyticsResponse<{
      quiz: { id: number, title: string }
      people: LearningQuizResult[]
    }>> =>
      $api(`/api/analytics/learning/quizzes/${quizId}`),

    fetchProducts: (filters: AnalyticsFilters): Promise<AnalyticsResponse<ProductsPayload>> =>
      $api<AnalyticsResponse<ProductsPayload>>('/api/analytics/products', { query: query(filters) }),

    fetchProductBreakdown: (
      filters: AnalyticsFilters,
      dimension: ProductDimension,
      limit = 15,
    ): Promise<AnalyticsResponse<ProductBreakdownRow[]>> =>
      $api<AnalyticsResponse<ProductBreakdownRow[]>>(`/api/analytics/products/breakdown/${dimension}`, {
        query: query(filters, limit),
      }),
  }
}

/**
 * Срез, общий для всех вкладок раздела.
 *
 * Живёт в состоянии Nuxt, а не в каждой странице: выбрав квартал и склад на
 * продажах, человек ожидает увидеть тот же срез в клиентах, а не сбрасывать
 * его заново на каждой вкладке.
 */
export function useAnalyticsFilters() {
  return useState<AnalyticsFilters>('analytics-filters', () => ({
    preset: 'month',
    channels: [],
    warehouses: [],
    managers: [],
    segments: [],
    withReturns: true,
  }))
}

/**
 * Справочник фильтров и свежесть витрины.
 *
 * Запрашивается один раз на весь раздел: списки складов и менеджеров за время
 * сессии не меняются, и тянуть их на каждой вкладке значит лишние сканы
 * ClickHouse ради одного и того же ответа.
 */
export function useAnalyticsDirectory() {
  const { fetchDirectory } = useAnalyticsApi()

  return useAsyncData('analytics-directory', async () => (await fetchDirectory()).data)
}