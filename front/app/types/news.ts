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

/** Что бывает целью ссылки при новости. */
export type LinkedKind = 'course' | 'module' | 'lesson' | 'regulation'

/**
 * Куда сходить после новости.
 *
 * Приходит готовой ссылкой, а не парой «вид и номер»: у модуля своей страницы
 * нет и он ведёт на курс, у урока адрес складывается из курса и номера.
 */
export interface NewsLink {
  id: number
  kind: LinkedKind
  kind_label: string
  item_id: number
  title: string | null
  subtitle: string | null
  url: string | null
}

/** Найденный материал — то же, но ещё не привязанное. */
export interface LinkedMaterialResult {
  kind: LinkedKind
  id: number
  title: string
  subtitle: string | null
  url: string | null
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

  /**
   * Кому адресована — только на экране составителя. Три списка, и они
   * складываются: отделу, группе и ещё двоим поимённо.
   */
  recipients?: NewsPerson[]
  departments?: NewsAddressee[]
  groups?: NewsAddressee[]

  attachments?: LessonAttachment[]

  /** Куда сходить после новости: курс, модуль, урок или документ. */
  links?: NewsLink[]

  /**
   * Проверка при новости. По устройству совпадает с тестом урока, поэтому и
   * тип тот же: разметка редактора у них общая.
   */
  quiz?: Quiz | null

  is_acknowledged: boolean
  acknowledged_at: string | null

  /**
   * Ждёт ли новость ознакомления именно от смотрящего.
   *
   * Не то же, что `requires_acknowledgement`: та говорит о самой новости, эта
   * — о человеке. Вышедшее до его прихода в компанию его не обязывает, и
   * встречать новичка десятком долгов незачем.
   */
  awaits_acknowledgement: boolean

  /** Для того, кто новость ведёт: сколько отметилось из скольких. */
  acknowledged_count?: number
  audience_size?: number
}

/**
 * Отдел или группа в адресатах новости: название — всё, что о них нужно
 * знать экрану, состав читает сервер на каждом обращении.
 */
export interface NewsAddressee {
  id: number
  name: string
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
  department_ids: number[]
  group_ids: number[]
  links: { type: LinkedKind, id: number }[]
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
