export type ArticleStatus = 'draft' | 'published' | 'archived'

export interface KnowledgeCategory {
  id: number
  name: string
  slug: string
  description: string | null
  position: number
  articles_count?: number
}

export interface KnowledgeArticle {
  id: number
  title: string
  slug: string
  excerpt: string | null
  /** Only the detail and save endpoints return the body. */
  content?: string
  status: ArticleStatus
  status_label: string
  published_at: string | null
  updated_at: string | null
  category: KnowledgeCategory | null
  author: { id: number, name: string } | null
}

export interface ArticlePayload {
  title: string
  excerpt: string | null
  content: string
  status: ArticleStatus
  category_id: number | null
}

export interface StatusOption {
  value: ArticleStatus
  label: string
}

/** Laravel's paginated collection envelope. */
export interface PaginatedResponse<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}
