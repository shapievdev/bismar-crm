export interface ExitSurveyField {
  id: string
  label: string
  type: 'text' | 'date' | 'select' | 'textarea' | 'radio'
  required: boolean
  options?: string[]
  other?: boolean
}
export interface ExitSurveyDefinition {
  version: number
  title: string
  description: string
  fields: ExitSurveyField[]
}
export interface ExitSurveyRow {
  id: number
  user_id: number | null
  employee: { name: string, department: string, job_title: string | null, hired_at: string | null }
  status: 'pending' | 'submitted' | 'cancelled'
  dismissed_at: string
  assigned_at: string
  submitted_at: string | null
  cancelled_at: string | null
}
export interface ExitSurveyDetail extends ExitSurveyRow {
  definition: ExitSurveyDefinition
  answers: Record<string, string | null> | null
}
export interface ExitSurveyReport {
  data: ExitSurveyRow[]
  meta: { current_page: number, last_page: number, total: number }
  summary: Record<'pending' | 'submitted' | 'cancelled', number>
  departments: string[]
  breakdowns: { label: string, options: Record<string, number> }[]
}
