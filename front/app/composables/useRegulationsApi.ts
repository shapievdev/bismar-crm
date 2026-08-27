import { toValidationError } from '~/composables/useAuth'
import type { ResourceResponse } from '~/types/auth'
import type {
  CategoryPayload,
  CoursePerson,
  LessonAttachment,
  PaginatedResponse,
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
 * Регламенты — правила, по которым работают.
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

    /** Прочитал — весь прогресс, какой у регламента бывает. */
    acknowledge: (slug: string): Promise<{ data: { is_acknowledged: boolean, acknowledged_at: string | null } }> =>
      $api<{ data: { is_acknowledged: boolean, acknowledged_at: string | null } }>(
        `/api/lms/regulations/${slug}/acknowledge`,
        { method: 'POST' },
      ),

    fetchReaders: (slug: string): Promise<ResourceResponse<CoursePerson[]>> =>
      $api<ResourceResponse<CoursePerson[]>>(`/api/lms/regulations/${slug}/acknowledgements`),

    /* ---------- Категории: своё дерево ---------- */

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

    updateAttachment: (slug: string, attachmentId: number, description: string | null) =>
      $api<ResourceResponse<LessonAttachment>>(`/api/lms/regulations/${slug}/attachments/${attachmentId}`, {
        method: 'PUT',
        body: { description },
      }),

    deleteAttachment: (slug: string, attachmentId: number) =>
      $api(`/api/lms/regulations/${slug}/attachments/${attachmentId}`, { method: 'DELETE' }),
  }
}
