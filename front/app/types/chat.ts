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
  /** Способ связи, если он записан: почта необязательна. */
  email: string | null
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
  /**
   * Надиктовано здесь же, а не приложено файлом.
   *
   * Признак, а не тип: присланная почтой запись совещания — тоже звук, но
   * показывать её волной с кнопкой неправильно, это документ.
   */
  is_voice: boolean
  /** Длительность записи; у обычного файла пусто. */
  duration_ms: number | null
  /** Высоты столбиков волны, 0–100. */
  waveform: number[]
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

/**
 * Материал, с которого написали, — карточкой над репликой.
 *
 * Названия и адрес лежат снимком со дня отправки: материал переименуют или
 * выбросят, а разговор должен остаться читаемым.
 */
export interface MessageAbout {
  kind: 'course' | 'lesson' | 'document' | 'handbook'
  /** «Курс», «Урок», «Документ», «Справочник». */
  kind_label: string
  title: string
  /** Курс, внутри которого лежит урок. У остальных пусто. */
  context: string | null
  url: string | null
  reason: 'missing' | 'incorrect' | null
  /** «Ответа не хватило» или «Здесь написано неверно». */
  reason_label: string | null
}

/**
 * Откуда переслано — снимком на день пересылки.
 *
 * Снимком, а не связью: исходную реплику могут удалить, автора уволить, а
 * группу, из которой её взяли, стереть целиком. Подпись «Переслано от Иванова»
 * обязана пережить всё это.
 */
export interface ForwardedFrom {
  author_id: number | null
  author_name: string
  /** Когда это было сказано впервые. */
  said_at: string | null
}

/**
 * Отклик под репликой: знак, сколько и кто.
 *
 * «Своё» здесь не приходит: тот же набор уходит всем участникам сразу, и
 * вкладка узнаёт себя по номерам откликнувшихся.
 */
export interface MessageReaction {
  emoji: string
  count: number
  user_ids: number[]
  /** Короткие имена откликнувшихся — для подсказки «кто именно». */
  people: string[]
}

/**
 * Чем материал назван в запросе: вид и номер, и только.
 *
 * Название и адрес собирает сервер — экран их не придумывает и не носит в
 * адресе, иначе карточкой над репликой можно было бы объявить что угодно.
 */
export interface MaterialRef {
  kind: MessageAbout['kind']
  id: number
}

export interface ChatMessage {
  id: number
  conversation_id: number
  kind: MessageKind
  body: string | null
  /** С какого материала пришло замечание; null — обычная реплика. */
  about: MessageAbout | null
  /** Нет у системного сообщения и у сообщения уволившегося. */
  author: ChatPerson | null
  attachments: MessageAttachment[]
  created_at: string | null
  /** Когда правили; null — не правили ни разу. */
  edited_at: string | null
  /** На что отвечали; null — ни на что. */
  reply_to: QuotedMessage | null
  /** Откуда переслано; null — сказано здесь. */
  forwarded: ForwardedFrom | null
  /** Отклики под репликой; пустой массив — их нет. */
  reactions: MessageReaction[]
  /** Когда подняли наверх переписки; null — не поднимали. */
  pinned_at: string | null
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
  /**
   * Материал, с которого пишут, — ссылкой: сама карточка уже стоит в `about`
   * и видна в ленте, а серверу при повторе нужен всё тот же вид с номером.
   */
  aboutRef?: MaterialRef | null
  /** Кого позвали по имени — их номера уходят вместе с текстом. */
  mentions?: number[]
  /** Числа надиктованной записи: сервер их не считает, а браузер уже знает. */
  voice?: VoiceNumbers | null
}

/** Длительность и волна записи — то, что о ней знает только браузер. */
export interface VoiceNumbers {
  duration_ms: number
  waveform: number[]
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
  /**
   * Сколько из непрочитанного зовёт по имени.
   *
   * Отдельной цифрой: сорок непрочитанных в рабочей группе можно прочесть
   * вечером, вопрос лично тебе — нельзя.
   */
  unread_mentions: number
  /** Завёл ли группу этот человек: состав и название ведёт он. */
  is_owner: boolean
  /** Приглушена ли — уведомления по ней не приходят. */
  is_muted: boolean
  /** Поднята ли наверх списка. */
  is_pinned: boolean
}

/**
 * Карточка ссылки: чем страница себя назвала.
 *
 * В самом сообщении её нет — она читается по требованию и живёт в общем кэше
 * сутки. Ссылка — единственное, что сказал человек; заголовок и картинка
 * принадлежат сайту и завтра могут стать другими.
 */
export interface LinkCard {
  url: string
  host: string
  title: string
  description: string | null
  site_name: string | null
  image: string | null
}

/** Находка поиска — строка списка, а не сообщение: вложений в ней нет. */
export interface MessageHit {
  id: number
  conversation_id: number
  body: string | null
  author: ChatPerson | null
  created_at: string | null
}
