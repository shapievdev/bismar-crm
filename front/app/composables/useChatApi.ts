import type { ResourceResponse } from '~/types/auth'
import type { ChatMessage, ChatPerson, Conversation, DeletionScope } from '~/types/chat'

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

    /** Кусок ленты: последние сорок либо сорок до указанного сообщения. */
    fetchMessages: (id: number, before?: number): Promise<ResourceResponse<ChatMessage[]>> =>
      $api<ResourceResponse<ChatMessage[]>>(`/api/chat/conversations/${id}/messages`, {
        query: before ? { before } : {},
      }),

    /**
     * Отправка. Текст и файлы уходят вместе, одним обращением: сообщение с
     * подписью «вот прайс» и сам прайс — это одно сообщение, а не два.
     */
    sendMessage: (
      id: number,
      body: string,
      files: File[] = [],
      options: UploadOptions = {},
      replyToId: number | null = null,
    ): Promise<ResourceResponse<ChatMessage>> => {
      const form = new FormData()

      if (body) {
        form.append('body', body)
      }

      if (replyToId !== null) {
        form.append('reply_to_id', String(replyToId))
      }

      files.forEach(file => form.append('attachments[]', file))

      return $upload<ResourceResponse<ChatMessage>>(`/api/chat/conversations/${id}/messages`, form, options)
    },

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

    /** Кому можно написать. */
    searchContacts: (search = ''): Promise<ResourceResponse<ChatPerson[]>> =>
      $api<ResourceResponse<ChatPerson[]>>('/api/chat/contacts', { query: search ? { search } : {} }),
  }
}
