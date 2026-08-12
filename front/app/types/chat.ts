/** Личная переписка или групповая. */
export type ConversationKind = 'direct' | 'group'

/** Сказанное человеком или отмеченное системой. */
export type MessageKind = 'text' | 'system'

export interface ChatPerson {
  id: number
  name: string
  email: string
  avatar_url: string | null
  /** До какого места дочитал — есть, когда список участников загружен с ним. */
  last_read_at?: string | null
}

export interface MessageAttachment {
  id: number
  name: string
  mime_type: string | null
  size: number
  /** Показывать ли прямо в переписке: картинку показываем, архив нет. */
  opens_inline: boolean
  url: string | null
}

/**
 * Цитата над ответом: столько, сколько нужно, чтобы узнать реплику.
 *
 * Удалённая приходит помеченной и без текста — показать надо, что отвечали на
 * что-то, чего больше нет.
 */
export interface QuotedMessage {
  id: number
  deleted: boolean
  author: ChatPerson | null
  excerpt: string | null
}

export interface ChatMessage {
  id: number
  conversation_id: number
  kind: MessageKind
  body: string | null
  /** Нет у системного сообщения и у сообщения уволившегося. */
  author: ChatPerson | null
  attachments: MessageAttachment[]
  created_at: string | null
  /** Когда правили; null — не правили ни разу. */
  edited_at: string | null
  /** На что отвечали; null — ни на что. */
  reply_to: QuotedMessage | null
}

export interface Conversation {
  id: number
  kind: ConversationKind
  is_group: boolean
  /** У личной переписки — имя собеседника, у групповой — название. */
  title: string
  companion: ChatPerson | null
  participants?: ChatPerson[]
  participants_count?: number
  last_message?: ChatMessage
  last_message_at: string | null
  unread_count: number
  /** Завёл ли группу этот человек: состав и название ведёт он. */
  is_owner: boolean
}
