/** Как ключ предъявляется эндпоинту. */
export type AiAuthScheme = 'bearer' | 'header'

/** Чем закончился вопрос: по этому и разбирают журнал. */
export type ConsultantOutcome =
  | 'answered'
  /** Готовый ответ автора, отданный дословно: модель не вызывалась. */
  | 'verbatim'
  | 'nothing-found'
  /** Прямого ответа не нашлось — сотруднику показали материал по соседству. */
  | 'suggested'
  | 'unused'
  | 'failed'

export interface ConsultantQuestion {
  id: number
  question: string
  /**
   * Чем искали, если вопрос был продолжением разговора: «а сколько это сохнет?»
   * уходит в поиск достроенным. Null — вопрос понятен сам по себе.
   */
  searched_as: string | null
  answer: string | null
  outcome: ConsultantOutcome
  outcome_label: string
  hint: string
  /** Сколько фрагментов нашёл поиск и на сколько сослался ответ. */
  /** Что сказал об ответе сам спрашивавший и просил ли дописать его. */
  feedback: 'helpful' | 'unhelpful' | null
  feedback_label: string | null
  requested_at: string | null
  request_note: string | null

  /** Дописанный ответ и урок, в который он занесён. */
  resolution: string | null
  resolved_at: string | null
  resolution_lesson?: { id: number, title: string } | null
  resolved_by?: string | null

  retrieved: number
  cited: number
  model: string | null
  duration_ms: number | null
  asked_by?: string | null
  asked_at: string | null
}

export interface QuestionLogQuery {
  outcome?: ConsultantOutcome
  unanswered?: number
  /** Только заявки, которые ещё никто не закрыл. */
  requested?: number
  search?: string
  page?: number
}

export interface QuestionLogResponse {
  data: ConsultantQuestion[]
  meta: {
    current_page: number
    last_page: number
    total: number
    outcomes: { value: ConsultantOutcome, label: string }[]
    summary: Record<string, number>
  }
}

export interface AiSettings {
  model: string | null
  /** Модель смыслового поиска; пусто — поиск только по словам. */
  embedding_model: string | null
  base_url: string | null
  auth_scheme: AiAuthScheme
  max_tokens: number | null

  /** Последние знаки сохранённого ключа; сам ключ наружу не отдаётся. */
  key_hint: string | null
  has_key: boolean

  /** Что применится с учётом переменных окружения. */
  effective: {
    model: string
    embedding_model: string | null
    base_url: string
    max_tokens: number
    auth_scheme: AiAuthScheme
  }

  schemes: { value: AiAuthScheme, label: string }[]
  updated_at: string | null
  updated_by?: string | null
}

export interface AiSettingsPayload {
  model: string | null
  embedding_model: string | null
  base_url: string | null
  /** null означает «оставить сохранённый ключ». */
  api_key: string | null
  auth_scheme: AiAuthScheme
  max_tokens: number | null
}

/** Ответ автора на заданный вопрос — строкой в таблицу урока. */
export interface ResolveQuestionPayload {
  lesson_id: number
  question: string
  answer: string
}
