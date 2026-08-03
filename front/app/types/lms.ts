export type CourseStatus = 'draft' | 'published' | 'archived'
export type QuestionType = 'single' | 'multiple'

export interface LessonSummary {
  id: number
  title: string
  slug: string
  video_url: string | null
  video_upload_url?: string | null
  video_name?: string | null
  video_size?: number | null
  duration_minutes: number | null
  position: number
  has_quiz?: boolean
  /** Only the lesson endpoint returns the body. */
  content?: string | null
  content_json?: Record<string, unknown> | null
  attachments?: LessonAttachment[]
  quiz?: Quiz | null
  is_completed?: boolean
  /** Present on the lesson endpoint: what to show as previous/next. */
  neighbours?: { previous: LessonLink | null, next: LessonLink | null }
  course_title?: string
  course_slug?: string
  own_attempts?: QuizAttempt[]
}

export interface Category {
  id: number
  name: string
  slug: string
  description: string | null
  position: number
  parent_id: number | null
  children?: Category[]
  courses_count?: number
}

export interface CategoryPayload {
  name: string
  description: string | null
  parent_id?: number | null
  position?: number
}

export interface LessonLink {
  id: number
  title: string
}

export interface LessonAttachment {
  id: number
  name: string
  description: string | null
  mime_type: string | null
  size: number
  /** False when the signed URL forces a download instead of rendering. */
  opens_inline: boolean
  url: string
}

export interface QuizOption {
  id: number
  text: string
  position: number
  /** Present only for users who may edit the course. */
  is_correct?: boolean
}

export interface QuizQuestion {
  id: number
  text: string
  type: QuestionType
  points: number
  position: number
  options: QuizOption[]
}

export interface Quiz {
  id: number
  title: string
  description: string | null
  passing_score: number
  max_attempts: number | null
  questions?: QuizQuestion[]
}

export interface CourseModule {
  id: number
  title: string
  description: string | null
  position: number
  lessons?: LessonSummary[]
}

export interface LearnerEnrollment {
  id: number
  enrolled_at: string | null
  completed_at: string | null
  is_completed: boolean
  progress: number
  completed_lesson_ids: number[]
}

export interface Course {
  id: number
  title: string
  slug: string
  summary: string | null
  description: string | null
  status: CourseStatus
  status_label: string
  published_at: string | null
  cover_url: string | null
  category: Category | null
  lessons_count?: number
  enrollments_count?: number
  author: { id: number, name: string } | null
  modules?: CourseModule[]
  enrollment: LearnerEnrollment | null
}

export interface Enrollment {
  id: number
  enrolled_at: string | null
  completed_at: string | null
  is_completed: boolean
  progress: number | null
  completed_lesson_ids?: number[]
  course?: Course
}

export interface QuizAttempt {
  id: number
  score: number
  passed: boolean
  completed_at: string | null
}

export interface CoursePayload {
  title: string
  summary: string | null
  description: string | null
  status: CourseStatus
  category_id: number | null
}

export interface ModulePayload {
  title: string
  description: string | null
  position?: number
}

export interface LessonPayload {
  title: string
  content: string | null
  content_json?: Record<string, unknown> | null
  video_url: string | null
  video_upload_url?: string | null
  video_name?: string | null
  video_size?: number | null
  duration_minutes: number | null
  position?: number
}

/** What the quiz editor sends: the whole quiz, replacing whatever was there. */
export interface QuizPayload {
  title: string
  description: string | null
  passing_score: number
  max_attempts: number | null
  questions: {
    text: string
    type: QuestionType
    points: number
    options: { text: string, is_correct: boolean }[]
  }[]
}

export interface StatusOption {
  value: CourseStatus
  label: string
}

/** Laravel's paginated collection envelope. */
export interface PaginatedResponse<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}
