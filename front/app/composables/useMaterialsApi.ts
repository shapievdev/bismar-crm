import { toValidationError } from '~/composables/useAuth'
import type { DriveFile } from '~/composables/useGoogleDrive'
import type { ResourceResponse } from '~/types/auth'
import type {
  CategoryPayload,
  CoursePerson,
  LessonAttachment,
  MaterialAccess,
  MaterialVersion,
  MaterialVersionPayload,
  MaterialVersionSummary,
  MaterialProgress,
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

    /* ---------- Версии: то же правило для своих людей ---------- */

    /**
     * Все версии — тому, кто документ ведёт: с названиями и кругом групп, но
     * без тел. Читателю отдельного запроса не нужно: переключатель приходит
     * вместе с документом.
     */
    fetchVersions: (slug: string): Promise<ResourceResponse<MaterialVersionSummary[]>> =>
      $api<ResourceResponse<MaterialVersionSummary[]>>(`${base}/${slug}/versions`),

    /** Одна версия целиком: статья, файлы и проверка. */
    fetchVersion: (slug: string, versionId: number): Promise<ResourceResponse<MaterialVersion>> =>
      $api<ResourceResponse<MaterialVersion>>(`${base}/${slug}/versions/${versionId}`),

    createVersion: (slug: string, payload: MaterialVersionPayload): Promise<ResourceResponse<MaterialVersionSummary>> =>
      $api<ResourceResponse<MaterialVersionSummary>>(`${base}/${slug}/versions`, {
        method: 'POST',
        body: payload,
      }).catch(toValidationError),

    updateVersion: (
      slug: string,
      versionId: number,
      payload: MaterialVersionPayload,
    ): Promise<ResourceResponse<MaterialVersionSummary>> =>
      $api<ResourceResponse<MaterialVersionSummary>>(`${base}/${slug}/versions/${versionId}`, {
        method: 'PUT',
        body: payload,
      }).catch(toValidationError),

    /**
     * Порядок версий: им же решается спор, когда человек попал в две сразу.
     * Присылается целиком — «пусть будет вот так».
     */
    reorderVersions: (slug: string, versions: number[]): Promise<ResourceResponse<MaterialVersionSummary[]>> =>
      $api<ResourceResponse<MaterialVersionSummary[]>>(`${base}/${slug}/versions/order`, {
        method: 'PUT',
        body: { versions },
      }),

    deleteVersion: (slug: string, versionId: number): Promise<void> =>
      $api(`${base}/${slug}/versions/${versionId}`, { method: 'DELETE' }),

    /** Проверка при версии — своя у каждой; сдача по-прежнему ознакомление. */
    submitVersionQuiz: (
      slug: string,
      versionId: number,
      answers: Record<number, number[] | string | string[][]>,
    ): Promise<{ data: QuizOutcome }> =>
      $api<{ data: QuizOutcome }>(`${base}/${slug}/versions/${versionId}/quiz/submit`, {
        method: 'POST',
        body: { answers },
      }),

    saveVersionQuiz: (slug: string, versionId: number, payload: QuizPayload): Promise<ResourceResponse<Quiz>> =>
      $api<ResourceResponse<Quiz>>(`${base}/${slug}/versions/${versionId}/quiz`, {
        method: 'PUT',
        body: payload,
      }).catch(toValidationError),

    deleteVersionQuiz: (slug: string, versionId: number): Promise<void> =>
      $api(`${base}/${slug}/versions/${versionId}/quiz`, { method: 'DELETE' }),

    /** Файл при версии — свой бланк расчёта у каждой. */
    uploadVersionAttachment: (
      slug: string,
      versionId: number,
      file: File,
      description: string | null,
      options: UploadOptions = {},
    ): Promise<ResourceResponse<LessonAttachment>> => {
      const body = new FormData()

      body.append('file', file)

      if (description) {
        body.append('description', description)
      }

      return $upload<ResourceResponse<LessonAttachment>>(
        `${base}/${slug}/versions/${versionId}/attachments`,
        body,
        options,
      )
    },

    attachVersionDriveFile: (
      slug: string,
      versionId: number,
      file: DriveFile,
    ): Promise<ResourceResponse<LessonAttachment>> =>
      $api<ResourceResponse<LessonAttachment>>(`${base}/${slug}/versions/${versionId}/attachments/drive`, {
        method: 'POST',
        body: { ...file, description: null },
      }),

    /* ---------- Как материал проходят: администратору ---------- */

    /** Кто ознакомился, кому назначено и чем кончилась проверка. */
    fetchProgress: (slug: string): Promise<ResourceResponse<MaterialProgress>> =>
      $api<ResourceResponse<MaterialProgress>>(`${base}/${slug}/progress`),

    /** Какие вопросы проверки заваливают. */
    fetchQuizStatistics: (slug: string): Promise<ResourceResponse<QuizStatistics>> =>
      $api<ResourceResponse<QuizStatistics>>(`${base}/${slug}/quiz/statistics`),

    /** Разбор попытки сотрудника — что он отправил. */
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

    /** Кого пустили в закрытый материал: люди и группы одним ответом. */
    fetchMembers: (slug: string): Promise<ResourceResponse<MaterialAccess>> =>
      $api<ResourceResponse<MaterialAccess>>(`${base}/${slug}/access`),

    updateMembers: (
      slug: string,
      members: number[],
      groups: number[],
    ): Promise<ResourceResponse<MaterialAccess>> =>
      $api<ResourceResponse<MaterialAccess>>(`${base}/${slug}/access`, {
        method: 'PUT',
        body: { members, groups },
      }),

    searchMemberCandidates: (slug: string, search: string): Promise<ResourceResponse<MaterialAccess>> =>
      $api<ResourceResponse<MaterialAccess>>(`${base}/${slug}/access/candidates`, {
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
