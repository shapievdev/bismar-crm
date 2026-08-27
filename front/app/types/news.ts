import type { JSONContent } from '@tiptap/core'
import type { LessonAttachment, Quiz } from '~/types/lms'

export type NewsStatus = 'draft' | 'published'
export type NewsAudienceKind = 'everyone' | 'selected'

/** Человек в списке адресатов или ознакомившихся. */
export interface NewsPerson {
  id: number
  name: string
  email: string
  avatar_url: string | null
  /** Есть только у тех, кто отметился. */
  acknowledged_at?: string
  /** «Подтвердил» или «Сдал тест». */
  acknowledged_via?: string
}

export interface News {
  id: number
  title: string
  slug: string
  excerpt: string | null

  /** Статья едет только с карточкой новости — в ленте её нет. */
  content_json?: JSONContent | null

  status: NewsStatus
  status_label: string
  is_published: boolean
  published_at: string | null
  is_pinned: boolean

  audience: NewsAudienceKind
  audience_label: string
  requires_acknowledgement: boolean

  author?: { id: number, name: string } | null

  /** Только на экране составителя. */
  recipients?: NewsPerson[]

  attachments?: LessonAttachment[]

  /**
   * Проверка при новости. По устройству совпадает с тестом урока, поэтому и
   * тип тот же: разметка редактора у них общая.
   */
  quiz?: Quiz | null

  is_acknowledged: boolean
  acknowledged_at: string | null

  /** Для того, кто новость ведёт: сколько отметилось из скольких. */
  acknowledged_count?: number
  audience_size?: number
}

export interface NewsPayload {
  title: string
  excerpt: string | null
  content_json: JSONContent | null
  status: NewsStatus
  is_pinned: boolean
  audience: NewsAudienceKind
  requires_acknowledgement: boolean
  recipients: number[]
}

/** Кто ознакомился, а кто ещё нет. */
export interface NewsAcknowledgements {
  acknowledged: NewsPerson[]
  pending: NewsPerson[]
}

export interface NewsQuizResult {
  score: number
  passed: boolean
  completed_at: string | null
  is_acknowledged: boolean
}
