import type { SurveySummary } from '~/types/survey'
import type {
  AnalyticsFilters,
  AnalyticsResponse,
  BreakdownRow,
  CustomersPayload,
  Directory,
  ProductBreakdownRow,
  ProductDimension,
  LearningPayload,
  LearningPerson,
  LearningQuizResult,
  LearningSurveyParticipant,
  ProductsPayload,
  SalesDimension,
  SalesPayload,
  SharedStaffReport,
  StaffExportRequest,
  StaffLink,
  StaffPayload,
  StaffPerson,
  StaffQuery,
} from '~/types/analytics'

/**
 * Обращения к аналитике.
 *
 * Фильтры разворачиваются в query-параметры одинаково для всех вкладок: сервер
 * ждёт списки как `warehouses[]`, и собирать их в каждом вызове заново значит
 * однажды собрать по-разному.
 */
/**
 * Срез штата в том виде, в каком его понимает адресная строка.
 *
 * Пустое не отправляется вовсе: `?job_title=` сервер прочёл бы как «должность
 * — пустая строка», а не «любая».
 */
function staffQuery(query: StaffQuery): Record<string, string | number[]> {
  return Object.fromEntries(
    Object.entries(query).filter(([, value]) => value !== undefined
      && value !== ''
      && !(Array.isArray(value) && value.length === 0)),
  ) as Record<string, string | number[]>
}

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

    /**
     * Люди за цифрой сводки обучения.
     *
     * Своим запросом, а не вместе со сводкой: раскрывают из семи срезов один, и
     * присылать все семь на открытии страницы значит присылать штат помноженный
     * на курсы ради одного нажатия, которого может и не случиться.
     */
    fetchLearningPeople: (slice: string): Promise<AnalyticsResponse<{
      slice: string
      /** Сколько их всего: список обрезан, и об этом сказано прямо. */
      total: number
      people: LearningPerson[]
    }>> =>
      $api('/api/analytics/learning/people', { query: { slice } }),

    /** Кто и как прошёл один тест — раскрывается у одной строки отчёта. */
    fetchQuizResults: (quizId: number): Promise<AnalyticsResponse<{
      quiz: { id: number, title: string }
      people: LearningQuizResult[]
    }>> =>
      $api(`/api/analytics/learning/quizzes/${quizId}`),

    /**
     * Результаты одного опроса: сводка ответов и кто его прошёл.
     *
     * Двумя частями, потому что «результаты» здесь про разное: сводка — что
     * ответили, список — кто отвечал. У анонимного опроса имён в сводке нет и
     * взяться им неоткуда, а список прошедших остаётся поимённым: «кто прошёл,
     * видно; что ответил — нет».
     */
    fetchSurveyResults: (surveyId: number): Promise<AnalyticsResponse<{
      survey: { id: number, title: string, is_required: boolean, is_anonymous: boolean }
      summary: SurveySummary
      people: LearningSurveyParticipant[]
    }>> =>
      $api(`/api/analytics/learning/surveys/${surveyId}`),

    /**
     * Движение персонала за срез.
     *
     * Срез уходит запросом целиком: сервер процеживает его правами — директор
     * направления, спросивший чужое подразделение, получит своё.
     */
    fetchStaff: (query: StaffQuery = {}): Promise<AnalyticsResponse<StaffPayload>> =>
      $api<AnalyticsResponse<StaffPayload>>('/api/analytics/staff', { query: staffQuery(query) }),

    /** Люди за цифрой — отдельным запросом: это персональные данные. */
    fetchStaffPeople: (
      query: StaffQuery,
      slice: string,
    ): Promise<AnalyticsResponse<{ slice: string, people: StaffPerson[] }>> =>
      $api('/api/analytics/staff/people', { query: { ...staffQuery(query), slice } }),

    /**
     * Выгрузка файлом.
     *
     * Через `$api`, а не переходом по адресу: переход уводит со страницы и
     * теряет срез, а заодно и признаётся браузеру как обычная навигация — с
     * отказом вместо файла, если сессия успела истечь. Здесь же отказ приходит
     * ошибкой, которую есть кому показать.
     */
    downloadStaffReport: (
      query: StaffQuery,
      request: StaffExportRequest,
    ): Promise<Blob> =>
      $api<Blob>('/api/analytics/staff/export', {
        responseType: 'blob',
        query: {
          ...staffQuery(query),
          format: request.format,
          names: request.names ? 1 : 0,
          ...(request.names && request.slice ? { slice: request.slice } : {}),
        },
      }),

    fetchStaffLinks: (): Promise<AnalyticsResponse<StaffLink[]>> =>
      $api<AnalyticsResponse<StaffLink[]>>('/api/analytics/staff/links'),

    /** Выдать ссылку. Срез замораживается таким, каким его видит выдающий. */
    createStaffLink: (query: StaffQuery, days: number): Promise<AnalyticsResponse<StaffLink>> =>
      $api<AnalyticsResponse<StaffLink>>('/api/analytics/staff/links', {
        method: 'POST',
        body: { ...staffQuery(query), days },
      }),

    revokeStaffLink: (id: number): Promise<AnalyticsResponse<StaffLink>> =>
      $api<AnalyticsResponse<StaffLink>>(`/api/analytics/staff/links/${id}`, { method: 'DELETE' }),

    /** Отчёт по токену — единственное обращение, которому не нужен вход. */
    fetchSharedStaffReport: (token: string): Promise<AnalyticsResponse<SharedStaffReport>> =>
      $api<AnalyticsResponse<SharedStaffReport>>(`/api/shared/staff-report/${token}`),

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