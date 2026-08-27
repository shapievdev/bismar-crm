import { toValidationError } from '~/composables/useAuth'
import type { UploadOptions } from '~/utils/upload'
import type { ResourceResponse } from '~/types/auth'
import type { LessonAttachment, PaginatedResponse, Quiz, QuizPayload } from '~/types/lms'
import type {
  News,
  NewsAcknowledgements,
  NewsPayload,
  NewsPerson,
  NewsQuizResult,
} from '~/types/news'

/**
 * Новости.
 *
 * Читает лента на главной, ведёт редактор. Типы вложения и проверки взяты у
 * базы знаний: устройство у них одно и то же, а разметка редактора — общая.
 */
export function useNewsApi() {
  const { $api, $upload } = useNuxtApp()

  return {
    /** Лента: то, что этот человек вправе прочитать. */
    fetchFeed: (page = 1): Promise<PaginatedResponse<News>> =>
      $api<PaginatedResponse<News>>('/api/news', { query: { page } }),

    /** Редакция: всё, включая черновики. */
    fetchAll: (page = 1): Promise<PaginatedResponse<News>> =>
      $api<PaginatedResponse<News>>('/api/news/manage', { query: { page } }),

    /** Сколько новостей ждут ознакомления — для значка на рельсе. */
    fetchPendingCount: (): Promise<{ data: { count: number } }> =>
      $api<{ data: { count: number } }>('/api/news/pending-count'),

    fetchNews: (slug: string): Promise<ResourceResponse<News>> =>
      $api<ResourceResponse<News>>(`/api/news/${slug}`),

    createNews: (payload: NewsPayload): Promise<ResourceResponse<News>> =>
      $api<ResourceResponse<News>>('/api/news', { method: 'POST', body: payload })
        .catch(toValidationError),

    updateNews: (slug: string, payload: NewsPayload): Promise<ResourceResponse<News>> =>
      $api<ResourceResponse<News>>(`/api/news/${slug}`, { method: 'PUT', body: payload })
        .catch(toValidationError),

    deleteNews: (slug: string) =>
      $api(`/api/news/${slug}`, { method: 'DELETE' }),

    /** Кого можно назвать адресатом — поиском. */
    searchPeople: (search: string): Promise<ResourceResponse<NewsPerson[]>> =>
      $api<ResourceResponse<NewsPerson[]>>('/api/news/people', { query: { search } }),

    /** Отметиться самому. При проверке кнопки нет — сервер такую отметку не примет. */
    acknowledge: (slug: string): Promise<{ data: { is_acknowledged: boolean, acknowledged_at: string | null } }> =>
      $api<{ data: { is_acknowledged: boolean, acknowledged_at: string | null } }>(
        `/api/news/${slug}/acknowledge`,
        { method: 'POST' },
      ),

    fetchAcknowledgements: (slug: string): Promise<{ data: NewsAcknowledgements }> =>
      $api<{ data: NewsAcknowledgements }>(`/api/news/${slug}/acknowledgements`),

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
        `/api/news/${slug}/attachments`,
        form,
        options,
      )
    },

    updateAttachment: (slug: string, attachmentId: number, description: string | null) =>
      $api<ResourceResponse<LessonAttachment>>(`/api/news/${slug}/attachments/${attachmentId}`, {
        method: 'PUT',
        body: { description },
      }),

    deleteAttachment: (slug: string, attachmentId: number) =>
      $api(`/api/news/${slug}/attachments/${attachmentId}`, { method: 'DELETE' }),

    saveQuiz: (slug: string, body: QuizPayload): Promise<ResourceResponse<Quiz>> =>
      $api<ResourceResponse<Quiz>>(`/api/news/${slug}/quiz`, { method: 'PUT', body })
        .catch(toValidationError),

    deleteQuiz: (slug: string) =>
      $api(`/api/news/${slug}/quiz`, { method: 'DELETE' }),

    /** Пройти проверку. Сдал — новость считается прочитанной. */
    submitQuiz: (slug: string, answers: Record<number, number[]>): Promise<{ data: NewsQuizResult }> =>
      $api<{ data: NewsQuizResult }>(`/api/news/${slug}/quiz/submit`, {
        method: 'POST',
        body: { answers },
      }),
  }
}
