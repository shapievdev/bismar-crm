import { toValidationError } from '~/composables/useAuth'
import type { ResourceResponse } from '~/types/auth'
import type { Quiz, QuizAttempt, QuizPayload } from '~/types/lms'
import type {
  AssignExamPayload,
  AssignedExam,
  Exam,
  ExamAssignmentRow,
  ExamPayload,
  ExamResultRow,
  ExamStatus,
  ExamWindow,
} from '~/types/exams'

/**
 * Экзамены.
 *
 * Сдающий ходит по `/api/exams` — доступ там даёт назначение, а не право.
 * Ведение живёт на `/api/exams/manage` под правом `exams.manage`; вопросы
 * сохраняются той же нагрузкой QuizPayload, что у теста урока: редактор один.
 */
export function useExamsApi() {
  const { $api } = useNuxtApp()

  return {
    /* ---------- Сдача ---------- */

    /** Мои экзамены: несданное и срочное сервер кладёт сверху. */
    fetchMine: (): Promise<{ data: AssignedExam[] }> =>
      $api<{ data: AssignedExam[] }>('/api/exams'),

    /** Для значка на рельсе: сколько ждут сдачи и назначали ли вообще. */
    fetchPendingCount: (): Promise<{ data: { pending: number, has_exams: boolean } }> =>
      $api<{ data: { pending: number, has_exams: boolean } }>('/api/exams/pending-count'),

    /** Шапка без вопросов: они приезжают только с «Начать». */
    fetchExam: (slug: string): Promise<{ data: AssignedExam }> =>
      $api<{ data: AssignedExam }>(`/api/exams/${slug}`),

    /** Начать попытку: с этой минуты тикает таймер. */
    startExam: (slug: string): Promise<{ window: ExamWindow, quiz: Quiz }> =>
      $api<{ window: ExamWindow, quiz: Quiz }>(`/api/exams/${slug}/start`, { method: 'POST' }),

    submitExam: (
      slug: string,
      answers: Record<number, number[] | string | string[][]>,
    ): Promise<ResourceResponse<QuizAttempt>> =>
      $api<ResourceResponse<QuizAttempt>>(`/api/exams/${slug}/submit`, {
        method: 'POST',
        body: { answers },
      }),

    fetchAttempt: (slug: string, attemptId: number): Promise<ResourceResponse<QuizAttempt>> =>
      $api<ResourceResponse<QuizAttempt>>(`/api/exams/${slug}/attempts/${attemptId}`),

    /* ---------- Ведение ---------- */

    fetchAll: (): Promise<{ data: Exam[] }> =>
      $api<{ data: Exam[] }>('/api/exams/manage'),

    createExam: (payload: ExamPayload): Promise<ResourceResponse<Exam>> =>
      $api<ResourceResponse<Exam>>('/api/exams/manage', { method: 'POST', body: payload })
        .catch(toValidationError),

    /** Полный экзамен с вопросами и ключом — ведущему. */
    fetchManaged: (slug: string): Promise<ResourceResponse<Exam>> =>
      $api<ResourceResponse<Exam>>(`/api/exams/manage/${slug}`),

    updateExam: (slug: string, payload: ExamPayload): Promise<ResourceResponse<Exam>> =>
      $api<ResourceResponse<Exam>>(`/api/exams/manage/${slug}`, { method: 'PUT', body: payload })
        .catch(toValidationError),

    deleteExam: (slug: string) =>
      $api(`/api/exams/manage/${slug}`, { method: 'DELETE' }),

    /** Публикация и архив — отдельным адресом: у них свои проверки. */
    changeStatus: (slug: string, status: ExamStatus): Promise<ResourceResponse<Exam>> =>
      $api<ResourceResponse<Exam>>(`/api/exams/manage/${slug}/status`, {
        method: 'POST',
        body: { status },
      }).catch(toValidationError),

    saveQuiz: (slug: string, payload: QuizPayload): Promise<ResourceResponse<Quiz>> =>
      $api<ResourceResponse<Quiz>>(`/api/exams/manage/${slug}/quiz`, { method: 'PUT', body: payload })
        .catch(toValidationError),

    deleteQuiz: (slug: string) =>
      $api(`/api/exams/manage/${slug}/quiz`, { method: 'DELETE' }),

    /** Кого можно назначить — поиском по работающим. */
    searchPeople: (search: string): Promise<{ data: { id: number, name: string }[] }> =>
      $api<{ data: { id: number, name: string }[] }>('/api/exams/manage/people', { query: { search } }),

    fetchAssignments: (slug: string): Promise<{ data: ExamAssignmentRow[] }> =>
      $api<{ data: ExamAssignmentRow[] }>(`/api/exams/manage/${slug}/assignments`),

    /** Присланное — полный список: убранный освобождается от обязанности. */
    assign: (slug: string, payload: AssignExamPayload): Promise<{ data: ExamAssignmentRow[] }> =>
      $api<{ data: ExamAssignmentRow[] }>(`/api/exams/manage/${slug}/assignments`, {
        method: 'PUT',
        body: payload,
      }).catch(toValidationError),

    fetchResults: (slug: string): Promise<{ data: ExamResultRow[] }> =>
      $api<{ data: ExamResultRow[] }>(`/api/exams/manage/${slug}/results`),

    /** Чужая попытка — ведущему: разбор с открытым ключом. */
    fetchManagedAttempt: (slug: string, attemptId: number): Promise<ResourceResponse<QuizAttempt>> =>
      $api<ResourceResponse<QuizAttempt>>(`/api/exams/manage/${slug}/attempts/${attemptId}`),
  }
}
