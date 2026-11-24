<script setup lang="ts">
import type { DismissalDepartment, DismissalDepartmentInput } from '~/types/staff-dismissal'
import { departmentReportTree, visibleDepartmentRows } from '~/utils/department-report-tree'

const props = withDefaults(defineProps<{
  departments: DismissalDepartment[]
  editing?: boolean
  disabled?: boolean
}>(), { editing: false, disabled: false })
const model = defineModel<DismissalDepartmentInput[]>({ default: () => [] })
const search = ref('')
const expanded = ref(new Set<number>())
const tree = computed(() => departmentReportTree(props.departments))
const visible = computed(() => visibleDepartmentRows(tree.value, expanded.value, search.value))
const inputs = computed(() => new Map(model.value.map(row => [row.id, row])))
const hasBranches = computed(() => tree.value.some(node => node.children.length > 0))
watch(search, value => {
  expanded.value = value.trim() ? new Set(props.departments.map(row => row.id)) : new Set()
})
function toggle(id: number) {
  const next = new Set(expanded.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  expanded.value = next
}
function all(open: boolean) { expanded.value = open ? new Set(props.departments.map(row => row.id)) : new Set() }
function format(value: number | null, percent = false) {
  return value == null ? '—' : `${value.toLocaleString('ru-RU', { maximumFractionDigits: 1 })}${percent ? '%' : ''}`
}
function update(id: number, key: 'average_headcount' | 'retainable', event: Event) {
  const value = (event.target as HTMLInputElement).value
  model.value = model.value.map(row => row.id === id ? { ...row, [key]: value } : row)
}
const columns = [
  ['average_headcount', 'Среднесписочная', false], ['left', 'Уволилось', false], ['turnover', 'Текучка', true],
  ['interviews', 'Интервью', false], ['interview_coverage', 'Охват', true], ['retainable', 'Можно удержать', false],
  ['rehire_recommended_count', 'К повторному приёму', false],
] as const
</script>

<template>
  <div class="department-report">
    <div class="department-tools">
      <input v-model="search" type="search" class="input department-search" placeholder="Найти подразделение" aria-label="Найти подразделение">
      <div v-if="hasBranches" class="department-controls">
        <button type="button" class="department-link" @click="all(true)">Развернуть всё</button>
        <span aria-hidden="true">·</span>
        <button type="button" class="department-link" @click="all(false)">Свернуть всё</button>
      </div>
    </div>
    <p v-if="!visible.length" class="department-empty">{{ search ? 'Подразделения не найдены. Попробуйте другое название.' : 'Нет подразделений в выбранном срезе.' }}</p>

    <div v-else-if="editing" class="department-inputs">
      <div v-for="row in visible" :key="row.item.id" class="department-input-row" :style="{ '--depth': Math.min(row.depth, 5) }">
        <div class="department-input-heading">
          <button v-if="row.hasChildren" type="button" class="department-toggle" :aria-expanded="expanded.has(row.item.id)" @click="toggle(row.item.id)">
            <svg viewBox="0 0 20 20" class="department-chevron" :class="{ 'is-open': expanded.has(row.item.id) }" aria-hidden="true"><path d="m7.5 5 5 5-5 5" /></svg>
            <span>{{ row.item.name }}</span>
          </button>
          <strong v-else class="department-leaf">{{ row.item.name }}</strong>
        </div>
        <div class="department-fields">
          <label>Среднесписочная, человек<input class="input" type="number" min="0" max="10000000" step="0.01" placeholder="Не указано" :disabled="disabled" :value="inputs.get(row.item.id)?.average_headcount ?? ''" @input="update(row.item.id, 'average_headcount', $event)"></label>
          <label>Можно удержать, человек<input class="input" type="number" min="0" step="1" :max="row.item.interviews" placeholder="Не указано" :disabled="disabled" :value="inputs.get(row.item.id)?.retainable ?? ''" @input="update(row.item.id, 'retainable', $event)"></label>
        </div>
      </div>
    </div>

    <template v-else>
      <div class="department-table-wrap">
        <table class="department-table" aria-label="Увольнения по структуре компании">
          <thead><tr><th scope="col">Подразделение</th><th v-for="[key, title, percent] in columns" :key="key" scope="col">{{ title }}<small>{{ percent ? '%' : 'человек' }}</small></th></tr></thead>
          <tbody><tr v-for="row in visible" :key="row.item.id" :class="{ 'department-parent': row.hasChildren }">
            <th scope="row" :style="{ '--depth': Math.min(row.depth, 6) }">
              <button v-if="row.hasChildren" type="button" class="department-toggle" :aria-expanded="expanded.has(row.item.id)" @click="toggle(row.item.id)">
                <svg viewBox="0 0 20 20" class="department-chevron" :class="{ 'is-open': expanded.has(row.item.id) }" aria-hidden="true"><path d="m7.5 5 5 5-5 5" /></svg>
                <span>{{ row.item.name }}<small>{{ row.childCount }} в структуре</small></span>
              </button>
              <span v-else class="department-leaf">{{ row.item.name }}</span>
            </th>
            <td v-for="[key, , percent] in columns" :key="key" :class="{ 'department-missing': row.item[key] == null, 'department-count': key === 'left' && row.item.left > 0 }">{{ format(row.item[key], percent) }}</td>
          </tr></tbody>
        </table>
      </div>
      <div class="department-mobile">
        <article v-for="row in visible" :key="row.item.id" class="department-mobile-card" :class="{ 'department-mobile-child': row.depth > 0 }" :style="{ '--depth': Math.min(row.depth, 3) }">
          <button v-if="row.hasChildren" type="button" class="department-toggle" :aria-expanded="expanded.has(row.item.id)" @click="toggle(row.item.id)">
            <svg viewBox="0 0 20 20" class="department-chevron" :class="{ 'is-open': expanded.has(row.item.id) }" aria-hidden="true"><path d="m7.5 5 5 5-5 5" /></svg>
            <span>{{ row.item.name }}<small>{{ row.childCount }} в структуре</small></span>
          </button>
          <h4 v-else>{{ row.item.name }}</h4>
          <dl class="department-mobile-values"><div v-for="[key, title, percent] in columns" :key="key"><dt>{{ title }}</dt><dd :class="{ 'department-missing': row.item[key] == null }">{{ format(row.item[key], percent) }}</dd></div></dl>
        </article>
      </div>
    </template>
  </div>
</template>

<style scoped>
.department-report { min-width: 0; }
.department-tools { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin: 18px 0; flex-wrap: wrap; }
.department-search { width: min(100%, 320px); font-size: 14px; }
.department-controls { display: flex; align-items: center; gap: 12px; color: var(--color-text-muted); font-size: 13px; flex-wrap: wrap; }
.department-link { padding: 4px 0; border: 0; background: none; color: var(--color-text-muted); font: inherit; cursor: pointer; }
.department-link:hover { color: var(--color-text); }
.department-table-wrap { overflow-x: auto; border: 1px solid var(--color-border); border-radius: 18px; }
.department-table { width: 100%; border-collapse: collapse; font-size: 13px; line-height: 1.45; }
.department-table thead { background: var(--control-surface); }
.department-table thead th { padding: 15px 10px; color: var(--color-text-muted); font-size: 12px; font-weight: 500; text-align: right; vertical-align: top; }
.department-table thead th:first-child { text-align: left; padding-left: 18px; }
.department-table th small { display: block; font-size: 11px; margin-top: 4px; font-weight: 400; color: var(--color-text-muted); }
.department-table tbody th { width: 32%; min-width: 220px; padding: 0 14px 0 calc(12px + var(--depth, 0) * 18px); font-weight: 500; text-align: left; }
.department-table td { padding: 18px 10px; text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.department-table tr + tr { border-top: 1px solid var(--color-border); }
.department-table tbody tr:hover { background: var(--control-surface); }
.department-parent { background: color-mix(in srgb, var(--control-surface) 45%, transparent); }
.department-toggle { display: flex; align-items: center; width: 100%; gap: 10px; background: none; border: 0; color: var(--color-text); padding: 15px 0; font: inherit; text-align: left; cursor: pointer; line-height: 1.45; }
.department-toggle small { display: block; font-size: 11px; color: var(--color-text-muted); font-weight: 400; margin-top: 3px; }
.department-chevron { width: 18px; height: 18px; flex: 0 0 18px; fill: none; stroke: currentColor; stroke-width: 1.6; stroke-linecap: round; stroke-linejoin: round; transition: transform 150ms; }
.department-chevron.is-open { transform: rotate(90deg); }
.department-leaf { display: block; padding: 17px 0 17px 28px; font-weight: 400; }
.department-count { font-weight: 600; }
.department-missing { color: var(--color-text-muted); }
.department-empty { padding: 28px 16px; text-align: center; color: var(--color-text-muted); font-size: 14px; }
.department-mobile { display: none; }
.department-inputs { display: grid; gap: 10px; }
.department-input-row { border: 1px solid var(--color-border); border-radius: 16px; padding: 0 18px 18px; margin-left: calc(var(--depth) * 14px); min-width: 0; }
.department-input-heading { font-size: 14px; }
.department-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
.department-fields label { display: grid; gap: 8px; color: var(--color-text-muted); font-size: 12px; }
.department-fields .input { width: 100%; min-width: 0; font-size: 14px; color: var(--color-text); }
@media (max-width: 760px) {
  .department-table-wrap { display: none; }
  .department-mobile { display: grid; gap: 12px; }
  .department-tools { gap: 12px; }
  .department-search { width: 100%; }
  .department-mobile-card { background: var(--control-surface); border-radius: 16px; padding: 0 16px 16px; min-width: 0; }
  .department-mobile-child { margin-left: calc(var(--depth) * 10px); border-left: 2px solid var(--color-border); }
  .department-mobile-card h4 { font-size: 14px; margin: 0; padding: 16px 0; font-weight: 500; }
  .department-mobile-card .department-toggle { font-size: 14px; }
  .department-mobile-values { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px 12px; margin: 0; }
  .department-mobile-values dt { font-size: 11px; color: var(--color-text-muted); line-height: 1.4; }
  .department-mobile-values dd { margin: 5px 0 0; font-size: 18px; font-weight: 500; font-variant-numeric: tabular-nums; }
  .department-input-row { padding: 0 14px 14px; margin-left: calc(var(--depth) * 8px); }
  .department-fields { grid-template-columns: minmax(0, 1fr); gap: 12px; }
}
</style>
