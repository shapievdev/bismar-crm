import type { Quiz } from '~/types/lms'

/**
 * Экзамены: проверка знаний сама по себе, назначенная людям.
 *
 * Вопросы при экзамене — тот же Quiz, что у урока: типы вопросов, попыток и
 * разбора берутся из базы знаний, а здесь — только своё: окно, таймер, срок.
 */

export type ExamStatus = 'draft' | 'published' | 'archived'

export interface Exam {
  id: number
  title: string
  slug: string
  description: string | null
  status: ExamStatus
  status_label: string
  opens_at: string | null
  closes_at: string | null
  /** Минуты на попытку. Null — без таймера. */
  time_limit_minutes: number | null
  shuffle_questions: boolean
  /** Можно ли сесть прямо сейчас — и почему нельзя, словами сервера. */
  is_open: boolean
  closed_reason: string | null
  quiz?: Quiz | null
  author?: { id: number, name: string } | null
  assignments_count?: number
}

/** Как у человека идут дела с экзаменом — считает сервер, см. ExamResults. */
export interface ExamSummary {
  attempts_spent: number
  best_score: number | null
  passed: boolean
  /** Работа у проверяющего: сдавшему — «ждите», автору — «не торопите». */
  awaiting_review: boolean
  last_attempt_at: string | null
  /** Срок прошёл, а зачтённой попытки нет. */
  overdue: boolean
}

/** Назначенный экзамен глазами сдающего. */
export interface AssignedExam {
  exam: Exam
  due_at: string | null
  assigned_at: string | null
  /** Идущая попытка — по ней экран продолжает таймер после перезагрузки. */
  window: ExamWindow | null
  summary?: ExamSummary
}

export interface ExamWindow {
  started_at: string | null
  /** Null у экзамена без таймера. */
  expires_at: string | null
}

/** Строка назначений в редакторе: кто и к какому сроку. */
export interface ExamAssignmentRow {
  user: { id: number, name: string } | null
  due_at: string | null
  assigned_at: string | null
}

/** Строка результатов: назначенный и его сводка. */
export interface ExamResultRow extends ExamSummary {
  user: { id: number, name: string } | null
  due_at: string | null
}

export interface ExamPayload {
  title: string
  description?: string | null
  opens_at?: string | null
  closes_at?: string | null
  time_limit_minutes?: number | null
  shuffle_questions?: boolean
}

export interface AssignExamPayload {
  users?: number[]
  departments?: number[]
  groups?: number[]
  due_at?: string | null
}
