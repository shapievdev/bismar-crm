export interface DismissalMetrics {
  left: number
  interviews: number
  interview_coverage: number | null
  retainable: number | null
  retainable_share: number | null
  early_left: number
  early_share: number | null
  without_hire_date: number
  pending: number
  without_survey: number
}
export interface DismissalDepartment extends DismissalMetrics {
  id: number
  name: string
  average_headcount: number | null
  turnover: number | null
}
export interface StaffDismissalReport {
  period: { from: string, to: string }
  summary: DismissalMetrics
  departments: DismissalDepartment[]
  reasons: { label: string, count: number, share: number | null }[]
  interview_reasons: { label: string, count: number, share: number | null }[]
  conditions_assessment: string
  can_edit: boolean
  updated_at: string | null
}
