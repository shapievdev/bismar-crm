<script setup lang="ts">
import type { QuestionTable } from '~/types/lms'

/**
 * Таблица, которую заполняет сотрудник.
 *
 * Одна разметка на урок и на документ. Ячейки хранятся строками: значение —
 * это то, что человек написал, и приводить его к числу приложение не берётся —
 * в бланке рядом стоят суммы, даты и «дд.мм-дд.мм».
 *
 * Ведущая ячейка подписанной строки уходит на сервер вместе с подписью, но
 * править её нельзя: так ширина строки одна на всю таблицу, и правило зачёта не
 * приходится считать для каждой строки отдельно.
 */
const props = defineProps<{
  table: QuestionTable
  disabled?: boolean
  /** Ячейки, разошедшиеся с эталоном: в разборе они обведены. */
  wrong?: { row: number, cell: number }[]
}>()

const rows = defineModel<string[][]>({ required: true })

/** Заполняет ли сотрудник первый столбец: у пустых подписей и у добавленных. */
const hasLeadingInput = computed(() => props.table.row_label_title !== null
  && (props.table.rows.some(row => row.label === '') || props.table.can_add_rows))

const width = computed(() => props.table.columns.length + (hasLeadingInput.value ? 1 : 0))

/** Подпись заготовленной строки; у добавленной её нет — там ячейка. */
function labelOf(index: number): string | null {
  if (props.table.row_label_title === null) {
    return null
  }

  const label = props.table.rows[index]?.label ?? ''

  return label === '' ? null : label
}

/**
 * Строки ответа держатся в том же числе, что заготовлено: пока сотрудник не
 * тронул таблицу, пустые строки всё равно нужны — иначе рисовать нечего.
 */
function ensureRows() {
  const wanted = Math.max(props.table.rows.length, rows.value.length)
  const next = Array.from({ length: wanted }, (_, index) => {
    const row = Array.from({ length: width.value }, (_, cell) => rows.value[index]?.[cell] ?? '')

    // Подпись заготовленной строки уходит вместе с ней.
    const label = labelOf(index)

    if (label !== null && hasLeadingInput.value) {
      row[0] = label
    }

    return row
  })

  rows.value = next
}

watch([() => props.table, () => rows.value.length], ensureRows, { immediate: true, deep: true })

function set(rowIndex: number, cellIndex: number, value: string) {
  const next = rows.value.map(row => [...row])
  const row = next[rowIndex]

  if (!row) {
    return
  }

  row[cellIndex] = value
  rows.value = next
}

function addRow() {
  rows.value = [...rows.value, Array.from({ length: width.value }, () => '')]
}

function isWrong(rowIndex: number, cellIndex: number): boolean {
  return (props.wrong ?? []).some(cell => cell.row === rowIndex && cell.cell === cellIndex)
}

/** Какой столбец рисует эта ячейка: с ведущим номера сдвинуты на один. */
function columnOf(cellIndex: number) {
  return props.table.columns[hasLeadingInput.value ? cellIndex - 1 : cellIndex] ?? null
}
</script>

<template>
  <div class="table-wrap">
    <table class="quiz-table">
      <thead>
        <tr>
          <th v-if="table.row_label_title !== null">
            {{ table.row_label_title }}
          </th>
          <th v-for="column in table.columns" :key="column.title">
            {{ column.title }}
          </th>
        </tr>
      </thead>

      <tbody>
        <tr v-for="(row, rowIndex) in rows" :key="rowIndex">
          <!-- Подписанная строка: её название не правят. -->
          <td v-if="table.row_label_title !== null && labelOf(rowIndex) !== null" class="quiz-table__label">
            {{ labelOf(rowIndex) }}
          </td>

          <td
            v-for="(cell, cellIndex) in row"
            :key="cellIndex"
            :class="{
              'quiz-table__hidden': labelOf(rowIndex) !== null && cellIndex === 0 && hasLeadingInput,
              'quiz-table__wrong': isWrong(rowIndex, cellIndex),
            }"
          >
            <UiSelect
              v-if="columnOf(cellIndex)?.kind === 'select'"
              :model-value="cell"
              :options="[
                { value: '', label: '—' },
                ...(columnOf(cellIndex)?.options ?? []).map(option => ({ value: option, label: option })),
              ]"
              :disabled="disabled"
              placeholder="—"
              @update:model-value="value => set(rowIndex, cellIndex, String(value ?? ''))"
            />
            <input
              v-else
              type="text"
              class="quiz-table__cell"
              :value="cell"
              :disabled="disabled"
              @input="set(rowIndex, cellIndex, ($event.target as HTMLInputElement).value)"
            >
          </td>
        </tr>
      </tbody>
    </table>

    <button
      v-if="table.can_add_rows"
      type="button"
      class="button-plain"
      :disabled="disabled"
      @click="addRow"
    >
      + Добавить строку
    </button>
  </div>
</template>

<style scoped>
/* Таблица на телефоне прокручивается, а не ломает страницу. */
.table-wrap {
  overflow-x: auto;
  margin: 0.4rem 0 0.2rem;
}

.quiz-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.9rem;
}

.quiz-table th,
.quiz-table td {
  padding: 0.2rem 0.3rem;
  border: 1px solid var(--color-border);
  text-align: left;
  vertical-align: middle;
}

.quiz-table th {
  padding: 0.4rem 0.5rem;
  background: var(--color-surface-sunken);
  font-size: 0.78rem;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--color-text-muted);
  white-space: nowrap;
}

.quiz-table__label {
  padding: 0.4rem 0.5rem;
  color: var(--color-text-muted);
  white-space: nowrap;
}

/* Ячейка, разошедшаяся с эталоном: цвет здесь не единственный признак — рядом
   с таблицей стоит «совпало 1 из 2», а под ней перечислено ожидаемое. */
.quiz-table__wrong {
  background: var(--color-danger-soft);
  outline: 1px solid var(--color-danger);
  outline-offset: -1px;
}

/* Ведущая ячейка подписанной строки: она уходит на сервер, но на экране её
   место занимает сама подпись. */
.quiz-table__hidden {
  display: none;
}

.quiz-table__cell {
  width: 100%;
  min-width: 7rem;
  padding: 0.35rem 0.4rem;
  border: 0;
  border-radius: var(--radius-sm);
  background: transparent;
  color: inherit;
  font: inherit;
}

.quiz-table__cell:focus {
  outline: 2px solid var(--color-border-strong);
  outline-offset: -2px;
}
</style>
