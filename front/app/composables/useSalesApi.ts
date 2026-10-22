import type { ResourceResponse } from '~/types/auth'

/** Отдел структуры в выпадающем списке — с путём, потому что имена повторяются. */
export interface SalesDepartmentOption {
  id: number
  name: string
  path: string
}

export interface SalesColleague {
  id: number
  name: string
  job_title: string | null
}

/** Подсказка: кого и какой отдел предлагает система за этим именем. */
export interface SellerSuggestion {
  user_id: number | null
  department_id: number | null
}

export interface SellerRow {
  /** Имя ровно как в выгрузке 1С — по нему сходятся факт и план. */
  external_name: string
  revenue: number
  orders: number
  department_id: number | null
  user_id: number | null
  suggestion: SellerSuggestion
}

export interface SellerDirectory {
  sellers: SellerRow[]
  departments: SalesDepartmentOption[]
  people: SalesColleague[]
  totals: {
    revenue: number
    unassigned_revenue: number
    unassigned_share: number
    sellers: number
  }
  lookback_days: number
}

export type SalesPlanKind = 'department' | 'member' | 'person'

export interface SalesPlan {
  id: number
  kind: SalesPlanKind
  department_id: number | null
  sales_person_id: number | null
  revenue: number
}

export interface SalesPlanPayload {
  period: string
  kind: SalesPlanKind
  department_id?: number | null
  sales_person_id?: number | null
  revenue: number
}

/**
 * Отделы продаж и планы.
 *
 * Отдельно от аналитики, потому что это не чтение цифр, а решение о них:
 * сопоставление задаёт, чья выручка чья, а план — мерку, по которой отдел
 * потом оценят. И право на это своё.
 */
export function useSalesApi() {
  const { $api } = useNuxtApp()

  return {
    fetchSellers: (): Promise<ResourceResponse<SellerDirectory>> =>
      $api<ResourceResponse<SellerDirectory>>('/api/sales/sellers'),

    /**
     * Завести или поправить связь. Оба поля пустые — связь снимается.
     */
    saveSeller: (body: {
      external_name: string
      department_id: number | null
      user_id: number | null
    }): Promise<ResourceResponse<unknown>> =>
      $api<ResourceResponse<unknown>>('/api/sales/sellers', { method: 'PUT', body }),

    /** Принять подсказки по всем, кого ещё не трогали. */
    acceptSuggestions: (): Promise<ResourceResponse<{ accepted: number }>> =>
      $api<ResourceResponse<{ accepted: number }>>('/api/sales/sellers/accept', { method: 'POST' }),

    fetchPlans: (period: string): Promise<ResourceResponse<SalesPlan[]>> =>
      $api<ResourceResponse<SalesPlan[]>>('/api/sales/plans', { query: { period } }),

    savePlan: (body: SalesPlanPayload): Promise<ResourceResponse<SalesPlan>> =>
      $api<ResourceResponse<SalesPlan>>('/api/sales/plans', { method: 'PUT', body }),

    /**
     * Расставить планы по умолчанию — по факту закрытых месяцев.
     *
     * Поставленное руками не переписывает: возвращает, сколько новых строк
     * завелось.
     */
    suggestPlans: (period: string): Promise<ResourceResponse<{
      departments: number
      members: number
      people: number
      months: number
    }>> =>
      $api('/api/sales/plans/suggest', { method: 'POST', query: { period } }),

    deletePlan: (id: number): Promise<ResourceResponse<null>> =>
      $api<ResourceResponse<null>>(`/api/sales/plans/${id}`, { method: 'DELETE' }),
  }
}
