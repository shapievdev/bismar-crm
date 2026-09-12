import type { Department } from '~/types/structure'

/** Отдел в плоском списке: с отступом по глубине и с именем родителя. */
export interface FlatDepartment {
  id: number
  name: string
  /** Насколько глубоко отдел лежит в дереве: 0 — корень. */
  depth: number
  parent: string | null
}

/**
 * Дерево отделов, развёрнутое в плоский список с отступами.
 *
 * Дерево с галочками не нарисовать так, чтобы его было удобно читать и удобно
 * отмечать разом; плоский список с отступами отвечает на оба вопроса — где
 * отдел стоит и выбран ли он. Тем же приёмом отделы выбирают в новостях.
 *
 * Отмечая отдел, отмечают и всё, что под ним (см. DepartmentReach на сервере),
 * поэтому подотделы из списка не вычёркиваются: их отмечают отдельно, когда
 * нужен именно подотдел, а не весь куст.
 */
export function flattenDepartments(tree: Department[], depth = 0, parent: string | null = null): FlatDepartment[] {
  return tree.flatMap(node => [
    { id: node.id, name: node.name, depth, parent },
    ...flattenDepartments(node.children ?? [], depth + 1, node.name),
  ])
}
