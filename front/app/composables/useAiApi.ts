import type { ResourceResponse } from '~/types/auth'
import type {
  AiSettings,
  AiSettingsPayload,
  ConsultantQuestion,
  QuestionLogQuery,
  QuestionLogResponse,
  ResolveQuestionPayload,
} from '~/types/ai'

/**
 * Настройки консультанта. Доступны только суперадминистратору — сервер решает
 * это сам, страница лишь не показывает то, чего нельзя открыть.
 */
export function useAiApi() {
  const { $api } = useNuxtApp()

  return {
    fetchSettings: (): Promise<ResourceResponse<AiSettings>> =>
      $api<ResourceResponse<AiSettings>>('/api/ai/settings'),

    saveSettings: (body: AiSettingsPayload): Promise<ResourceResponse<AiSettings>> =>
      $api<ResourceResponse<AiSettings>>('/api/ai/settings', { method: 'PUT', body }),

    /**
     * Проверить связь — тем, что набрано в форме, а не тем, что сохранено.
     *
     * Иначе выходила ловушка: вставил свежий ключ, нажал «Проверить» — и получил
     * отказ по старому, потому что новый ещё не сохранён. Непришедшее поле
     * сервер берёт из сохранённого; пустой ключ значит «проверь тем, что в базе»
     * — форма его и не показывает.
     */
    testConnection: (body: Partial<AiSettingsPayload> = {}): Promise<{ message: string }> =>
      $api<{ message: string }>('/api/ai/settings/test', { method: 'POST', body }),

    // Журнал открыт авторам курсов, а не только суперадминистратору: пробел в
    // базе закрывают они.
    fetchQuestions: (query: QuestionLogQuery = {}): Promise<QuestionLogResponse> =>
      $api<QuestionLogResponse>('/api/ai/questions', { query }),

    /**
     * Ответ на заданный вопрос: строкой в урок и обратно в тот разговор, где
     * вопрос был задан.
     */
    resolveQuestion: (
      questionId: number,
      body: ResolveQuestionPayload,
    ): Promise<ResourceResponse<ConsultantQuestion>> =>
      $api<ResourceResponse<ConsultantQuestion>>(`/api/ai/questions/${questionId}/answer`, {
        method: 'POST',
        body,
      }),

    /**
     * Убирает вопрос из журнала совсем — вместе с перепиской того, кто его
     * задал. Для случайных нажатий и проверок «а что ты умеешь», которым в
     * перечне пробелов не место.
     */
    deleteQuestion: (questionId: number) =>
      $api(`/api/ai/questions/${questionId}`, { method: 'DELETE' }),
  }
}
