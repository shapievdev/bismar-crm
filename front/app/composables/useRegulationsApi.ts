import { toValidationError } from '~/composables/useAuth'
import type { DriveFile } from '~/composables/useGoogleDrive'
import type { ResourceResponse } from '~/types/auth'
import type {
  CategoryPayload,
  CoursePerson,
  LessonAttachment,
  PaginatedResponse,
  Quiz,
  QuizAttempt,
  QuizOutcome,
  QuizPayload,
  QuizStatistics,
  Regulation,
  RegulationCategory,
  RegulationPayload,
} from '~/types/lms'
import type { UploadOptions } from '~/utils/upload'

export interface RegulationQuery {
  search?: string
  category?: string
  status?: string
  page?: number
}

/**
 * Документы — правила, по которым работают.
 *
 * Живут в базе знаний рядом с материалами и потому под теми же правами. Тип
 * вложения взят у урока: устройство у них одно и то же, а панель файлов — общая.
 */
export function useRegulationsApi() {
  const { $api, $upload } = useNuxtApp()

  return {
    fetchRegulations: (query: RegulationQuery = {}): Promise<PaginatedResponse<Regulation>> =>
      $api<PaginatedResponse<Regulation>>('/api/lms/regulations', { query }),

    fetchRegulation: (slug: string): Promise<ResourceResponse<Regulation>> =>
      $api<ResourceResponse<Regulation>>(`/api/lms/regulations/${slug}`),

    createRegulation: (payload: RegulationPayload): Promise<ResourceResponse<Regulation>> =>
      $api<ResourceResponse<Regulation>>('/api/lms/regulations', { method: 'POST', body: payload })
        .catch(toValidationError),

    updateRegulation: (slug: string, payload: RegulationPayload): Promise<ResourceResponse<Regulation>> =>
      $api<ResourceResponse<Regulation>>(`/api/lms/regulations/${slug}`, { method: 'PUT', body: payload })
        .catch(toValidationError),

    deleteRegulation: (slug: string) =>
      $api(`/api/lms/regulations/${slug}`, { method: 'DELETE' }),

    /** Прочитал — весь прогресс, какой у документа бывает. */
    acknowledge: (slug: string): Promise<{ data: { is_acknowledged: boolean, acknowledged_at: string | null } }> =>
      $api<{ data: { is_acknowledged: boolean, acknowledged_at: string | null } }>(
        `/api/lms/regulations/${slug}/acknowledge`,
        { method: 'POST' },
      ),

    fetchReaders: (slug: string): Promise<ResourceResponse<CoursePerson[]>> =>
      $api<ResourceResponse<CoursePerson[]>>(`/api/lms/regulations/${slug}/acknowledgements`),

    /* ---------- Категории: своё дерево ---------- */

    /**
     * Проверка при документе: заводят её целиком, как и тест урока, — сервер
     * заменяет прежнюю. Планку ставит правило, а не автор.
     */
    saveQuiz: (slug: string, payload: QuizPayload): Promise<ResourceResponse<Quiz>> =>
      $api<ResourceResponse<Quiz>>(`/api/lms/regulations/${slug}/quiz`, {
        method: 'PUT',
        body: payload,
      }).catch(toValidationError),

    deleteQuiz: (slug: string): Promise<void> =>
      $api(`/api/lms/regulations/${slug}/quiz`, { method: 'DELETE' }),

    /** Что проверка показывает тому, кто ведёт документ: какие вопросы заваливают. */
    fetchQuizStatistics: (slug: string): Promise<ResourceResponse<QuizStatistics>> =>
      $api<ResourceResponse<QuizStatistics>>(`/api/lms/regulations/${slug}/quiz/statistics`),

    /** Ведущему документ: разбор попытки сотрудника — что он отправил. */
    fetchQuizAttempt: (slug: string, attemptId: number): Promise<ResourceResponse<QuizAttempt>> =>
      $api<ResourceResponse<QuizAttempt>>(`/api/lms/regulations/${slug}/quiz/attempts/${attemptId}`),

    /** Пройти проверку. Сдал — документ считается прочитанным. */
    submitQuiz: (
      slug: string,
      // У письменного вопроса ответ — строка, у выбора — номера вариантов.
      answers: Record<number, number[] | string | string[][]>,
    ): Promise<ResourceResponse<QuizOutcome>> =>
      $api<ResourceResponse<QuizOutcome>>(`/api/lms/regulations/${slug}/quiz/submit`, {
        method: 'POST',
        body: { answers },
      }),

    fetchCategories: (): Promise<ResourceResponse<RegulationCategory[]>> =>
      $api<ResourceResponse<RegulationCategory[]>>('/api/lms/regulations/categories'),

    createCategory: (payload: CategoryPayload): Promise<ResourceResponse<RegulationCategory>> =>
      $api<ResourceResponse<RegulationCategory>>('/api/lms/regulations/categories', {
        method: 'POST',
        body: payload,
      }).catch(toValidationError),

    /** По адресу, а не по номеру: категория связывается по slug, как учебная. */
    updateCategory: (slug: string, payload: CategoryPayload): Promise<ResourceResponse<RegulationCategory>> =>
      $api<ResourceResponse<RegulationCategory>>(`/api/lms/regulations/categories/${slug}`, {
        method: 'PUT',
        body: payload,
      }).catch(toValidationError),

    deleteCategory: (slug: string) =>
      $api(`/api/lms/regulations/categories/${slug}`, { method: 'DELETE' }),

    /* ---------- Люди ---------- */

    fetchMembers: (slug: string): Promise<ResourceResponse<CoursePerson[]>> =>
      $api<ResourceResponse<CoursePerson[]>>(`/api/lms/regulations/${slug}/access`),

    updateMembers: (slug: string, members: number[]): Promise<ResourceResponse<CoursePerson[]>> =>
      $api<ResourceResponse<CoursePerson[]>>(`/api/lms/regulations/${slug}/access`, {
        method: 'PUT',
        body: { members },
      }),

    searchMemberCandidates: (slug: string, search: string): Promise<ResourceResponse<CoursePerson[]>> =>
      $api<ResourceResponse<CoursePerson[]>>(`/api/lms/regulations/${slug}/access/candidates`, {
        query: { search },
      }),

    fetchExperts: (slug: string): Promise<ResourceResponse<CoursePerson[]>> =>
      $api<ResourceResponse<CoursePerson[]>>(`/api/lms/regulations/${slug}/experts`),

    updateExperts: (slug: string, members: number[]): Promise<ResourceResponse<CoursePerson[]>> =>
      $api<ResourceResponse<CoursePerson[]>>(`/api/lms/regulations/${slug}/experts`, {
        method: 'PUT',
        body: { members },
      }),

    searchExpertCandidates: (slug: string, search: string): Promise<ResourceResponse<CoursePerson[]>> =>
      $api<ResourceResponse<CoursePerson[]>>(`/api/lms/regulations/${slug}/experts/candidates`, {
        query: { search },
      }),

    /* ---------- Файлы ---------- */

    uploadAttachment: (
      slug: string,
      file: File,
      description: string | null = null,
      options: UploadOptions = {},
    ) => {
      const form = new FormData()
      form.append('file', file)

      if (description) {
        form.append('description', description)
      }

      return $upload<ResourceResponse<LessonAttachment>>(
        `/api/lms/regulations/${slug}/attachments`,
        form,
        options,
      )
    },

    /**
     * Приложить файл, оставшийся жить на Google Диске, — как и у урока: уходит
     * только его номер.
     */
    attachDriveFile: (
      slug: string,
      file: DriveFile,
      description: string | null = null,
    ): Promise<ResourceResponse<LessonAttachment>> =>
      $api<ResourceResponse<LessonAttachment>>(`/api/lms/regulations/${slug}/attachments/drive`, {
        method: 'POST',
        body: { ...file, description },
      }),

    updateAttachment: (slug: string, attachmentId: number, description: string | null) =>
      $api<ResourceResponse<LessonAttachment>>(`/api/lms/regulations/${slug}/attachments/${attachmentId}`, {
        method: 'PUT',
        body: { description },
      }),

    deleteAttachment: (slug: string, attachmentId: number) =>
      $api(`/api/lms/regulations/${slug}/attachments/${attachmentId}`, { method: 'DELETE' }),
  }
}
