<script setup lang="ts">
import type { QuestionTable, QuestionTableColumn } from '~/types/lms'

/**
 * Как автор собирает таблицу-вопрос.
 *
 * Устройство её взято с настоящих бланков аттестации: столбцы с заголовками,
 * заготовленные строки с подписями и признак «строки можно добавлять». Отсюда
 * получаются все случаи бланка — от двенадцати месяцев без подписей до статей
 * расходов, часть которых сотрудник вписывает сам.
 *
 * Подписи строк правятся одним полем, по строке на строку: заводить их по
 * одной кнопкой значит двадцать нажатий там, где хватает вставки из буфера.
 */
const table = defineModel<QuestionTable>({ required: true })

/** Подписи строк — текстом, по одной в строке; пустая строка тоже значима. */
const labels = computed({
  get: () => table.value.rows.map(row => row.label).join('\n'),
  set: (value: string) => {
    table.value = {
      ...table.value,
      rows: value.split('\n').map(label => ({ label: label.trim() })),
    }
  },
})

const KINDS = [
  { value: 'text' as const, label: 'Текст' },
  { value: 'select' as const, label: 'Выбор из списка' },
]

function addColumn() {
  table.value.columns.push({ title: '', kind: 'text', options: [] })
}

function removeColumn(index: number) {
  table.value.columns.splice(index, 1)
}

/** Варианты ячейки-выбора — тоже строкой на вариант. */
function options(column: QuestionTableColumn): string {
  return column.options.join('\n')
}

function setOptions(column: QuestionTableColumn, value: string) {
  column.options = value.split('\n').map(option => option.trim()).filter(Boolean)
}

/* ---------- Эталонные значения ---------- */

/**
 * Ожидаемое значение ячейки. Пустое — «эту ячейку не сверяем»: в прогнозе на
 * тринадцать недель правильных чисел не существует, а в столбце «Тип расхода»
 * они есть.
 */
function expected(rowIndex: number, columnIndex: number): string {
  return table.value.rows[rowIndex]?.expected?.[columnIndex] ?? ''
}

function setExpected(rowIndex: number, columnIndex: number, value: string) {
  const rows = table.value.rows.map((row, index) => {
    if (index !== rowIndex) {
      return row
    }

    const values = table.value.columns.map((_, column) => column === columnIndex
      ? value.trim()
      : (row.expected?.[column] ?? ''))

    return { ...row, expected: values }
  })

  table.value = { ...table.value, rows }
}

/** Сколько ячеек будет сверяться: по этому числу видно, задан ли эталон вообще. */
const checkedCells = computed(() => table.value.rows.reduce(
  (total, row) => total + (row.expected ?? []).filter(value => value !== '').length,
  0,
))
</script>

<template>
  <div class="editor">
    <div class="row">
      <label class="field">
        <span>Заголовок первого столбца</span>
        <input v-model.trim="table.row_label_title" type="text" placeholder="Месяц">
        <span class="hint">Оставьте пустым, если подписей у строк нет</span>
      </label>

      <label class="check">
        <input v-model="table.can_add_rows" type="checkbox">
        Сотрудник может добавлять строки
      </label>
    </div>

    <label class="field">
      <span>Подписи строк — по одной в строке</span>
      <textarea v-model="labels" rows="4" placeholder="Неделя 1&#10;Неделя 2&#10;Неделя 3" />
      <span class="hint">
        Строк заведено: {{ table.rows.length }}. Пустая строка — та, название
        которой сотрудник впишет сам; заполнить её он тоже будет обязан. Если
        строки нужны про запас, не заводите их — включите добавление строк.
      </span>
    </label>

    <div class="columns">
      <div v-for="(column, index) in table.columns" :key="index" class="column">
        <div class="column__head">
          <input v-model.trim="column.title" type="text" placeholder="Заголовок столбца">

          <UiSelect
            v-model="column.kind"
            :options="KINDS"
            auto
            @update:model-value="column.options = []"
          />

          <button type="button" class="danger" @click="removeColumn(index)">
            ×
          </button>
        </div>

        <label v-if="column.kind === 'select'" class="field">
          <span>Варианты — по одному в строке</span>
          <textarea
            :value="options(column)"
            rows="3"
            placeholder="Постоянные&#10;Переменные"
            @input="setOptions(column, ($event.target as HTMLTextAreaElement).value)"
          />
        </label>
      </div>
    </div>

    <button type="button" class="button-plain" @click="addColumn">
      Добавить столбец
    </button>

    <!-- Эталон необязателен: где правильного ответа не существует (прогноз
         на 13 недель — данные самой компании), ячейку просто требуется
         заполнить. Где он есть — задайте, и ячейка будет сверяться. -->
    <details v-if="table.rows.length && table.columns.length" class="reference">
      <summary>
        Правильные ответы — необязательно
        <span class="hint">· сверяется ячеек: {{ checkedCells }}</span>
      </summary>

      <p class="hint">
        Заполните только те ячейки, у которых есть один правильный ответ.
        Пустая ячейка не сверяется — её достаточно заполнить. Числа сверяются
        как числа, текст — без учёта регистра и знаков.
      </p>

      <div class="table-wrap">
        <table class="grid">
          <thead>
            <tr>
              <th>{{ table.row_label_title || 'Строка' }}</th>
              <th v-for="(column, index) in table.columns" :key="index">
                {{ column.title || `Столбец ${index + 1}` }}
              </th>
            </tr>
          </thead>

          <tbody>
            <tr v-for="(row, rowIndex) in table.rows" :key="rowIndex">
              <td class="grid__label">
                {{ row.label || `Строка ${rowIndex + 1}` }}
              </td>

              <td v-for="(column, columnIndex) in table.columns" :key="columnIndex">
                <UiSelect
                  v-if="column.kind === 'select'"
                  :model-value="expected(rowIndex, columnIndex)"
                  :options="[
                    { value: '', label: 'не сверять' },
                    ...column.options.map(option => ({ value: option, label: option })),
                  ]"
                  @update:model-value="value => setExpected(rowIndex, columnIndex, String(value ?? ''))"
                />
                <input
                  v-else
                  type="text"
                  :value="expected(rowIndex, columnIndex)"
                  placeholder="не сверять"
                  @change="setExpected(rowIndex, columnIndex, ($event.target as HTMLInputElement).value)"
                >
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </details>

    <p class="hint">
      Таблица зачитывается, когда заготовленные строки заполнены целиком: верны
      ли в ней числа, приложение знать не может — это работа, которую читает
      человек.
    </p>
  </div>
</template>

<style scoped>
.reference {
  padding: 0.6rem 0.8rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface);
}

.reference summary {
  cursor: pointer;
  font-size: 0.9rem;
}

.reference .hint {
  margin: 0.4rem 0 0;
}

.table-wrap {
  overflow-x: auto;
  margin-top: 0.5rem;
}

.grid {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.88rem;
}

.grid th,
.grid td {
  padding: 0.2rem 0.3rem;
  border: 1px solid var(--color-border);
  text-align: left;
}

.grid th {
  padding: 0.35rem 0.5rem;
  background: var(--color-surface-sunken);
  font-size: 0.76rem;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--color-text-muted);
  white-space: nowrap;
}

.grid__label {
  color: var(--color-text-muted);
  white-space: nowrap;
}

.grid input[type='text'] {
  min-width: 7rem;
  padding: 0.3rem 0.4rem;
  border: 0;
  background: transparent;
}

/*
 * Поля здесь выглядят так же, как в самом конструкторе теста: у него они
 * покрашены своими стилями, а у отдельного компонента чужие не наследуются —
 * и без этого таблица садилась в форму тёмными коробками не по размеру.
 */
input[type='text'],
textarea {
  width: 100%;
  padding: 0.5rem 0.7rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface-raised);
  color: var(--color-text);
  font: inherit;
}

textarea {
  resize: vertical;
}

input[type='text']:focus,
textarea:focus {
  outline: none;
  border-color: var(--color-border-strong);
}

.button-plain,
.danger {
  align-self: flex-start;
  padding: 0.4rem 0.85rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface-raised);
  color: var(--color-text);
  font: inherit;
  font-size: 0.9rem;
  cursor: pointer;
}

.danger {
  color: var(--color-danger);
  border-color: var(--color-danger);
}

.editor {
  display: flex;
  flex-direction: column;
  gap: 0.8rem;
  margin-top: 0.6rem;
}

/* Заголовок ведущего столбца и галочка — одна строка вопроса «как устроены
   строки», поэтому стоят рядом, а не друг под другом. */
.row {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 0.75rem 1rem;
}

.row .field {
  flex: 1 1 20rem;
}

.field {
  display: block;
}

.field > span {
  display: block;
  margin-bottom: 0.3rem;
  font-size: 0.85rem;
  color: var(--color-text-muted);
}

.field .hint {
  margin-top: 0.3rem;
}

.check {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  /* Прижата к нижнему краю поля рядом: так их подписи стоят на одной линии. */
  padding-bottom: 0.55rem;
  font-size: 0.9rem;
  white-space: nowrap;
}

.columns {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.column {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding: 0.7rem 0.8rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface);
}

.column__head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

/* Заголовок тянется, вид и «убрать» держат свою ширину. */
.column__head input[type='text'] {
  flex: 1 1 12rem;
  width: auto;
}

.hint {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.82rem;
}
</style>
