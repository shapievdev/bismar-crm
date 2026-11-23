import test from 'node:test'
import assert from 'node:assert/strict'
import { departmentReportTree, visibleDepartmentRows } from '../app/utils/department-report-tree.ts'
const items = [
  { id: 1, name: 'Бисмар', parent_id: null },
  { id: 2, name: 'Коммерческий департамент', parent_id: 1 },
  { id: 3, name: 'Розница', parent_id: 2 },
  { id: 4, name: 'Касса', parent_id: 3 },
  { id: 5, name: 'Склад', parent_id: 1 },
]
const ids = rows => rows.map(row => row.item.id)

test('the report starts with collapsed roots and opens one level at a time', () => {
  const tree = departmentReportTree(items)
  assert.deepEqual(ids(visibleDepartmentRows(tree, new Set())), [1])
  assert.deepEqual(ids(visibleDepartmentRows(tree, new Set([1]))), [1, 2, 5])
  const rows = visibleDepartmentRows(tree, new Set([1, 2, 3]))
  assert.deepEqual(ids(rows), [1, 2, 3, 4, 5])
  assert.equal(rows[3].depth, 3)
  assert.equal(rows[0].childCount, 4)
})

test('search preserves the path to matching departments without unrelated branches', () => {
  const rows = visibleDepartmentRows(departmentReportTree(items), new Set(items.map(row => row.id)), 'КАССА')
  assert.deepEqual(ids(rows), [1, 2, 3, 4])
})

test('searching a parent includes its entire branch', () => {
  assert.deepEqual(ids(visibleDepartmentRows(departmentReportTree(items), new Set([1, 2, 3]), 'розница')), [1, 2, 3, 4])
})

test('a department whose parent is hidden becomes a root without inventing an ancestor', () => {
  assert.deepEqual(ids(visibleDepartmentRows(departmentReportTree([items[2], items[3]]), new Set())), [3])
})

test('cycles and self links cannot hide departments or cause infinite traversal', () => {
  const cyclic = [{ id: 1, name: 'Первый', parent_id: 2 }, { id: 2, name: 'Второй', parent_id: 1 }, { id: 3, name: 'Третий', parent_id: 3 }]
  assert.deepEqual(ids(visibleDepartmentRows(departmentReportTree(cyclic), new Set([1, 2, 3]))), [1, 2, 3])
})
