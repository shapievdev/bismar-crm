import type { ResourceResponse } from '~/types/auth'
import type {
  Category,
  CategoryPayload,
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

    uploadAttachment: (lessonId: number | string, file: File, description: string | null = null) => {
      const form = new FormData()
      form.append('file', file)

      if (description) {
        form.append('description', description)
      }

      return $api<ResourceResponse<LessonAttachment>>(`/api/lms/lessons/${lessonId}/attachments`, {
        method: 'POST',
        body: form,
      })
    },

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

    uploadCover: (slug: string, file: File): Promise<ResourceResponse<Course>> => {
      const form = new FormData()
      form.append('cover', file)

      return $api<ResourceResponse<Course>>(`/api/lms/courses/${slug}/cover`, {
        method: 'POST',
        body: form,
      })
    },

    deleteCover: (slug: string) =>
      $api(`/api/lms/courses/${slug}/cover`, { method: 'DELETE' }),

    fetchCategories: (): Promise<ResourceResponse<Category[]>> =>
      $api<ResourceResponse<Category[]>>('/api/lms/categories'),

    createCategory: (body: CategoryPayload): Promise<ResourceResponse<Category>> =>
      $api<ResourceResponse<Category>>('/api/lms/categories', { method: 'POST', body }),

    updateCategory: (slug: string, body: CategoryPayload): Promise<ResourceResponse<Category>> =>
      $api<ResourceResponse<Category>>(`/api/lms/categories/${slug}`, { method: 'PUT', body }),

    deleteCategory: (slug: string) =>
      $api(`/api/lms/categories/${slug}`, { method: 'DELETE' }),

    uploadVideo: (lessonId: number | string, file: File): Promise<ResourceResponse<LessonSummary>> => {
      const form = new FormData()
      form.append('video', file)

      return $api<ResourceResponse<LessonSummary>>(`/api/lms/lessons/${lessonId}/video`, {
        method: 'POST',
        body: form,
      })
    },

    deleteVideo: (lessonId: number | string) =>
      $api(`/api/lms/lessons/${lessonId}/video`, { method: 'DELETE' }),
  }
}
