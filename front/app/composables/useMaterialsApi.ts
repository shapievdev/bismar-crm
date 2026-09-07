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
  RegulationLink,
  MaterialSection,
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
 * Документы и справочники — две половины одного раздела базы знаний.
 *
 * Устроены они одинаково до последней мелочи, поэтому и адреса у них одни и те
 * же, с точностью до раздела: он и приходит сюда единственным доводом. Тип
 * вложения взят у урока — панель файлов у них общая.
 */
export function useMaterialsApi(section: MaterialSection) {
  const { $api, $upload } = useNuxtApp()
  const base = `/api/lms/${section}`

  return {
    fetchRegulations: (query: RegulationQuery = {}): Promise<PaginatedResponse<Regulation>> =>
      $api<PaginatedResponse<Regulation>>(base, { query }),

    fetchRegulation: (slug: string): Promise<ResourceResponse<Regulation>> =>
      $api<ResourceResponse<Regulation>>(`${base}/${slug}`),

    createRegulation: (payload: RegulationPayload): Promise<ResourceResponse<Regulation>> =>
      $api<ResourceResponse<Regulation>>(base, { method: 'POST', body: payload })
        .catch(toValidationError),

    updateRegulation: (slug: string, payload: RegulationPayload): Promise<ResourceResponse<Regulation>> =>
      $api<ResourceResponse<Regulation>>(`${base}/${slug}`, { method: 'PUT', body: payload })
        .catch(toValidationError),

    deleteRegulation: (slug: string) =>
      $api(`${base}/${slug}`, { method: 'DELETE' }),

    /** Прочитал — весь прогресс, какой у документа бывает. */
    acknowledge: (slug: string): Promise<{ data: { is_acknowledged: boolean, acknowledged_at: string | null } }> =>
      $api<{ data: { is_acknowledged: boolean, acknowledged_at: string | null } }>(
        `${base}/${slug}/acknowledge`,
        { method: 'POST' },
      ),

    fetchReaders: (slug: string): Promise<ResourceResponse<CoursePerson[]>> =>
      $api<ResourceResponse<CoursePerson[]>>(`${base}/${slug}/acknowledgements`),

    /* ---------- Категории: своё дерево ---------- */

    /**
     * Проверка при документе: заводят её целиком, как и тест урока, — сервер
     * заменяет прежнюю. Планку ставит правило, а не автор.
     */
    saveQuiz: (slug: string, payload: QuizPayload): Promise<ResourceResponse<Quiz>> =>
      $api<ResourceResponse<Quiz>>(`${base}/${slug}/quiz`, {
        method: 'PUT',
        body: payload,
      }).catch(toValidationError),

    deleteQuiz: (slug: string): Promise<void> =>
      $api(`${base}/${slug}/quiz`, { method: 'DELETE' }),

    /** Что проверка показывает тому, кто ведёт документ: какие вопросы заваливают. */
    fetchQuizStatistics: (slug: string): Promise<ResourceResponse<QuizStatistics>> =>
      $api<ResourceResponse<QuizStatistics>>(`${base}/${slug}/quiz/statistics`),

    /** Ведущему документ: разбор попытки сотрудника — что он отправил. */
    fetchQuizAttempt: (slug: string, attemptId: number): Promise<ResourceResponse<QuizAttempt>> =>
      $api<ResourceResponse<QuizAttempt>>(`${base}/${slug}/quiz/attempts/${attemptId}`),

    /** Пройти проверку. Сдал — документ считается прочитанным. */
    submitQuiz: (
      slug: string,
      // У письменного вопроса ответ — строка, у выбора — номера вариантов.
      answers: Record<number, number[] | string | string[][]>,
    ): Promise<ResourceResponse<QuizOutcome>> =>
      $api<ResourceResponse<QuizOutcome>>(`${base}/${slug}/quiz/submit`, {
        method: 'POST',
        body: { answers },
      }),

    fetchCategories: (): Promise<ResourceResponse<RegulationCategory[]>> =>
      $api<ResourceResponse<RegulationCategory[]>>(`${base}/categories`),

    createCategory: (payload: CategoryPayload): Promise<ResourceResponse<RegulationCategory>> =>
      $api<ResourceResponse<RegulationCategory>>(`${base}/categories`, {
        method: 'POST',
        body: payload,
      }).catch(toValidationError),

    /** По адресу, а не по номеру: категория связывается по slug, как учебная. */
    updateCategory: (slug: string, payload: CategoryPayload): Promise<ResourceResponse<RegulationCategory>> =>
      $api<ResourceResponse<RegulationCategory>>(`${base}/categories/${slug}`, {
        method: 'PUT',
        body: payload,
      }).catch(toValidationError),

    deleteCategory: (slug: string) =>
      $api(`${base}/categories/${slug}`, { method: 'DELETE' }),

    /* ---------- Люди ---------- */

    fetchMembers: (slug: string): Promise<ResourceResponse<CoursePerson[]>> =>
      $api<ResourceResponse<CoursePerson[]>>(`${base}/${slug}/access`),

    updateMembers: (slug: string, members: number[]): Promise<ResourceResponse<CoursePerson[]>> =>
      $api<ResourceResponse<CoursePerson[]>>(`${base}/${slug}/access`, {
        method: 'PUT',
        body: { members },
      }),

    searchMemberCandidates: (slug: string, search: string): Promise<ResourceResponse<CoursePerson[]>> =>
      $api<ResourceResponse<CoursePerson[]>>(`${base}/${slug}/access/candidates`, {
        query: { search },
      }),

    fetchExperts: (slug: string): Promise<ResourceResponse<CoursePerson[]>> =>
      $api<ResourceResponse<CoursePerson[]>>(`${base}/${slug}/experts`),

    updateExperts: (slug: string, members: number[]): Promise<ResourceResponse<CoursePerson[]>> =>
      $api<ResourceResponse<CoursePerson[]>>(`${base}/${slug}/experts`, {
        method: 'PUT',
        body: { members },
      }),

    searchExpertCandidates: (slug: string, search: string): Promise<ResourceResponse<CoursePerson[]>> =>
      $api<ResourceResponse<CoursePerson[]>>(`${base}/${slug}/experts/candidates`, {
        query: { search },
      }),

    /* ---------- Соседи: «рядом по теме» ---------- */

    /**
     * Связь взаимная: сохранённый здесь список меняет блок сразу на двух
     * страницах — и на этом документе, и на том, который в него вписали.
     */
    fetchRelated: (slug: string): Promise<ResourceResponse<RegulationLink[]>> =>
      $api<ResourceResponse<RegulationLink[]>>(`${base}/${slug}/related`),

    updateRelated: (slug: string, documents: number[]): Promise<ResourceResponse<RegulationLink[]>> =>
      $api<ResourceResponse<RegulationLink[]>>(`${base}/${slug}/related`, {
        method: 'PUT',
        body: { documents },
      }),

    searchRelatedCandidates: (slug: string, search: string): Promise<ResourceResponse<RegulationLink[]>> =>
      $api<ResourceResponse<RegulationLink[]>>(`${base}/${slug}/related/candidates`, {
        query: { search },
      }),

    /* ---------- «Частые вопросы» ---------- */

    /**
     * Список односторонний и в заданном порядке: он и есть ответ на вопрос «с
     * чем сюда приходят чаще». Подсказка ищет по обоим разделам — сотруднику
     * нужен ответ, а не раздел, в котором он лежит.
     */
    fetchQuestions: (slug: string): Promise<ResourceResponse<RegulationLink[]>> =>
      $api<ResourceResponse<RegulationLink[]>>(`${base}/${slug}/questions`),

    updateQuestions: (slug: string, documents: number[]): Promise<ResourceResponse<RegulationLink[]>> =>
      $api<ResourceResponse<RegulationLink[]>>(`${base}/${slug}/questions`, {
        method: 'PUT',
        body: { documents },
      }),

    searchQuestionCandidates: (slug: string, search: string): Promise<ResourceResponse<RegulationLink[]>> =>
      $api<ResourceResponse<RegulationLink[]>>(`${base}/${slug}/questions/candidates`, {
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
        `${base}/${slug}/attachments`,
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
      $api<ResourceResponse<LessonAttachment>>(`${base}/${slug}/attachments/drive`, {
        method: 'POST',
        body: { ...file, description },
      }),

    updateAttachment: (slug: string, attachmentId: number, description: string | null) =>
      $api<ResourceResponse<LessonAttachment>>(`${base}/${slug}/attachments/${attachmentId}`, {
        method: 'PUT',
        body: { description },
      }),

    deleteAttachment: (slug: string, attachmentId: number) =>
      $api(`${base}/${slug}/attachments/${attachmentId}`, { method: 'DELETE' }),
  }
}
