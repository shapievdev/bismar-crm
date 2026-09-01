/**
 * Узел дерева категорий — общее у учебных категорий и категорий документов:
 * у них разные таблицы, но одинаковое устройство.
 */
export interface CategoryNode {
  name: string
  slug: string
  children?: CategoryNode[]
}

/**
 * Путь от корня раздела до этой категории — то, из чего рисуются крошки.
 *
 * Категории приходят деревом, а в адресе лежит один slug, поэтому дорогу
 * наверх приходится восстанавливать. Считается по уже загруженному дереву:
 * категорий десятки, и спрашивать сервер о каждом предке незачем.
 *
 * Пустой путь означает «такой категории в дереве нет» — её могли удалить, пока
 * ссылка лежала у человека в закладках, и крошки тогда просто не рисуются.
 */
export function categoryTrail<T extends CategoryNode>(tree: T[], slug?: string | null): T[] {
  if (!slug) {
    return []
  }

  const walk = (nodes: T[], ancestors: T[]): T[] | null => {
    for (const node of nodes) {
      const path = [...ancestors, node]

      if (node.slug === slug) {
        return path
      }

      const found = walk((node.children ?? []) as T[], path)

      if (found) {
        return found
      }
    }

    return null
  }

  return walk(tree, []) ?? []
}
