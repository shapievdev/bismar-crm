/** Личная переписка или групповая. */
export type ConversationKind = 'direct' | 'group'

/** Сказанное человеком или отмеченное системой. */
export type MessageKind = 'text' | 'system'

/** У кого удаляют переписку: у себя одного или у всех сразу. */
export type DeletionScope = 'mine' | 'everyone'

export interface ChatPerson {
  id: number
  /** Полное ФИО: «Курабанов Давлет Избуллаевич». */
  name: string
  /** Имя с инициалами: «Давлет К. И.» — для заголовков и списка. */
  short_name: string
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

/**
 * Своя реплика, пока она уходит на сервер.
 *
 * Такая строка живёт только в браузере: она встаёт в ленту сразу, показывает,
 * сколько байт уже ушло, и уступает место ответу сервера. Пока файлы летят,
 * человек продолжает писать — поэтому отправка ничего не блокирует, а состояние
 * висит на самой реплике, а не на поле ввода.
 */
export interface Sending {
  /** Отличает свою ещё не отправленную строку от настоящей. */
  sending: true
  /** Сколько байт уже ушло, 0–100. */
  progress: number
  /** Чем отправка сорвалась; пусто — ещё летит. */
  error?: string
  /** Что отправляли: нужно, чтобы повторить тем же составом. */
  files: File[]
  /**
   * Предпросмотр выбранного, адресами `blob:`.
   *
   * Снимки видны в ленте, не дожидаясь сервера, — иначе на месте отправляемого
   * сообщения висел бы пустой прямоугольник. Адреса освобождаются, когда строку
   * сменяет ответ сервера.
   */
  previews: string[]
}

/** Строка ленты: пришедшая с сервера либо своя, пока она уходит. */
export type ThreadMessage = ChatMessage & Partial<Sending>

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
