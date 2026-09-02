import type { JSONContent } from '@tiptap/core'

export type CourseStatus = 'draft' | 'published' | 'archived'

/**
 * Кому курс виден вообще — вопрос отдельный от того, готов ли он.
 *
 * Приватный курс открыт автору, тем, кого он добавил, и суперадминистратору;
 * для остальных его нет ни в каталоге, ни у консультанта.
 */
export type CourseVisibility = 'public' | 'private'
/**
 * Чем отвечают на вопрос: выбором варианта или своими словами.
 *
 * Письменный ответ проверяет ИИ — сравнивает его с эталоном автора по смыслу.
 */
export type QuestionType = 'single' | 'multiple' | 'text' | 'long_text' | 'table'

/** Столбец таблицы: свободная ячейка или выбор из списка. */
export interface QuestionTableColumn {
  title: string
  kind: 'text' | 'select'
  options: string[]
}

/**
 * Устройство таблицы-вопроса.
 *
 * `row_label_title` пуст — ведущего столбца с подписями нет вовсе (так устроена
 * таблица на двенадцать месяцев). Пустая подпись строки означает, что название
 * вписывает сам сотрудник.
 */
export interface QuestionTable {
  row_label_title: string | null
  columns: QuestionTableColumn[]
  /**
   * Строки таблицы. `expected` — ожидаемые значения по столбцам: где автор их
   * задал, ячейка сверяется, где оставил пустыми — только требуется заполнить.
   * Сотруднику этот список не приходит: это тот же ключ.
   */
  rows: { label: string, expected?: string[] }[]
  can_add_rows: boolean
}

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
  answers?: LessonAnswer[]
  quiz?: Quiz | null
  is_completed?: boolean
  /**
   * Урок, из-за которого этот пока нельзя закрыть: курс проходят по порядку.
   * Приходит с карточки урока; null — путь открыт.
   */
  blocked_by?: LessonLink | null
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

/**
 * Откуда файл: загружен к нам или остался жить на Google Диске.
 *
 * Разница не в способе загрузки, а в том, кто отвечает за доступ: наш файл
 * закрыт подписанной ссылкой, файл с Диска — настройками доступа у Google.
 */
export type AttachmentSource = 'storage' | 'google_drive'

export interface LessonAttachment {
  id: number
  name: string
  description: string | null
  mime_type: string | null
  size: number
  /** False when the signed URL forces a download instead of rendering. */
  opens_inline: boolean
  url: string
  source: AttachmentSource
  /** Адрес для рамки просмотра — только у файла с Google Диска. */
  embed_url: string | null
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
  /**
   * Эталонный ответ письменного вопроса. Приходит только тому, кто правит
   * материал: сотруднику это тот же ключ.
   */
  expected_answer?: string | null
  /** Форма таблицы. Не ключ — без неё заполнять нечего, поэтому едет всем. */
  table?: QuestionTable | null
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
  visibility: CourseVisibility
  visibility_label: string
  is_private: boolean
  /** Может ли этот человек закрыть курс и вести список допущенных. */
  can_manage_access: boolean
  members_count?: number
  published_at: string | null
  cover_url: string | null
  category: Category | null
  lessons_count?: number
  enrollments_count?: number
  author: { id: number, name: string } | null
  /** Кому писать, если написанного в курсе не хватило. */
  experts?: CoursePerson[]
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

/** Что бывает шагом плана обучения. */
export type PlannableKind = 'course' | 'regulation'

/**
 * Материал, который можно поставить шагом плана.
 *
 * Приходит списком целиком, с категорией у каждой строки: план составляют,
 * глядя на то, что есть, а не угадывая название. `is_visible_to_learner`
 * говорит про сотрудника — назначить закрытое от него не запрещено, но знать
 * об этом составитель должен сразу.
 */
export interface PlannableItem {
  kind: PlannableKind
  id: number
  title: string
  slug: string
  category: string | null
  is_visible_to_learner: boolean
}

export interface LearningPlanItem {
  id: number
  position: number
  assigned_at: string | null
  assigned_by?: { id: number, name: string } | null

  /**
   * Шаг приходит плоско: вид, название и адрес. Двух вложенных объектов на
   * выбор здесь нет намеренно — экран плана рисует список одинаковых строк.
   */
  kind: PlannableKind
  item_id: number
  title: string | null
  slug: string | null
  summary: string | null

  progress: number
  is_started: boolean
  is_completed: boolean
  /** Когда шаг был пройден. Пусто у непройденного. */
  completed_at?: string | null

  /**
   * Проверка при документе и то, как её прошёл этот человек. Null — проверки
   * нет; у курса её и не бывает, там тест висит на уроке.
   */
  quiz?: {
    questions: number
    attempts: number
    best_score: number | null
    passed: boolean
    last_at: string | null
  } | null

  /**
   * Увидит ли сотрудник этот шаг у себя. Приходит только тому, кто план
   * составляет: материал могли закрыть уже после назначения.
   */
  is_visible_to_learner?: boolean
}

/** Категория документов. Своё дерево, не общее с учебными категориями. */
export interface RegulationCategory {
  id: number
  name: string
  slug: string
  description: string | null
  position: number
  parent_id: number | null
  children?: RegulationCategory[]
  regulations_count?: number
}

/**
 * Правило, по которому работают. Сам себе урок: ни модулей, ни частей —
 * статья, файлы и отметка «ознакомлен».
 */
export interface Regulation {
  id: number
  title: string
  slug: string
  summary: string | null
  /** Едет только с карточкой одного документа — в каталоге её нет. */
  content_json?: JSONContent | null
  status: CourseStatus
  status_label: string
  is_published: boolean
  published_at: string | null
  visibility: CourseVisibility
  visibility_label: string
  is_private: boolean
  can_manage_access: boolean
  members_count?: number
  category: RegulationCategory | null
  author?: { id: number, name: string } | null
  experts?: CoursePerson[]
  attachments?: LessonAttachment[]
  /**
   * Проверка при документе. Есть — значит ознакомление засчитывается сдачей, а
   * не нажатием кнопки, и кнопки экран не рисует.
   */
  quiz?: Quiz | null
  /** Свои прошлые попытки — история и вход в разбор. */
  own_attempts?: QuizAttempt[]

  /** Весь прогресс, какой у документа бывает. */
  is_acknowledged: boolean
  acknowledged_at: string | null
  acknowledged_count?: number
}

/** Итог отправленной проверки — у документа он же и есть ознакомление. */
export interface QuizOutcome {
  id: number
  score: number
  passed: boolean
  completed_at: string | null
  is_acknowledged: boolean
  review?: QuizReview | null
}

export interface RegulationPayload {
  title: string
  summary: string | null
  content_json: JSONContent | null
  status: CourseStatus
  visibility: CourseVisibility
  category_id: number | null
}

export interface QuizAttempt {
  id: number
  score: number
  passed: boolean
  completed_at: string | null
  /** Разбор — только там, где попытку показывают одну. В списке прошлых нет. */
  review?: QuizReview | null
}

export interface QuizReviewOption {
  id: number
  text: string
  is_chosen: boolean
  /** Null, пока попытки не кончились и тест не сдан: это ключ. */
  is_correct: boolean | null
}

export interface QuizReviewQuestion {
  id: number
  text: string
  type: QuestionType
  points: number
  is_correct: boolean
  is_answered: boolean
  selected_option_ids: number[]
  options: QuizReviewOption[]

  /* Письменный ответ: что человек написал и как это оценено. */
  answer?: string | null
  similarity?: number | null
  threshold?: number | null
  /** Чем измеряли: по смыслу (эмбеддинги) или по пересечению слов. */
  measured_by?: 'meaning' | 'words' | null
  /** Эталон — открывается по тем же правилам, что и ключ у выбора. */
  expected_answer?: string | null

  /* Таблица: как она устроена и как её заполнили. */
  table?: QuestionTable | null
  table_answer?: string[][]
  filled_cells?: number | null
  required_cells?: number | null
  /** Сверяемые ячейки: сколько их, сколько совпало и какие разошлись. */
  checked_cells?: number | null
  correct_cells?: number | null
  wrong_cells?: { row: number, cell: number }[]
}

/** Что человек выбрал и где ошибся. */
export interface QuizReview {
  /** Раскрыты ли верные ответы. */
  reveals_key: boolean
  questions: QuizReviewQuestion[]
}

export interface QuizQuestionStatistics {
  id: number
  text: string
  answered: number
  correct: number
  /** Доля верных среди отвечавших; null — вопрос никто не тронул. */
  correct_share: number | null
  options: { id: number, text: string, is_correct: boolean, chosen: number }[]
  /**
   * Средняя схожесть с эталоном — только у письменного вопроса. По ней видно,
   * не узок ли эталон: верные по смыслу ответы у самой черты — признак того,
   * что дело не в людях.
   */
  average_similarity?: number | null
}

/**
 * Кто проходил тест — со всеми своими попытками.
 *
 * Попытки приложены сразу: их у человека единицы, а разбор каждой спрашивается
 * отдельно — он тяжёлый и нужен не всякой строке.
 */
export interface QuizLearner {
  id: number
  name: string
  passed: boolean
  best_score: number
  attempts: QuizAttempt[]
}

/** Как тест проходят — по первым попыткам каждого. */
export interface QuizStatistics {
  attempts: number
  learners: number
  passed: number
  average_first_score: number | null
  questions: QuizQuestionStatistics[]
  people: QuizLearner[]
}

export interface CoursePayload {
  title: string
  summary: string | null
  description: string | null
  status: CourseStatus
  visibility: CourseVisibility
  category_id: number | null
}

/**
 * Человек на экране доступа: допущенный или найденный поиском.
 *
 * Ровно то, чем один сотрудник отличается от другого на глаз, — прав и
 * должности здесь нет, автору курса они ни к чему.
 */
export interface CoursePerson {
  id: number
  name: string
  email: string
  avatar_url: string | null
  /** Когда открыли доступ. У найденного поиском — нет. */
  granted_at?: string | null
  /** Когда назначили ответственным. То же самое со стороны ответственных. */
  appointed_at?: string | null
  /**
   * Когда человек отметил, что прочитал документ. Есть только в списке
   * ознакомившихся: у документа это весь прогресс, какой бывает.
   */
  acknowledged_at?: string | null
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
    /**
     * Номер уже существующего вопроса. Им вопрос остаётся собой при правке: по
     * номерам разложены ответы прошлых попыток, и пересозданный вопрос стирает
     * из разбора всё, что на него отвечали. У нового вопроса номера нет.
     */
    id?: number | null
    text: string
    type: QuestionType
    points: number
    /** Варианты — только у вопроса с выбором. */
    options: { id?: number | null, text: string, is_correct: boolean }[]
    /** Эталон — только у письменного: с ним сравнивают написанное. */
    expected_answer?: string | null
    /** Форма — только у таблицы. */
    table?: QuestionTable | null
  }[]
}

export interface StatusOption {
  value: CourseStatus
  label: string
}

/** Где в уроке написан ответ. */
export type AnswerSourceKind = 'text' | 'video' | 'attachment'

/** Место внутри урока, куда ведёт ссылка на источник. */
export interface SourceLocation {
  kind: AnswerSourceKind
  /** Готовая подпись: «Видео урока, 12:35». */
  label: string
  seconds: number | null
  page: number | null
  block_id: string | null
  attachment_name: string | null
  attachment_url: string | null
}

/** A lesson the consultant leaned on, so the reader can go and check it. */
export interface ConsultantSource {
  lesson_id: number
  lesson_title: string
  course_title: string
  course_slug: string
  /** Кусок урока или готовый ответ, на котором стоит утверждение. */
  quote: string
  /** Вопрос строки таблицы — есть, только если ответ пришёл оттуда. */
  question: string | null
  location: SourceLocation | null
}

/** Что сотрудник сказал о полученном ответе. */
export type AnswerFeedback = 'helpful' | 'unhelpful'

/** Ответ, дописанный автором после заявки. */
export interface ConsultantResolution {
  answer: string
  answered_at: string | null
  /** Новым остаётся до первой загрузки переписки, где его показали. */
  is_new: boolean
  lesson: {
    lesson_id: number
    lesson_title: string
    course_title: string | null
    course_slug: string | null
  } | null
}

/**
 * Человек, к которому консультант отправляет с вопросом без ответа.
 *
 * Не источник: на него не ссылаются, его не цитируют — ему пишут.
 */
export interface ConsultantExpert {
  user_id: number
  name: string
  email: string
  avatar_url: string | null
  course_title: string
  course_slug: string
}

export interface ConsultantAnswer {
  /**
   * Строка журнала, в которую записан вопрос: по ней ставится оценка и подаётся
   * заявка. Null — журнал был недоступен, оценивать нечего.
   */
  id: number | null
  answer: string
  /** Отдан ли готовый ответ автора как есть, без участия модели. */
  verbatim: boolean
  sources: ConsultantSource[]
  /**
   * Материал по соседству: не то, на чём стоит ответ, а то, что стоит открыть
   * следом. Ручаться за него ответ не может, поэтому и список отдельный.
   */
  related: ConsultantSource[]
  /** К кому идти, если ответа не нашлось. Пусто, когда ответ есть. */
  experts: ConsultantExpert[]
}

/** Заданный когда-то вопрос вместе с ответом — как он лежит в истории. */
export interface ConsultantExchange {
  id: number
  question: string
  answer: ConsultantAnswer
  /** Помог ли ответ, по словам самого спрашивавшего. */
  feedback: AnswerFeedback | null
  /** Подана ли заявка на дополнение. */
  requested: boolean
  /** Дописанный автором ответ — есть, только если заявку уже закрыли. */
  resolution?: ConsultantResolution
  asked_at: string | null
}

/** Строка таблицы урока: вопрос, ответ и место, где он написан. */
export interface LessonAnswer {
  id: number
  position: number
  question: string
  answer: string
  source_kind: AnswerSourceKind
  source_attachment_id: number | null
  source_seconds: number | null
  source_page: number | null
  source_block_id: string | null
  /** Указывает ли строка ещё на существующее место. */
  source_is_live: boolean
  /**
   * Посчитаны ли векторы: пока нет, смысловой поиск строку не находит.
   *
   * Отсутствует, когда смыслового поиска нет вовсе — без модели эмбеддингов
   * векторов не будет никогда, и говорить об ожидании нечего.
   */
  is_indexed?: boolean
}

/** Что редактор шлёт: таблицу целиком, взамен той, что была. */
export interface LessonAnswerPayload {
  question: string
  answer: string
  source_kind: AnswerSourceKind
  source_attachment_id?: number | null
  source_seconds?: number | null
  source_page?: number | null
  source_block_id?: string | null
}

/**
 * Черновик, предложенный моделью. Ничего не сохранено, пока автор не утвердит.
 *
 * Источник приходит готовым: он выведен из расшифровки, откуда взят вопрос, —
 * автору не остаётся ничего указывать руками.
 */
export interface SuggestedAnswer {
  question: string
  answer: string
  source_kind: AnswerSourceKind
  source_attachment_id: number | null
  source_seconds: number | null
  source_page: number | null
  source_block_id: string | null
}

/**
 * Расшифровка одной единицы содержания урока: записи, файла или блока статьи.
 *
 * Читателю не видна — это не часть материала, а то, чем материал становится
 * доступен консультанту.
 */
export interface LessonTranscript {
  id: number
  source_kind: AnswerSourceKind
  source_attachment_id: number | null
  source_block_id: string | null
  /** Выведена из текста блока, а не загружена: такую нельзя удалить. */
  is_derived: boolean
  original_name: string | null
  format: 'srt' | 'vtt' | 'timed' | 'plain' | null
  /** Текст как его вставил автор — то, что он показывает и правит. */
  content: string | null
  characters: number
  segments_count: number
  updated_at: string | null
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
