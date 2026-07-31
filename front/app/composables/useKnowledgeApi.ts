import type { ResourceResponse } from '~/types/auth'
import type {
  ArticlePayload,
  KnowledgeArticle,
  KnowledgeCategory,
  PaginatedResponse,
  StatusOption,
} from '~/types/knowledge'

export interface ArticleQuery {
  search?: string
  category?: string
  status?: string
  page?: number
}

/**
 * Typed access to the knowledge base endpoints.
 */
export function useKnowledgeApi() {
  const { $api } = useNuxtApp()

  return {
    fetchArticles: (query: ArticleQuery = {}): Promise<PaginatedResponse<KnowledgeArticle>> =>
      $api<PaginatedResponse<KnowledgeArticle>>('/api/knowledge/articles', { query }),

    fetchArticle: (slug: string): Promise<ResourceResponse<KnowledgeArticle>> =>
      $api<ResourceResponse<KnowledgeArticle>>(`/api/knowledge/articles/${slug}`),

    createArticle: (payload: ArticlePayload): Promise<ResourceResponse<KnowledgeArticle>> =>
      $api<ResourceResponse<KnowledgeArticle>>('/api/knowledge/articles', {
        method: 'POST',
        body: payload,
      }),

    updateArticle: (slug: string, payload: ArticlePayload): Promise<ResourceResponse<KnowledgeArticle>> =>
      $api<ResourceResponse<KnowledgeArticle>>(`/api/knowledge/articles/${slug}`, {
        method: 'PUT',
        body: payload,
      }),

    deleteArticle: (slug: string): Promise<void> =>
      $api(`/api/knowledge/articles/${slug}`, { method: 'DELETE' }),

    fetchCategories: (): Promise<ResourceResponse<KnowledgeCategory[]>> =>
      $api<ResourceResponse<KnowledgeCategory[]>>('/api/knowledge/categories'),

    fetchStatuses: (): Promise<ResourceResponse<StatusOption[]>> =>
      $api<ResourceResponse<StatusOption[]>>('/api/knowledge/statuses'),
  }
}
