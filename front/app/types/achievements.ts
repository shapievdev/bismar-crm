/**
 * Достижения — значки, которые шаги адаптации раздают сами.
 *
 * Три вида одного и того же, и они намеренно разные по весу: в строке этапа
 * едет значок (картинка и название), в каталоге — всё поле, в профиле — ещё и
 * то, когда и за что он получен.
 */

/** Значок при шаге маршрута: минимум, чтобы его нарисовать. */
export interface AchievementBadge {
  id: number
  title: string
  image_url: string | null
}

/** Строка каталога — экран администратора. */
export interface Achievement extends AchievementBadge {
  description: string | null
  position: number
  /** Спрятано: из выбора ушло, в чужих профилях осталось. */
  is_hidden: boolean
  people_count?: number
  author?: { id: number, name: string } | null
}

/** Заслуга в профиле: значок плюс день и причина. */
export interface AwardedAchievement extends AchievementBadge {
  description: string | null
  awarded_at: string | null
  reason: string | null
}

export interface AchievementPayload {
  title: string
  description?: string | null
  position?: number | null
}
