export interface DepartmentTreeItem {
  id: number
  name: string
  parent_id?: number | null
}
export interface DepartmentReportNode<T> {
  item: T
  children: DepartmentReportNode<T>[]
}

/** Only the departments returned by the scoped report can enter this tree. */
export function departmentReportTree<T extends DepartmentTreeItem>(items: T[]): DepartmentReportNode<T>[] {
  const byId = new Map(items.map(item => [item.id, { item, children: [] } as DepartmentReportNode<T>]))
  const roots: DepartmentReportNode<T>[] = []
  for (const node of byId.values()) {
    const parent = node.item.parent_id == null ? undefined : byId.get(node.item.parent_id)
    // A malformed cycle should never hide a department or recurse forever.
    const path = new Set([node.item.id])
    let ancestor = parent
    while (ancestor && !path.has(ancestor.item.id)) {
      path.add(ancestor.item.id)
      ancestor = ancestor.item.parent_id == null ? undefined : byId.get(ancestor.item.parent_id)
    }
    if (parent && !ancestor) parent.children.push(node)
    else roots.push(node)
  }
  return roots
}

export interface DepartmentReportRow<T> {
  item: T
  depth: number
  childCount: number
  hasChildren: boolean
}

export function visibleDepartmentRows<T extends DepartmentTreeItem>(
  tree: DepartmentReportNode<T>[], expanded: Set<number>, search = '',
): DepartmentReportRow<T>[] {
  const query = search.trim().toLocaleLowerCase('ru')
  function matching(nodes: DepartmentReportNode<T>[]): DepartmentReportNode<T>[] {
    if (!query) return nodes
    return nodes.flatMap(node => {
      if (node.item.name.toLocaleLowerCase('ru').includes(query)) return [node]
      const children = matching(node.children)
      return children.length ? [{ ...node, children }] : []
    })
  }
  const result: DepartmentReportRow<T>[] = []
  const count = (nodes: DepartmentReportNode<T>[]): number => nodes.reduce((sum, node) => sum + 1 + count(node.children), 0)
  function walk(nodes: DepartmentReportNode<T>[], depth: number) {
    for (const node of nodes) {
      result.push({ item: node.item, depth, childCount: count(node.children), hasChildren: node.children.length > 0 })
      if (expanded.has(node.item.id)) walk(node.children, depth + 1)
    }
  }
  walk(matching(tree), 0)
  return result
}
