import type { AwardedAchievement, AchievementBadge } from '~/types/achievements'
import type { Quiz, QuizAttempt } from '~/types/lms'

/**
 * Адаптация стажёра: карта этапов по дням.
 *
 * Статусы и проценты считает сервер (StageRequirements) — фронт только рисует:
 * правило «этап пройден» одно, и второго, на экране, быть не должно.
 */

export type KitIcon = 'access' | 'shirt' | 'barcode' | 'badge' | 'key' | 'book' | 'tool' | 'device' | 'bag' | 'star'
export interface KitDefinition { id: string, label: string, icon: KitIcon, kind: 'item' | 'admission', is_required: boolean }
export interface KitItem extends KitDefinition { confirmed_at: string | null, confirmed_by: number | null, confirmed_by_name: string | null }

export interface RouteSettings {
  notifications_enabled: boolean
  passed_message: string
  failed_message: string
  completed_message: string
  stage_notification: string
  status_labels: Record<StageStatus, string>
}

export interface RouteActivity { id: number, event: string, subject: string, actor_name: string | null, created_at: string, changes: string | null }

export type JourneyStatus = 'active' | 'paused' | 'completed'
export type ScheduleAnchor = 'hired' | 'date' | 'today' | 'none'
export type StageStatus = 'locked' | 'available' | 'in_progress' | 'completed' | 'quiz_failed'
export type TemplateStatus = 'draft' | 'published' | 'archived'
export type DayMode = 'calendar' | 'working'

/** Требование этапа: материал, встреча, задание или тест. */
export interface StageItem {
  kind: 'material' | 'meeting' | 'task' | 'quiz'
  key: string
  title: string
  is_required: boolean
  done: boolean
  done_at: string | null
  url: string | null
  meta: {
    id?: number
    quiz_id?: number
    with?: { id: number, name: string, job_title: string | null } | null
    format?: string
    format_label?: string
    place?: string | null
    scheduled_at?: string | null
    reschedules_count?: number
    confirm_by?: string
    submitted_at?: string | null
    awaiting_confirmation?: boolean
    attempts?: QuizAttempt[]
    attempts_remaining?: number | null
    attempts_spent?: number
    attempts_allowed?: number | null
    awaiting_review?: boolean
    best_score?: number | null
    best_score_others?: number | null
    /** День, к которому материал стоит изучить, — срок на виду. */
    day_offset?: number | null
    /** Вид и номер материала — ими редактор узнаёт его в каталоге. */
    material_type?: 'course' | 'document' | 'handbook'
    material_id?: number
    /** Значок за это дело: обещание на карте, заслуга после закрытия. */
    achievement?: AchievementBadge | null
  }
}

export interface JourneyStage {
  is_final_exam: boolean
  due_at: string | null
  responsible_ids: number[]
  responsibles: { id: number, name: string, job_title: string | null }[]
  template_title: string | null
  template_version: number | null
  id: number
  position: number
  title: string
  description: string | null
  /** Null — дни в этом маршруте не считают. */
  day_offset: number | null
  opens_at: string | null
  completed_at: string | null
  /** Снят с шаблона или придуман для этого человека. */
  from_template: boolean
  status: StageStatus
  status_label: string
  status_icon: string
  progress: number
  /** Значок за весь этап и значок за его тест. */
  achievement_id: number | null
  achievement: AchievementBadge | null
  quiz_achievement_id: number | null
  quiz_achievement: AchievementBadge | null
  items: StageItem[]
  /** Только в ответах ведущему. */
  extra_attempts?: number
  /** Тест этапа с вопросами — ведущему, он его и правит. */
  quiz?: Quiz | null
}

export interface NextAction {
  kind: string
  label: string
  url: string | null
  journey_stage_id: number | null
}

export interface Journey {
  kit_items: KitDefinition[]
  settings: RouteSettings
  requires_graduation: boolean
  day_mode: DayMode
  store: string | null
  meeting_reminder_minutes: number | null
  achievements: AwardedAchievement[]
  history: RouteActivity[]
  id: number
  status: JourneyStatus
  status_label: string
  started_at: string | null
  current_day: number | null
  total_days: number | null
  employee: { id: number, name: string }
  allow_ahead: boolean
  progress: { completed: number, total: number, percent: number }
  graduation: {
    items: KitItem[]
    access_confirmed_at: string | null
    tshirt_issued_at: string | null
    barcode_issued_at: string | null
    fully_equipped: boolean
  }
  completed_at: string | null
  next_action: NextAction | null
  stages: JourneyStage[]
}

/** Встреча, которую проводит вошедший: его очередь подтверждений. */
export interface HostedMeeting {
  id: number
  topic: string
  format: string
  format_label: string
  place: string | null
  scheduled_at: string | null
  employee: { id: number, name: string }
  journey_id: number
}

/* ---------- Ведение ---------- */

export interface TemplateStageLink {
  id: number
  type: 'course' | 'document' | 'handbook'
  material_id: number
  title: string
  url: string | null
  is_required: boolean
  /** День, к которому материал стоит изучить. */
  day_offset: number | null
  achievement_id: number | null
  achievement: AchievementBadge | null
}

export interface TemplateStageMeeting {
  id: number
  topic: string
  format: string
  place: string | null
  with_user_id: number | null
  with_name: string | null
  with_role: string | null
  achievement_id: number | null
  achievement: AchievementBadge | null
}

export interface TemplateStageTask {
  id: number
  text: string
  confirm_by: 'self' | 'responsible'
  is_required: boolean
  achievement_id: number | null
  achievement: AchievementBadge | null
}

export interface TemplateStage {
  is_final_exam: boolean
  responsible_ids: number[]
  id: number
  position: number
  day_offset: number
  title: string
  description: string | null
  achievement_id: number | null
  achievement: AchievementBadge | null
  quiz_achievement_id: number | null
  quiz_achievement: AchievementBadge | null
  links: TemplateStageLink[]
  meetings: TemplateStageMeeting[]
  tasks: TemplateStageTask[]
  quiz: Quiz | null
}

export interface OnboardingTemplate {
  kit_items: KitDefinition[]
  history?: RouteActivity[]
  version: number
  parent_id: number | null
  job_title: string | null
  store: string | null
  settings: RouteSettings
  requires_graduation: boolean
  graduation_achievement_id: number | null
  id: number
  title: string
  slug: string
  description: string | null
  status: TemplateStatus
  status_label: string
  duration_days: number
  day_mode: DayMode
  day_mode_label: string
  allow_ahead: boolean
  meeting_reminder_minutes: number | null
  author?: { id: number, name: string } | null
  stages_count?: number
  journeys_count?: number
  stages?: TemplateStage[]
}

/** Строка каталога материалов (PlannableMaterial). */
export interface PlannableRow {
  kind: 'course' | 'document' | 'handbook'
  id: number
  title: string
  slug: string
  category?: string | null
  is_visible_to_learner?: boolean
}

/** Строка списка сотрудников: человек и его маршрут, возможно пустой. */
export interface JourneySummary {
  employee: { id: number, name: string, job_title: string | null }
  journey_id: number | null
  status: JourneyStatus | null
  status_label: string | null
  started_at: string | null
  current_day: number | null
  progress: { completed: number, total: number, percent: number }
  completed_at: string | null
  fully_equipped: boolean
}

export interface OnboardingReportData {
  started: number
  completed: number
  in_progress: number
  completion_rate: number | null
  average_days: number | null
  average_days_to_access: number | null
  exam_first_try_rate: number | null
  exam_average_attempts: number | null
  exam_attempts: number
  slowest_stages: { title: string, average_days: number, average_delay_days: number, journeys: number }[]
}

export interface StagePayload {
  is_final_exam?: boolean
  responsible_ids?: number[]
  id?: number | null
  title: string
  description?: string | null
  day_offset: number
  opens_at?: string | null
  position?: number | null
  achievement_id?: number | null
  quiz_achievement_id?: number | null
  links: { type: string, id: number, is_required?: boolean, day_offset?: number | null, achievement_id?: number | null }[]
  meetings: {
    id?: number | null
    topic: string
    format: string
    place?: string | null
    with_user_id?: number | null
    with_role?: string | null
    achievement_id?: number | null
  }[]
  tasks: { id?: number | null, text: string, confirm_by: string, is_required?: boolean, achievement_id?: number | null }[]
}

/**
 * Этап личного маршрута при сохранении: день необязателен, роли нет, зато у
 * встречи есть время — назначают его здесь же, вместе с этапом.
 */
export interface JourneyStagePayload {
  is_final_exam?: boolean
  responsible_ids?: number[]
  due_at?: string | null
  id?: number | null
  title: string
  description?: string | null
  day_offset?: number | null
  opens_at?: string | null
  position?: number | null
  achievement_id?: number | null
  quiz_achievement_id?: number | null
  links: { type: string, id: number, is_required?: boolean, day_offset?: number | null, achievement_id?: number | null }[]
  meetings: {
    id?: number | null
    topic: string
    format: string
    place?: string | null
    /** ISO со смещением; null — «время уточняется». */
    scheduled_at?: string | null
    with_user_id?: number | null
    achievement_id?: number | null
  }[]
  tasks: { id?: number | null, text: string, confirm_by: string, is_required?: boolean, achievement_id?: number | null }[]
}

export type { Quiz, QuizAttempt }
