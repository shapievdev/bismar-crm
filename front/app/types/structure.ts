/** Кем человек числится в отделе. */
export type DepartmentRoleKind = 'head' | 'deputy' | 'member'

/**
 * Человек в отделе. Роли нет у того, кого ещё только предлагают добавить:
 * до отдела он в нём никем не числится.
 */
export interface DepartmentPerson {
  id: number
  name: string
  short_name: string
  job_title: string | null
  avatar_url: string | null
  role: DepartmentRoleKind | null
  role_label: string | null
}

/**
 * Узел структуры — всё, что написано на карточке отдела.
 *
 * `members_count` — прямые сотрудники («Подчинённые: N»), `people_total` —
 * весь куст вместе с вложенными отделами: это число стоит рядом с именем
 * руководителя.
 */
export interface Department {
  id: number
  name: string
  parent_id: number | null
  position: number
  is_root: boolean
  heads: DepartmentPerson[]
  deputies: DepartmentPerson[]
  /** Несколько лиц подчинённых для карточки; весь состав — в панели. */
  members: DepartmentPerson[]
  members_count: number
  people_total: number
  children_count: number
  children: Department[]
}

/**
 * Человек, которого тащат с карточки или из панели.
 *
 * Роль едет вместе с ним: перетащив заместителя в соседний отдел, его туда и
 * ставят заместителем, а не понижают молча до сотрудника.
 */
export interface DraggedPerson {
  id: number
  name: string
  role: DepartmentRoleKind
  fromDepartmentId: number
}

/** Куда бросили карточку: на отдел — подчинить, в промежуток — переставить. */
export interface DepartmentDropTarget {
  parentId: number
  position: number
}
