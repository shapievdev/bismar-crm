/**
 * Опросник при материале — то же устройство у урока, документа, справочника,
 * версии и новости.
 *
 * Стоит рядом с тестом и нарочно устроен иначе: у теста есть ключ, планка и
 * попытки, у опроса нет ни одного из трёх. Проходят его один раз (решение
 * пользователя 2026-09-21) — второго захода не бывает, и экран по `is_answered`
 * решает, показывать бланк или благодарность.
 */

export type SurveyQuestionType = 'single' | 'multiple' | 'scale' | 'text' | 'long_text'

export interface SurveyOption {
  id: number
  text: string
  position: number
}

export interface SurveyScale {
  /** Деления целиком — их считает сервер, чтобы бланк и сводка не разошлись. */
  steps: number[]
  min_label: string | null
  max_label: string | null
}

export interface SurveyQuestion {
  id: number
  text: string
  type: SurveyQuestionType
  /** Обязателен ли вопрос внутри опроса — не то же, что обязателен ли опрос. */
  is_required: boolean
  /** Дописывать ли к вариантам поле «свой вариант». Только у выбора. */
  allows_other: boolean
  position: number
  /** Шкала — только у вопроса-шкалы. */
  scale: SurveyScale | null
  options: SurveyOption[]
}

export interface Survey {
  id: number
  title: string
  description: string | null
  /** Обязательный держит зачёт материала: урок, ознакомление, подтверждение. */
  is_required: boolean
  /** Анонимный не записывает, кто что ответил. Обещание — показать до ответа. */
  is_anonymous: boolean
  closes_at: string | null
  /** Принимает ли ответы: срок мог выйти. */
  is_open: boolean
  /** Слово после отправки — авторское. */
  thanks: string | null
  /** Проходил ли этот человек. Второго раза не бывает. */
  is_answered: boolean
  questions?: SurveyQuestion[]
}

/** Что присылают, заводя и правя опрос. */
export interface SurveyPayload {
  title: string
  description: string | null
  is_required: boolean
  is_anonymous: boolean
  /** Срок приёма ответов. Null — открыт, пока стоит материал. */
  closes_at: string | null
  thanks: string | null
  questions: {
    /**
     * Номер уже существующего вопроса. Им вопрос остаётся собой при правке: по
     * номерам разложены снимки ответов, и пересозданный вопрос отвязывает от
     * себя всё, что люди сказали. У нового номера нет.
     */
    id?: number | null
    text: string
    type: SurveyQuestionType
    is_required: boolean
    allows_other: boolean
    scale_min?: number | null
    scale_max?: number | null
    scale_min_label?: string | null
    scale_max_label?: string | null
    /** Варианты — только у выбора. */
    options: { id?: number | null, text: string }[]
  }[]
}

/**
 * Ответ на один вопрос. Приходит и уходит одним предметом, а не полем на вид:
 * что именно заполнено, знает вид вопроса.
 */
export interface SurveyAnswer {
  options?: number[]
  other?: string | null
  scale?: number | null
  text?: string | null
}

/** Итог отправленного опроса. */
export interface SurveyOutcome {
  id: number
  submitted_at: string | null
  thanks: string | null
  /** Зачтён ли материал: обязательный опрос мог быть последним, чего он ждал. */
  is_credited: boolean
}

/* ---------- Сводка ответов ---------- */

export interface SurveyTextAnswer {
  text: string
  /** Кто сказал. Null у анонимного опроса — и у того, чью запись удалили. */
  person: string | null
}

export interface SurveyQuestionSummary {
  id: number
  text: string
  type: SurveyQuestionType
  is_required: boolean
  /** Сколько человек ответили именно на этот вопрос. */
  answered: number
  /** Выбор: сколько за какой вариант. */
  options?: { id: number, text: string, count: number, share: number }[]
  /** Свои варианты — их читают, как письменные ответы. */
  other?: SurveyTextAnswer[]
  /** Шкала: распределение по делениям и среднее. */
  scale?: {
    min_label: string | null
    max_label: string | null
    steps: { value: number, count: number }[]
    average: number | null
  }
  /** Письменные ответы — целиком. */
  texts?: SurveyTextAnswer[]
}

export interface SurveySummary {
  is_anonymous: boolean
  is_required: boolean
  closes_at: string | null
  is_open: boolean
  /** Сколько человек прошли опрос. Считается по отметкам, а не по ответам. */
  answered: number
  questions: SurveyQuestionSummary[]
}
