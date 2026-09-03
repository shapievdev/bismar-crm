import { toValidationError } from '~/composables/useAuth'
import type { DriveFile } from '~/composables/useGoogleDrive'
import type { ResourceResponse } from '~/types/auth'
import type {
  AnswerSourceKind,
  Attestation,
  Category,
  CategoryPayload,
  ConsultantAnswer,
  ConsultantExchange,
  Course,
  CoursePayload,
  CoursePerson,
  Enrollment,
  LearningPlanItem,
  PlannableItem,
  PlannableKind,
  LessonAnswer,
  LessonAnswerPayload,
  LessonAttachment,
  LessonTranscript,
  LessonPayload,
  LessonSummary,
  ModulePayload,
  PaginatedResponse,
  Quiz,
  QuizAttempt,
  QuizPayload,
  QuizStatistics,
  StatusOption,
  SuggestedAnswer,
} from '~/types/lms'

export interface CourseQuery {
  search?: string
  category?: string
  status?: string
  page?: number
}

export interface LessonProgressResult {
  data: { progress: number, is_completed: boolean }
}

/**
 * Typed access to the LMS endpoints.
 */
export function useLmsApi() {
  const { $api, $upload } = useNuxtApp()

  return {
    fetchCourses: (query: CourseQuery = {}): Promise<PaginatedResponse<Course>> =>
      $api<PaginatedResponse<Course>>('/api/lms/courses', { query }),

    fetchCourse: (slug: string): Promise<ResourceResponse<Course>> =>
      $api<ResourceResponse<Course>>(`/api/lms/courses/${slug}`),

    createCourse: (payload: CoursePayload): Promise<ResourceResponse<Course>> =>
      $api<ResourceResponse<Course>>('/api/lms/courses', { method: 'POST', body: payload }),

    updateCourse: (slug: string, payload: CoursePayload): Promise<ResourceResponse<Course>> =>
      $api<ResourceResponse<Course>>(`/api/lms/courses/${slug}`, { method: 'PUT', body: payload }),

    /**
     * Убирает материал из базы знаний.
     *
     * На сервере это мягкое удаление: прогресс учеников по курсу стоит того,
     * чтобы его можно было вернуть.
     */
    deleteCourse: (slug: string) =>
      $api(`/api/lms/courses/${slug}`, { method: 'DELETE' }),

    /** Кто допущен к приватному курсу, помимо автора. */
    fetchCourseAccess: (slug: string): Promise<ResourceResponse<CoursePerson[]>> =>
      $api<ResourceResponse<CoursePerson[]>>(`/api/lms/courses/${slug}/access`),

    /**
     * Задаёт список целиком: экран показывает его весь, и «сохранить» здесь
     * означает «пусть будет вот так».
     */
    updateCourseAccess: (slug: string, members: number[]): Promise<ResourceResponse<CoursePerson[]>> =>
      $api<ResourceResponse<CoursePerson[]>>(`/api/lms/courses/${slug}/access`, {
        method: 'PUT',
        body: { members },
      }),

    /** Кого ещё можно добавить — поиском: сотрудников тысячи, нужен один. */
    searchAccessCandidates: (slug: string, search: string): Promise<ResourceResponse<CoursePerson[]>> =>
      $api<ResourceResponse<CoursePerson[]>>(`/api/lms/courses/${slug}/access/candidates`, {
        query: { search },
      }),

    fetchStatuses: (): Promise<ResourceResponse<StatusOption[]>> =>
      $api<ResourceResponse<StatusOption[]>>('/api/lms/statuses'),

    enroll: (slug: string): Promise<ResourceResponse<Enrollment>> =>
      $api<ResourceResponse<Enrollment>>(`/api/lms/courses/${slug}/enroll`, { method: 'POST' }),

    myCourses: (): Promise<ResourceResponse<Enrollment[]>> =>
      $api<ResourceResponse<Enrollment[]>>('/api/lms/my-courses'),

    /** Свой план обучения: что назначили пройти и в каком порядке. */
    myPlan: (): Promise<ResourceResponse<LearningPlanItem[]>> =>
      $api<ResourceResponse<LearningPlanItem[]>>('/api/lms/my-plan'),

    /** План сотрудника — глазами того, кто его составляет. */
    fetchPlan: (userId: number): Promise<ResourceResponse<LearningPlanItem[]>> =>
      $api<ResourceResponse<LearningPlanItem[]>>(`/api/lms/plans/${userId}`),

    /**
     * Что можно поставить шагом этому сотруднику — весь список сразу.
     *
     * Целиком, а не поиском: курсов и документов десятки, и дальше экран
     * сужает список сам — по виду и по категории, — не спрашивая сервер на
     * каждое движение.
     */
    fetchPlanMaterial: (userId: number): Promise<ResourceResponse<PlannableItem[]>> =>
      $api<ResourceResponse<PlannableItem[]>>(`/api/lms/plans/${userId}/material`),

    /**
     * Задаёт план целиком, и порядок присланного и есть порядок шагов —
     * так же, как список допущенных к курсу.
     *
     * Шаг приходит парой «вид и номер»: номер сам по себе ничего не значит,
     * курс №3 и документ №3 — разные вещи.
     */
    savePlan: (
      userId: number,
      items: { type: PlannableKind, id: number }[],
    ): Promise<ResourceResponse<LearningPlanItem[]>> =>
      $api<ResourceResponse<LearningPlanItem[]>>(`/api/lms/plans/${userId}`, {
        method: 'PUT',
        body: { items },
      }).catch(toValidationError),

    fetchLesson: (id: number | string): Promise<ResourceResponse<LessonSummary>> =>
      $api<ResourceResponse<LessonSummary>>(`/api/lms/lessons/${id}`),

    completeLesson: (id: number | string): Promise<LessonProgressResult> =>
      $api<LessonProgressResult>(`/api/lms/lessons/${id}/complete`, { method: 'POST' }),

    submitQuiz: (
      lessonId: number | string,
      // У письменного вопроса ответ — строка, у выбора — номера вариантов.
      answers: Record<number, number[] | string | string[][]>,
    ): Promise<ResourceResponse<QuizAttempt>> =>
      $api<ResourceResponse<QuizAttempt>>(`/api/lms/lessons/${lessonId}/quiz/submit`, {
        method: 'POST',
        body: { answers },
      }),

    /** Разбор своей прошлой попытки: что выбрал и где ошибся. */
    fetchAttempt: (id: number): Promise<ResourceResponse<QuizAttempt>> =>
      $api<ResourceResponse<QuizAttempt>>(`/api/lms/quiz-attempts/${id}`),

    /* ---------- Аттестация: работы, которые читает человек ---------- */

    /** Очередь проверяющего: сперва ждущие ответа, потом разобранные. */
    fetchAttestations: (): Promise<ResourceResponse<Attestation[]>> =>
      $api<ResourceResponse<Attestation[]>>('/api/lms/attestations'),

    /** Одна работа целиком — с ответами и открытым ключом. */
    fetchAttestation: (attemptId: number): Promise<ResourceResponse<Attestation>> =>
      $api<ResourceResponse<Attestation>>(`/api/lms/attestations/${attemptId}`),

    /** Зачесть или не зачесть. Отказ без объяснения сервер не примет. */
    judgeAttestation: (
      attemptId: number,
      body: { is_accepted: boolean, comment: string | null },
    ): Promise<ResourceResponse<Attestation>> =>
      $api<ResourceResponse<Attestation>>(`/api/lms/attestations/${attemptId}/verdict`, {
        method: 'POST',
        body,
      }).catch(toValidationError),

    /** Сколько работ ждёт ответа — для значка в навигации. */
    fetchPendingAttestations: (): Promise<{ data: { pending: number } }> =>
      $api<{ data: { pending: number } }>('/api/lms/attestations/pending-count'),

    /** Кого можно назначить проверяющим. */
    searchExaminers: (search = ''): Promise<ResourceResponse<CoursePerson[]>> =>
      $api<ResourceResponse<CoursePerson[]>>('/api/lms/attestations/candidates', { query: { search } }),

    /** Автору: какой вопрос заваливают и что выбирают вместо верного. */
    fetchQuizStatistics: (lessonId: number | string): Promise<ResourceResponse<QuizStatistics>> =>
      $api<ResourceResponse<QuizStatistics>>(`/api/lms/lessons/${lessonId}/quiz/statistics`),

    /**
     * Автору: разбор попытки сотрудника. Адрес при уроке, а не при попытке, —
     * право смотреть чужие ответы даёт материал.
     */
    fetchLessonAttempt: (
      lessonId: number | string,
      attemptId: number,
    ): Promise<ResourceResponse<QuizAttempt>> =>
      $api<ResourceResponse<QuizAttempt>>(`/api/lms/lessons/${lessonId}/quiz/attempts/${attemptId}`),

    addModule: (slug: string, body: ModulePayload) =>
      $api(`/api/lms/courses/${slug}/modules`, { method: 'POST', body }),

    updateModule: (moduleId: number, body: ModulePayload) =>
      $api(`/api/lms/modules/${moduleId}`, { method: 'PUT', body }),

    deleteModule: (moduleId: number) =>
      $api(`/api/lms/modules/${moduleId}`, { method: 'DELETE' }),

    addLesson: (moduleId: number, body: LessonPayload) =>
      $api(`/api/lms/modules/${moduleId}/lessons`, { method: 'POST', body }),

    updateLesson: (lessonId: number | string, body: LessonPayload) =>
      $api(`/api/lms/lessons/${lessonId}`, { method: 'PUT', body }),

    deleteLesson: (lessonId: number | string) =>
      $api(`/api/lms/lessons/${lessonId}`, { method: 'DELETE' }),

    uploadAttachment: (
      lessonId: number | string,
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
        `/api/lms/lessons/${lessonId}/attachments`,
        form,
        options,
      )
    },

    /**
     * Приложить файл, оставшийся жить на Google Диске: уходит только его номер,
     * адрес просмотра сервер собирает сам.
     */
    attachDriveFile: (
      lessonId: number | string,
      file: DriveFile,
      description: string | null = null,
    ): Promise<ResourceResponse<LessonAttachment>> =>
      $api<ResourceResponse<LessonAttachment>>(`/api/lms/lessons/${lessonId}/attachments/drive`, {
        method: 'POST',
        body: { ...file, description },
      }),

    updateAttachment: (attachmentId: number, description: string | null) =>
      $api<ResourceResponse<LessonAttachment>>(`/api/lms/attachments/${attachmentId}`, {
        method: 'PUT',
        body: { description },
      }),

    deleteAttachment: (attachmentId: number) =>
      $api(`/api/lms/attachments/${attachmentId}`, { method: 'DELETE' }),

    saveQuiz: (lessonId: number | string, body: QuizPayload): Promise<ResourceResponse<Quiz>> =>
      $api<ResourceResponse<Quiz>>(`/api/lms/lessons/${lessonId}/quiz`, { method: 'PUT', body }),

    deleteQuiz: (lessonId: number | string) =>
      $api(`/api/lms/lessons/${lessonId}/quiz`, { method: 'DELETE' }),

    /** Таблица урока целиком, взамен той, что была. */
    saveAnswers: (
      lessonId: number | string,
      answers: LessonAnswerPayload[],
    ): Promise<ResourceResponse<LessonAnswer[]>> =>
      $api<ResourceResponse<LessonAnswer[]>>(`/api/lms/lessons/${lessonId}/answers`, {
        method: 'PUT',
        body: { answers },
      }),

    /**
     * Черновик от модели. Ничего не сохраняет — автор отбирает нужное сам.
     *
     * `transcriptId` сужает разбор до одной расшифровки: разбирать урок целиком
     * приходится редко, расшифровки приезжают по одной.
     */
    suggestAnswers: (
      lessonId: number | string,
      transcriptId?: number,
    ): Promise<ResourceResponse<SuggestedAnswer[]>> =>
      $api<ResourceResponse<SuggestedAnswer[]>>(`/api/lms/lessons/${lessonId}/answers/suggest`, {
        method: 'POST',
        query: transcriptId ? { transcript: transcriptId } : undefined,
      }),

    fetchTranscripts: (lessonId: number | string): Promise<ResourceResponse<LessonTranscript[]>> =>
      $api<ResourceResponse<LessonTranscript[]>>(`/api/lms/lessons/${lessonId}/transcripts`),

    /**
     * Загружает расшифровку — файлом или вставленным текстом.
     *
     * Через FormData в обоих случаях: файл иначе не отправить, а два пути к
     * одному эндпоинту разошлись бы при первой же правке.
     */
    saveTranscript: (
      lessonId: number | string,
      payload: {
        source_kind: AnswerSourceKind
        source_attachment_id?: number | null
        source_block_id?: string | null
        file?: File | null
        text?: string | null
      },
      options: UploadOptions = {},
    ): Promise<ResourceResponse<LessonTranscript>> => {
      const form = new FormData()
      form.append('source_kind', payload.source_kind)

      if (payload.source_attachment_id != null) {
        form.append('source_attachment_id', String(payload.source_attachment_id))
      }

      if (payload.source_block_id) {
        form.append('source_block_id', payload.source_block_id)
      }

      if (payload.file) {
        form.append('file', payload.file)
      }
      else if (payload.text) {
        form.append('text', payload.text)
      }

      return $upload<ResourceResponse<LessonTranscript>>(
        `/api/lms/lessons/${lessonId}/transcripts`,
        form,
        options,
      )
    },

    deleteTranscript: (transcriptId: number) =>
      $api(`/api/lms/transcripts/${transcriptId}`, { method: 'DELETE' }),

    /** Своя переписка с консультантом, старые вопросы первыми. */
    fetchConsultantHistory: (): Promise<ResourceResponse<ConsultantExchange[]>> =>
      $api<ResourceResponse<ConsultantExchange[]>>('/api/lms/ask/history'),

    /**
     * Убирает переписку с глаз.
     *
     * На сервере это отметка, а не удаление: тот же вопрос остаётся в журнале,
     * по которому авторы курсов видят, чего в базе знаний не хватает.
     */
    forgetConsultantHistory: () =>
      $api('/api/lms/ask/history', { method: 'DELETE' }),

    uploadCover: (
      slug: string,
      file: File,
      options: UploadOptions = {},
    ): Promise<ResourceResponse<Course>> => {
      const form = new FormData()
      form.append('cover', file)

      return $upload<ResourceResponse<Course>>(`/api/lms/courses/${slug}/cover`, form, options)
    },

    deleteCover: (slug: string) =>
      $api(`/api/lms/courses/${slug}/cover`, { method: 'DELETE' }),

    /**
     * Кто отвечает за курс. Право то же, что и на правку курса: назначить
     * ответственного — сказать, к кому идти, а не открыть кому-то закрытое.
     */
    fetchCourseExperts: (slug: string): Promise<ResourceResponse<CoursePerson[]>> =>
      $api<ResourceResponse<CoursePerson[]>>(`/api/lms/courses/${slug}/experts`),

    updateCourseExperts: (slug: string, experts: number[]): Promise<ResourceResponse<CoursePerson[]>> =>
      $api<ResourceResponse<CoursePerson[]>>(`/api/lms/courses/${slug}/experts`, {
        method: 'PUT',
        body: { experts },
      }),

    searchExpertCandidates: (slug: string, search: string): Promise<ResourceResponse<CoursePerson[]>> =>
      $api<ResourceResponse<CoursePerson[]>>(`/api/lms/courses/${slug}/experts/candidates`, {
        query: { search },
      }),

    fetchCategories: (): Promise<ResourceResponse<Category[]>> =>
      $api<ResourceResponse<Category[]>>('/api/lms/categories'),

    createCategory: (body: CategoryPayload): Promise<ResourceResponse<Category>> =>
      $api<ResourceResponse<Category>>('/api/lms/categories', { method: 'POST', body }),

    updateCategory: (slug: string, body: CategoryPayload): Promise<ResourceResponse<Category>> =>
      $api<ResourceResponse<Category>>(`/api/lms/categories/${slug}`, { method: 'PUT', body }),

    deleteCategory: (slug: string) =>
      $api(`/api/lms/categories/${slug}`, { method: 'DELETE' }),

    uploadVideo: (
      lessonId: number | string,
      file: File,
      options: UploadOptions = {},
    ): Promise<ResourceResponse<LessonSummary>> => {
      const form = new FormData()
      form.append('video', file)

      return $upload<ResourceResponse<LessonSummary>>(`/api/lms/lessons/${lessonId}/video`, form, options)
    },

    deleteVideo: (lessonId: number | string) =>
      $api(`/api/lms/lessons/${lessonId}/video`, { method: 'DELETE' }),

    ask: (question: string): Promise<ResourceResponse<ConsultantAnswer>> =>
      $api<ResourceResponse<ConsultantAnswer>>('/api/lms/ask', {
        method: 'POST',
        body: { question },
      }),

    /**
     * Помог ли ответ. Ставится только на свой вопрос — это решает сервер.
     */
    rateAnswer: (questionId: number, helpful: boolean): Promise<ResourceResponse<ConsultantExchange>> =>
      $api<ResourceResponse<ConsultantExchange>>(`/api/lms/ask/${questionId}/feedback`, {
        method: 'POST',
        body: { helpful },
      }),

    /**
     * Заявка на дополнение ответа: просьба к авторам дописать материал.
     *
     * Пояснение необязательно — человек, которому ответили не о том, чаще всего
     * не умеет сказать, чего именно не хватило.
     */
    requestFollowUp: (questionId: number, note?: string): Promise<ResourceResponse<ConsultantExchange>> =>
      $api<ResourceResponse<ConsultantExchange>>(`/api/lms/ask/${questionId}/request`, {
        method: 'POST',
        body: { note },
      }),
  }
}
