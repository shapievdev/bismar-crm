import type { ResourceResponse } from '~/types/auth'
import type {
  Course,
  CoursePayload,
  Enrollment,
  LessonAttachment,
  LessonPayload,
  LessonSummary,
  ModulePayload,
  PaginatedResponse,
  Quiz,
  QuizAttempt,
  QuizPayload,
  StatusOption,
} from '~/types/lms'

export interface CourseQuery {
  search?: string
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
  const { $api } = useNuxtApp()

  return {
    fetchCourses: (query: CourseQuery = {}): Promise<PaginatedResponse<Course>> =>
      $api<PaginatedResponse<Course>>('/api/lms/courses', { query }),

    fetchCourse: (slug: string): Promise<ResourceResponse<Course>> =>
      $api<ResourceResponse<Course>>(`/api/lms/courses/${slug}`),

    createCourse: (payload: CoursePayload): Promise<ResourceResponse<Course>> =>
      $api<ResourceResponse<Course>>('/api/lms/courses', { method: 'POST', body: payload }),

    updateCourse: (slug: string, payload: CoursePayload): Promise<ResourceResponse<Course>> =>
      $api<ResourceResponse<Course>>(`/api/lms/courses/${slug}`, { method: 'PUT', body: payload }),

    fetchStatuses: (): Promise<ResourceResponse<StatusOption[]>> =>
      $api<ResourceResponse<StatusOption[]>>('/api/lms/statuses'),

    enroll: (slug: string): Promise<ResourceResponse<Enrollment>> =>
      $api<ResourceResponse<Enrollment>>(`/api/lms/courses/${slug}/enroll`, { method: 'POST' }),

    myCourses: (): Promise<ResourceResponse<Enrollment[]>> =>
      $api<ResourceResponse<Enrollment[]>>('/api/lms/my-courses'),

    fetchLesson: (id: number | string): Promise<ResourceResponse<LessonSummary>> =>
      $api<ResourceResponse<LessonSummary>>(`/api/lms/lessons/${id}`),

    completeLesson: (id: number | string): Promise<LessonProgressResult> =>
      $api<LessonProgressResult>(`/api/lms/lessons/${id}/complete`, { method: 'POST' }),

    submitQuiz: (
      lessonId: number | string,
      answers: Record<number, number[]>,
    ): Promise<ResourceResponse<QuizAttempt>> =>
      $api<ResourceResponse<QuizAttempt>>(`/api/lms/lessons/${lessonId}/quiz/submit`, {
        method: 'POST',
        body: { answers },
      }),

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

    uploadAttachment: (lessonId: number | string, file: File) => {
      const form = new FormData()
      form.append('file', file)

      return $api<ResourceResponse<LessonAttachment>>(`/api/lms/lessons/${lessonId}/attachments`, {
        method: 'POST',
        body: form,
      })
    },

    deleteAttachment: (attachmentId: number) =>
      $api(`/api/lms/attachments/${attachmentId}`, { method: 'DELETE' }),

    saveQuiz: (lessonId: number | string, body: QuizPayload): Promise<ResourceResponse<Quiz>> =>
      $api<ResourceResponse<Quiz>>(`/api/lms/lessons/${lessonId}/quiz`, { method: 'PUT', body }),

    deleteQuiz: (lessonId: number | string) =>
      $api(`/api/lms/lessons/${lessonId}/quiz`, { method: 'DELETE' }),
  }
}
