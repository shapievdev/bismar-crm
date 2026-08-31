/** Кем человек числится в отделе. */
export type DepartmentRoleKind = 'head' | 'deputy' | 'member'

/**
 * Человек так, как его показывают в списках людей: карточка отдела, состав
 * группы, подсказка поиска. Ни прав, ни почты — лицо с именем.
 */
export interface Person {
  id: number
  name: string
  short_name: string
  job_title: string | null
  avatar_url: string | null
}

/**
 * Человек в отделе. Роли нет у того, кого ещё только предлагают добавить:
 * до отдела он в нём никем не числится.
 */
export interface DepartmentPerson extends Person {
  role: DepartmentRoleKind | null
  role_label: string | null
}

/**
 * Группа сотрудников — список людей, собранный вручную.
 *
 * Отдел говорит, где человек работает, группа — кого зовут вместе. Дерева не
 * образует и прав не даёт: это адресат рассылки и новости. Состав приходит
 * только в карточке: в списке из тридцати групп хватает числа людей.
 */
export interface Group {
  id: number
  name: string
  description: string | null
  people_count: number
  people?: Person[]
}

export interface GroupPayload {
  name: string
  description: string | null
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

/** Кому уходит рассылка уведомлений. */
export type BroadcastAudienceKind = 'everyone' | 'selected' | 'department' | 'group'

/** Отправленная рассылка в истории. */
export interface Broadcast {
  id: number
  title: string
  body: string
  url: string | null
  audience: BroadcastAudienceKind
  audience_label: string
  department?: string | null
  /** Название группы. Пусто и у прочих рассылок, и у удалённой с тех пор группы. */
  group?: string | null
  /** Снимком на день отправки: и люди, и подписки с тех пор могли измениться. */
  recipients_count: number
  devices_count: number
  author?: string | null
  sent_at: string | null
}

export interface BroadcastPayload {
  title: string
  body: string
  url: string | null
  audience: BroadcastAudienceKind
  user_ids?: number[]
  department_id?: number | null
  group_id?: number | null
}

/** Куда бросили карточку: на отдел — подчинить, в промежуток — переставить. */
export interface DepartmentDropTarget {
  parentId: number
  position: number
}
