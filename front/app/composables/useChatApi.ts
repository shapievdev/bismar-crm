import type { ResourceResponse } from '~/types/auth'
import type {
  ChatMessage,
  ChatPerson,
  Conversation,
  DeletionScope,
  LinkCard,
  MaterialRef,
  MessageAbout,
  MessageHit,
  MessageReaction,
  VoiceNumbers,
} from '~/types/chat'

/** Что приезжает вместе с куском ленты, кроме самих сообщений. */
export interface ThreadMeta {
  /** Закреплённое наверху — последнее поднятое первым. */
  pinned: ChatMessage[]
  /** Где начинается непрочитанное: там встаёт отбивка. */
  first_unread_id: number | null
}

/** Что уходит вместе с сообщением сверх текста и файлов. */
export interface SendExtras {
  replyToId?: number | null
  about?: MaterialRef | null
  /** Кого позвали по имени. */
  mentions?: number[]
  /** Числа надиктованной записи: сервер их не считает. */
  voice?: VoiceNumbers | null
}

/**
 * Обращения к мессенджеру.
 *
 * Сокеты приносят новое, а всё остальное — обычные запросы: история читается
 * кусками, отправка идёт обычным POST. Живое соединение нужно, чтобы узнать о
 * чужом сообщении, а не чтобы заменить собой API.
 */
export function useChatApi() {
  const { $api, $upload } = useNuxtApp()

  return {
    fetchConversations: (): Promise<ResourceResponse<Conversation[]>> =>
      $api<ResourceResponse<Conversation[]>>('/api/chat/conversations'),

    fetchConversation: (id: number): Promise<ResourceResponse<Conversation>> =>
      $api<ResourceResponse<Conversation>>(`/api/chat/conversations/${id}`),

    /** Личная переписка с этим человеком — заводится или находится прежняя. */
    startDirect: (userId: number): Promise<ResourceResponse<Conversation>> =>
      $api<ResourceResponse<Conversation>>('/api/chat/conversations', {
        method: 'POST',
        body: { kind: 'direct', user_id: userId },
      }),

    startGroup: (title: string, userIds: number[]): Promise<ResourceResponse<Conversation>> =>
      $api<ResourceResponse<Conversation>>('/api/chat/conversations', {
        method: 'POST',
        body: { kind: 'group', title, user_ids: userIds },
      }),

    renameConversation: (id: number, title: string): Promise<ResourceResponse<Conversation>> =>
      $api<ResourceResponse<Conversation>>(`/api/chat/conversations/${id}`, {
        method: 'PUT',
        body: { title },
      }),

    /**
     * Кусок ленты — и то, что к нему прилагается: закреплённое и место, с
     * которого начинается непрочитанное.
     *
     * `before` читает прошлое, `after` — будущее: второе нужно перескоку к
     * найденному поиском, иначе под перенесённой репликой оставалась бы пустота.
     */
    fetchMessages: (
      id: number,
      around: { before?: number, after?: number } = {},
    ): Promise<ResourceResponse<ChatMessage[]> & { meta: ThreadMeta }> =>
      $api<ResourceResponse<ChatMessage[]> & { meta: ThreadMeta }>(
        `/api/chat/conversations/${id}/messages`,
        { query: { ...(around.before ? { before: around.before } : {}), ...(around.after ? { after: around.after } : {}) } },
      ),

    /**
     * Отправка. Текст и файлы уходят вместе, одним обращением: сообщение с
     * подписью «вот прайс» и сам прайс — это одно сообщение, а не два.
     */
    sendMessage: (
      id: number,
      body: string,
      files: File[] = [],
      options: UploadOptions = {},
      extras: SendExtras = {},
    ): Promise<ResourceResponse<ChatMessage>> => {
      const form = new FormData()

      if (body) {
        form.append('body', body)
      }

      if (extras.replyToId !== null && extras.replyToId !== undefined) {
        form.append('reply_to_id', String(extras.replyToId))
      }

      // Вид и номер — всё, что нужно серверу: карточку он соберёт сам.
      if (extras.about) {
        form.append('about[kind]', extras.about.kind)
        form.append('about[id]', String(extras.about.id))
      }

      // Номерами, а не разбором текста: имя в реплике человек мог набрать
      // руками, а позвать — не позвать.
      extras.mentions?.forEach(id => form.append('mentions[]', String(id)))

      if (extras.voice) {
        form.append('voice[duration_ms]', String(extras.voice.duration_ms))
        extras.voice.waveform.forEach(height => form.append('voice[waveform][]', String(height)))
      }

      files.forEach(file => form.append('attachments[]', file))

      return $upload<ResourceResponse<ChatMessage>>(`/api/chat/conversations/${id}/messages`, form, options)
    },

    /**
     * Карточка материала, с которого собираются написать: её показывают над
     * полем ввода, чтобы человек видел то же, что увидит адресат.
     */
    fetchAbout: (about: MaterialRef): Promise<ResourceResponse<MessageAbout>> =>
      $api<ResourceResponse<MessageAbout>>('/api/chat/about', { query: about }),

    /** Правка своей реплики. Вложения не трогаются — меняются только слова. */
    editMessage: (
      conversationId: number,
      messageId: number,
      body: string,
    ): Promise<ResourceResponse<ChatMessage>> =>
      $api<ResourceResponse<ChatMessage>>(
        `/api/chat/conversations/${conversationId}/messages/${messageId}`,
        { method: 'PATCH', body: { body } },
      ),

    /** Удаление — у всех сразу: «убрать у себя» здесь нет намеренно. */
    deleteMessage: (conversationId: number, messageId: number) =>
      $api(`/api/chat/conversations/${conversationId}/messages/${messageId}`, { method: 'DELETE' }),

    /** Удаление выделенного: всё или ничего — сервер проверяет права до первого. */
    deleteMessages: (conversationId: number, messageIds: number[]): Promise<ResourceResponse<{ deleted: number }>> =>
      $api<ResourceResponse<{ deleted: number }>>(`/api/chat/conversations/${conversationId}/messages`, {
        method: 'DELETE',
        body: { message_ids: messageIds },
      }),

    /**
     * Отклик: поставить, сменить, снять — одним обращением.
     *
     * Для человека это одно нажатие по знаку, и разделять его на «создать» и
     * «удалить» значило бы заставить экран помнить, что там стоит сейчас.
     */
    react: (
      conversationId: number,
      messageId: number,
      emoji: string,
    ): Promise<ResourceResponse<{ message_id: number, mine: string | null, reactions: MessageReaction[] }>> =>
      $api(`/api/chat/conversations/${conversationId}/messages/${messageId}/reactions`, {
        method: 'POST',
        body: { emoji },
      }),

    pinMessage: (conversationId: number, messageId: number): Promise<ResourceResponse<ChatMessage>> =>
      $api<ResourceResponse<ChatMessage>>(`/api/chat/conversations/${conversationId}/messages/${messageId}/pin`, {
        method: 'POST',
      }),

    unpinMessage: (conversationId: number, messageId: number): Promise<ResourceResponse<ChatMessage>> =>
      $api<ResourceResponse<ChatMessage>>(`/api/chat/conversations/${conversationId}/messages/${messageId}/pin`, {
        method: 'DELETE',
      }),

    /** Пересылка: берут отсюда, кладут в названную переписку. */
    forwardMessages: (
      fromId: number,
      messageIds: number[],
      toId: number,
    ): Promise<ResourceResponse<ChatMessage[]>> =>
      $api<ResourceResponse<ChatMessage[]>>(`/api/chat/conversations/${fromId}/forward`, {
        method: 'POST',
        body: { message_ids: messageIds, to_conversation_id: toId },
      }),

    /** Приглушить разговор и вернуть ему звук — отметка личная. */
    muteConversation: (
      id: number,
      muted: boolean,
    ): Promise<ResourceResponse<{ is_muted: boolean, is_pinned: boolean }>> =>
      $api(`/api/chat/conversations/${id}/mute`, { method: 'POST', body: { muted } }),

    pinConversation: (
      id: number,
      pinned: boolean,
    ): Promise<ResourceResponse<{ is_muted: boolean, is_pinned: boolean }>> =>
      $api(`/api/chat/conversations/${id}/pin`, { method: 'POST', body: { pinned } }),

    /** Поиск по ленте одной переписки — с общим числом находок для счётчика. */
    searchInConversation: (
      id: number,
      query: string,
      before?: number,
    ): Promise<ResourceResponse<MessageHit[]> & { meta: { total: number } }> =>
      $api(`/api/chat/conversations/${id}/search`, { query: { q: query, ...(before ? { before } : {}) } }),

    /** Поиск по всем своим перепискам. */
    searchEverywhere: (query: string, before?: number): Promise<ResourceResponse<MessageHit[]>> =>
      $api(`/api/chat/search`, { query: { q: query, ...(before ? { before } : {}) } }),

    markRead: (id: number): Promise<ResourceResponse<{ read_at: string }>> =>
      $api<ResourceResponse<{ read_at: string }>>(`/api/chat/conversations/${id}/read`, { method: 'POST' }),

    leaveConversation: (id: number) =>
      $api(`/api/chat/conversations/${id}/leave`, { method: 'POST' }),

    /**
     * Удаление переписки: у себя либо у всех.
     *
     * У себя — разговор уходит из своего списка вместе с прошлым, у собеседника
     * остаётся целиком. У всех — не остаётся ни у кого: личную так удаляет
     * любой из двоих, группу — только тот, кто её завёл.
     */
    deleteConversation: (id: number, scope: DeletionScope = 'mine') =>
      $api(`/api/chat/conversations/${id}`, { method: 'DELETE', body: { scope } }),

    addParticipants: (id: number, userIds: number[]): Promise<ResourceResponse<ChatPerson[]>> =>
      $api<ResourceResponse<ChatPerson[]>>(`/api/chat/conversations/${id}/participants`, {
        method: 'POST',
        body: { user_ids: userIds },
      }),

    removeParticipant: (id: number, userId: number): Promise<ResourceResponse<ChatPerson[]>> =>
      $api<ResourceResponse<ChatPerson[]>>(`/api/chat/conversations/${id}/participants/${userId}`, {
        method: 'DELETE',
      }),

    fetchUnread: (): Promise<ResourceResponse<{ unread: number }>> =>
      $api<ResourceResponse<{ unread: number }>>('/api/chat/unread'),

    /**
     * Карточка ссылки, сказанной в переписке.
     *
     * Спрашивается по мере показа реплик, а не приезжает вместе с ними: ждать
     * чужой сайт, пока уходит сообщение, нельзя. Пустой ответ — обычное дело,
     * половина ссылок ведёт туда, где показывать нечего.
     */
    fetchLinkPreview: (url: string): Promise<ResourceResponse<LinkCard | null>> =>
      $api<ResourceResponse<LinkCard | null>>('/api/chat/link-preview', { query: { url } }),

    /** Кому можно написать. */
    searchContacts: (search = ''): Promise<ResourceResponse<ChatPerson[]>> =>
      $api<ResourceResponse<ChatPerson[]>>('/api/chat/contacts', { query: search ? { search } : {} }),
  }
}
