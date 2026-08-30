import type { InjectionKey, Ref } from 'vue'
import type { Department, DraggedPerson } from '~/types/structure'

/**
 * Общее состояние экрана структуры: что раскрыто, что выбрано, что тащат.
 *
 * Дерево рисуется само себя вызывающим компонентом, и передавать всё это
 * пропсами через каждый уровень значило бы протаскивать десяток свойств от
 * корня до листа. Страница кладёт состояние сюда, узлы берут его отсюда.
 */
export interface StructureContext {
  /** Можно ли перерисовывать структуру: у остальных она только читается. */
  editable: Ref<boolean>

  /** Отделы, в которых числится смотрящий, — на них метка «ваш отдел». */
  ownDepartmentIds: Ref<number[]>

  expandedIds: Ref<number[]>
  selectedId: Ref<number | null>

  /** Какую карточку тащат сейчас; null — не тащат ничего. */
  draggingId: Ref<number | null>

  /** Куда её бросят, если отпустить: «card:12» или «slot:12:3». */
  dropHint: Ref<string | null>

  toggle: (id: number) => void
  select: (id: number) => void

  addChild: (parent: Department) => void
  rename: (department: Department) => void
  remove: (department: Department) => void

  startDrag: (id: number) => void
  endDrag: () => void

  /** Можно ли подчинить тащимую карточку этому отделу. */
  mayDropOn: (id: number) => boolean

  dropOn: (id: number) => void
  dropAt: (parentId: number, position: number) => void

  /** Человек, которого тащат сейчас; null — не тащат никого. */
  draggingPerson: Ref<DraggedPerson | null>

  startPersonDrag: (person: DraggedPerson) => void
  endPersonDrag: () => void

  /** Можно ли перенести тащимого человека в этот отдел. */
  mayDropPersonOn: (departmentId: number) => boolean

  dropPersonOn: (departmentId: number) => void
}

export const structureKey: InjectionKey<StructureContext> = Symbol('structure')

/**
 * Состояние экрана для узла дерева. Вне экрана структуры не вызывается — если
 * вызовется, лучше упасть здесь, чем молча рисовать мёртвую карточку.
 */
export function useStructure(): StructureContext {
  const context = inject(structureKey)

  if (!context) {
    throw new Error('useStructure вызван вне экрана структуры компании.')
  }

  return context
}
