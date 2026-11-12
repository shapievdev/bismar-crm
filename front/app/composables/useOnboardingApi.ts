import { toValidationError } from '~/composables/useAuth'
import type { ResourceResponse } from '~/types/auth'
import type { Quiz, QuizAttempt, QuizPayload } from '~/types/lms'
import type {
  HostedMeeting,
  Journey,
  JourneyStagePayload,
  JourneySummary,
  OnboardingReportData,
  OnboardingTemplate,
  PlannableRow,
  ScheduleAnchor,
  StagePayload,
  TemplateStage,
  TemplateStatus,
} from '~/types/onboarding'

/**
 * Адаптация: личные маршруты сотрудников.
 *
 * Сотрудник и ответственные ходят по `/api/onboarding` — маршрут и так свой.
 * Ведение — `/api/onboarding/manage` под `onboarding.manage`, и адреса там
 * идут от сотрудника: назначений нет, есть карта, которую наполняют.
 */
export function useOnboardingApi() {
  const { $api } = useNuxtApp()

  return {
    /* ---------- Стажёр и ответственные ---------- */

    fetchEmployeeAttempt: (employeeId: number, id: number): Promise<ResourceResponse<QuizAttempt>> => $api(`/api/onboarding/manage/employees/${employeeId}/attempts/${id}`),
    fetchAttempt: (id: number): Promise<ResourceResponse<QuizAttempt>> => $api(`/api/onboarding/attempts/${id}`),
    updateJourneySettings: (id: number, body: Record<string, unknown>): Promise<{ data: Journey }> => $api(`/api/onboarding/manage/employees/${id}/settings`, { method: 'PUT', body }),
    fetchAssignedStages: (): Promise<{ data: { employee: { id: number, name: string }, stage: import('~/types/onboarding').JourneyStage }[] }> => $api('/api/onboarding/assigned/stages'),
    fetchAssignedAttempt: (id: number): Promise<ResourceResponse<QuizAttempt>> => $api(`/api/onboarding/assigned/attempts/${id}`),
    fetchMyTasks: (): Promise<{ data: { id: number, text: string, submitted_at: string | null, employee: { id: number, name: string } }[] }> => $api('/api/onboarding/tasks/mine'),
    fetchMy: (): Promise<{ data: Journey }> =>
      $api<{ data: Journey }>('/api/onboarding/my'),

    /** Значок на рельсе: есть ли маршрут и ждут ли встречи-поручения. */
    fetchBadge: (): Promise<{ data: { has_journey: boolean, pending_meetings: number, has_assignments: boolean } }> =>
      $api<{ data: { has_journey: boolean, pending_meetings: number, has_assignments: boolean } }>('/api/onboarding/badge'),

    submitTask: (taskId: number): Promise<{ data: Journey }> =>
      $api<{ data: Journey }>(`/api/onboarding/tasks/${taskId}/submit`, { method: 'POST' }),

    /** Вопросы открытого этапа — ключ спрятан сервером. */
    fetchStageQuiz: (stageId: number): Promise<ResourceResponse<Quiz>> =>
      $api<ResourceResponse<Quiz>>(`/api/onboarding/stages/${stageId}/quiz`),

    submitStageQuiz: (
      stageId: number,
      answers: Record<number, number[] | string | string[][]>,
    ): Promise<ResourceResponse<QuizAttempt>> =>
      $api<ResourceResponse<QuizAttempt>>(`/api/onboarding/stages/${stageId}/quiz/submit`, {
        method: 'POST',
        body: { answers },
      }),

    /** Встречи-поручения ответственного и подтверждения. */
    fetchMyMeetings: (): Promise<{ data: HostedMeeting[] }> =>
      $api('/api/onboarding/meetings/mine'),

    confirmMeeting: (meetingId: number) =>
      $api(`/api/onboarding/meetings/${meetingId}/confirm`, { method: 'POST' }),

    confirmTask: (taskId: number) =>
      $api(`/api/onboarding/tasks/${taskId}/confirm`, { method: 'POST' }),

    /* ---------- Шаблоны ---------- */

    fetchTemplates: (): Promise<{ data: OnboardingTemplate[] }> =>
      $api<{ data: OnboardingTemplate[] }>('/api/onboarding/manage/templates'),

    createTemplate: (payload: { title: string }): Promise<ResourceResponse<OnboardingTemplate>> =>
      $api<ResourceResponse<OnboardingTemplate>>('/api/onboarding/manage/templates', {
        method: 'POST',
        body: payload,
      }).catch(toValidationError),

    fetchTemplate: (slug: string): Promise<ResourceResponse<OnboardingTemplate>> =>
      $api<ResourceResponse<OnboardingTemplate>>(`/api/onboarding/manage/templates/${slug}`),

    updateTemplate: (slug: string, payload: Record<string, unknown>): Promise<ResourceResponse<OnboardingTemplate>> =>
      $api<ResourceResponse<OnboardingTemplate>>(`/api/onboarding/manage/templates/${slug}`, {
        method: 'PUT',
        body: payload,
      }).catch(toValidationError),

    deleteTemplate: (slug: string) =>
      $api(`/api/onboarding/manage/templates/${slug}`, { method: 'DELETE' }),

    duplicateTemplate: (slug: string): Promise<ResourceResponse<OnboardingTemplate>> =>
      $api<ResourceResponse<OnboardingTemplate>>(`/api/onboarding/manage/templates/${slug}/duplicate`, { method: 'POST' }),

    changeTemplateStatus: (slug: string, status: TemplateStatus): Promise<ResourceResponse<OnboardingTemplate>> =>
      $api<ResourceResponse<OnboardingTemplate>>(`/api/onboarding/manage/templates/${slug}/status`, {
        method: 'POST',
        body: { status },
      }).catch(toValidationError),

    saveStage: (slug: string, payload: StagePayload): Promise<{ data: TemplateStage }> =>
      $api<{ data: TemplateStage }>(`/api/onboarding/manage/templates/${slug}/stages`, {
        method: 'POST',
        body: payload,
      }).catch(toValidationError),

    deleteStage: (slug: string, stageId: number) =>
      $api(`/api/onboarding/manage/templates/${slug}/stages/${stageId}`, { method: 'DELETE' }),

    reorderStages: (slug: string, stageIds: number[]) =>
      $api(`/api/onboarding/manage/templates/${slug}/stages/reorder`, {
        method: 'POST',
        body: { stages: stageIds },
      }),

    saveStageQuiz: (slug: string, stageId: number, payload: QuizPayload): Promise<ResourceResponse<Quiz>> =>
      $api<ResourceResponse<Quiz>>(`/api/onboarding/manage/templates/${slug}/stages/${stageId}/quiz`, {
        method: 'PUT',
        body: payload,
      }).catch(toValidationError),

    deleteStageQuiz: (slug: string, stageId: number) =>
      $api(`/api/onboarding/manage/templates/${slug}/stages/${stageId}/quiz`, { method: 'DELETE' }),

    /** Каталог «что можно поставить материалом этапа». */
    fetchMaterials: (): Promise<{ data: PlannableRow[] }> =>
      $api<{ data: PlannableRow[] }>('/api/onboarding/manage/materials'),

    /* ---------- Маршруты сотрудников ---------- */

    /** Сотрудники и их маршруты — с поиском по фамилии. */
    fetchJourneys: (search = ''): Promise<{ data: JourneySummary[] }> =>
      $api<{ data: JourneySummary[] }>('/api/onboarding/manage/journeys', { query: { search } }),

    /** Кого можно найти — поиском по работающим. */
    searchPeople: (search: string): Promise<{ data: { id: number, name: string }[] }> =>
      $api<{ data: { id: number, name: string }[] }>('/api/onboarding/manage/people', { query: { search } }),

    /** Маршрут сотрудника. Пустая карта заводится самим открытием. */
    fetchEmployeeJourney: (employeeId: number): Promise<{ data: Journey }> =>
      $api<{ data: Journey }>(`/api/onboarding/manage/employees/${employeeId}`),

    /** Что из шаблона будет закрыто этому человеку — до наложения. */
    checkTemplateVisibility: (employeeId: number, templateSlug: string): Promise<{ data: { hidden: {
      stage_title: string
      day_offset: number | null
      title: string
      is_required: boolean
    }[] } }> =>
      $api(`/api/onboarding/manage/employees/${employeeId}/templates/${templateSlug}/visibility`),

    /** Наложить шаблон: его этапы дописываются в конец маршрута. */
    applyTemplate: (
      employeeId: number,
      templateSlug: string,
      payload: { anchor: ScheduleAnchor, date?: string | null },
    ): Promise<{ data: Journey }> =>
      $api<{ data: Journey }>(`/api/onboarding/manage/employees/${employeeId}/templates/${templateSlug}`, {
        method: 'POST',
        body: payload,
      }).catch(toValidationError),

    /* ---------- Этапы личного маршрута ---------- */

    saveJourneyStage: (employeeId: number, payload: JourneyStagePayload): Promise<{ data: Journey }> =>
      $api<{ data: Journey }>(`/api/onboarding/manage/employees/${employeeId}/stages`, {
        method: 'POST',
        body: payload,
      }).catch(toValidationError),

    deleteJourneyStage: (employeeId: number, stageId: number): Promise<{ data: Journey }> =>
      $api<{ data: Journey }>(`/api/onboarding/manage/employees/${employeeId}/stages/${stageId}`, { method: 'DELETE' })
        .catch(toValidationError),

    reorderJourneyStages: (employeeId: number, stageIds: number[]): Promise<{ data: Journey }> =>
      $api<{ data: Journey }>(`/api/onboarding/manage/employees/${employeeId}/stages/reorder`, {
        method: 'POST',
        body: { stages: stageIds },
      }),

    saveJourneyStageQuiz: (employeeId: number, stageId: number, payload: QuizPayload): Promise<ResourceResponse<Quiz>> =>
      $api<ResourceResponse<Quiz>>(`/api/onboarding/manage/employees/${employeeId}/stages/${stageId}/quiz`, {
        method: 'PUT',
        body: payload,
      }).catch(toValidationError),

    deleteJourneyStageQuiz: (employeeId: number, stageId: number) =>
      $api(`/api/onboarding/manage/employees/${employeeId}/stages/${stageId}/quiz`, { method: 'DELETE' }),

    /* ---------- Ход маршрута ---------- */

    pauseJourney: (employeeId: number): Promise<{ data: Journey }> =>
      $api<{ data: Journey }>(`/api/onboarding/manage/employees/${employeeId}/pause`, { method: 'POST' })
        .catch(toValidationError),

    resumeJourney: (employeeId: number): Promise<{ data: Journey }> =>
      $api<{ data: Journey }>(`/api/onboarding/manage/employees/${employeeId}/resume`, { method: 'POST' })
        .catch(toValidationError),

    /** Перенос и досрочное открытие: пустая дата — открыть сейчас. */
    moveStage: (employeeId: number, stageId: number, opensAt: string | null): Promise<{ data: Journey }> =>
      $api<{ data: Journey }>(`/api/onboarding/manage/employees/${employeeId}/stages/${stageId}/opening`, {
        method: 'PUT',
        body: { opens_at: opensAt },
      }).catch(toValidationError),

    scheduleMeeting: (
      employeeId: number,
      meetingId: number,
      payload: { scheduled_at: string, place?: string | null, with_user_id?: number | null },
    ): Promise<{ data: Journey }> =>
      $api<{ data: Journey }>(`/api/onboarding/manage/employees/${employeeId}/meetings/${meetingId}`, {
        method: 'PUT',
        body: payload,
      }).catch(toValidationError),

    confirmJourneyMeeting: (employeeId: number, meetingId: number): Promise<{ data: Journey }> =>
      $api<{ data: Journey }>(`/api/onboarding/manage/employees/${employeeId}/meetings/${meetingId}/confirm`, { method: 'POST' }),

    confirmJourneyTask: (employeeId: number, taskId: number): Promise<{ data: Journey }> =>
      $api<{ data: Journey }>(`/api/onboarding/manage/employees/${employeeId}/tasks/${taskId}/confirm`, { method: 'POST' }),

    grantAttempt: (employeeId: number, stageId: number): Promise<{ data: Journey }> =>
      $api<{ data: Journey }>(`/api/onboarding/manage/employees/${employeeId}/stages/${stageId}/grant-attempt`, { method: 'POST' })
        .catch(toValidationError),

    graduate: (employeeId: number, what: string): Promise<{ data: Journey }> =>
      $api<{ data: Journey }>(`/api/onboarding/manage/employees/${employeeId}/graduate`, {
        method: 'POST',
        body: { what },
      }).catch(toValidationError),

    /* ---------- Отчёт ---------- */

    fetchReport: (filters: Record<string, string | number | undefined>): Promise<{ data: OnboardingReportData }> =>
      $api<{ data: OnboardingReportData }>('/api/onboarding/manage/report', { query: filters }),
  }
}
