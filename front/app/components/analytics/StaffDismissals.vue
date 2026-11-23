<script setup lang="ts">
import type { StaffQuery } from '~/types/analytics'
import type { StaffDismissalReport } from '~/types/staff-dismissal'
import { ApiValidationError, toValidationError } from '~/composables/useAuth'

const props = defineProps<{ query: StaffQuery }>()
const { $api } = useNuxtApp()
const { can } = useAuth()
const { data, error, pending, refresh } = await useAsyncData('staff-dismissals',
  () => $api<{ data: StaffDismissalReport }>('/api/analytics/staff/dismissals', { query: props.query }),
  { watch: [() => props.query] })
const report = computed(() => data.value?.data)
const editing = ref(false), saving = ref(false), message = ref('')
const retainable = ref<number | string>(''), conditions = ref('')
const rows = ref<{ id: number, name: string, average_headcount: number | string, retainable: number | string }[]>([])
const metrics = [
  ['left', 'Уволилось', false], ['interviews', 'Прошли интервью', false], ['interview_coverage', 'Охват интервью', true],
  ['retainable', 'Можно было удержать', false], ['retainable_share', 'Доля удерживаемых', true],
  ['early_left', 'Уход до 3 месяцев', false], ['early_share', 'Доля ранних уходов', true],
] as const
function number(value: number | null | undefined, percent = false) {
  return value == null ? '—' : `${value.toLocaleString('ru-RU', { maximumFractionDigits: 1 })}${percent ? '%' : ''}`
}
function edit() {
  retainable.value = report.value?.summary.retainable ?? ''
  conditions.value = report.value?.conditions_assessment ?? ''
  rows.value = (report.value?.departments ?? []).map(row => ({ id: row.id, name: row.name,
    average_headcount: row.average_headcount ?? '', retainable: row.retainable ?? '' }))
  message.value = ''; editing.value = true
}
watch(() => props.query, () => { editing.value = false; message.value = '' })
async function save() {
  saving.value = true; message.value = ''
  const applied = JSON.stringify(props.query)
  const nullable = (value: number | string) => value === '' ? null : Number(value)
  try {
    const saved = await $api<{ data: StaffDismissalReport }>('/api/analytics/staff/dismissals', {
      method: 'PUT', query: props.query, body: {
        retainable: nullable(retainable.value), conditions_assessment: conditions.value,
        department_values: rows.value.map(row => ({ id: row.id,
          average_headcount: nullable(row.average_headcount), retainable: nullable(row.retainable) })),
      },
    }).catch(toValidationError)
    if (JSON.stringify(props.query) === applied) data.value = saved
    editing.value = false
  }
  catch (caught) { message.value = caught instanceof ApiValidationError ? caught.firstMessage : 'Не удалось сохранить данные. Попробуйте ещё раз.' }
  finally { saving.value = false }
}
</script>

<template>
  <section id="dismissals" class="dismissals card" :aria-busy="pending">
    <header class="section-heading">
      <div><h2>Аналитика увольнений</h2><p class="muted">За выбранный выше период. Интервью — заполненная выходная анкета.</p></div>
      <div class="actions"><NuxtLink v-if="can('users.manage')" class="button-secondary" to="/analytics/exit-surveys">Выходные анкеты</NuxtLink><button class="button-secondary" :disabled="pending || saving" @click="refresh()">Обновить</button><button v-if="report?.can_edit" class="button-secondary" :disabled="pending" @click="edit">Ввести данные</button></div>
    </header>
    <p v-if="error" class="alert alert--danger" role="alert">Не удалось загрузить аналитику увольнений.</p>
    <template v-if="report">
      <div class="metrics"><div v-for="[key, label, percent] in metrics" :key="key" class="metric"><span>{{ label }}</span><strong>{{ number(report.summary[key], percent) }}</strong><small v-if="key === 'retainable'">Вводится вручную</small></div></div>
      <p class="muted">Охват — от всех уволенных, доля удерживаемых — от прошедших интервью, ранние уходы — от всех увольнений. Отсутствующие значения обозначены «—».</p>
      <p v-if="report.summary.without_hire_date" class="muted">У {{ report.summary.without_hire_date }} уволенных не указана дата приёма: ранний уход для них определить нельзя.</p>
      <p class="muted">Ожидают анкету: {{ report.summary.pending }}. Без действующей анкеты: {{ report.summary.without_survey }} (включая не прошедших стажировку и старые увольнения).</p>
      <h3>Увольнения по подразделениям</h3>
      <div class="table-scroll"><table><thead><tr><th>Подразделение</th><th>Среднесписочная, чел.</th><th>Уволилось, чел.</th><th>Текучка, %</th><th>Интервью, чел.</th><th>Охват, %</th><th>Можно удержать, чел.</th></tr></thead><tbody><tr v-for="row in report.departments" :key="row.id"><th>{{ row.name }}</th><td>{{ number(row.average_headcount) }}</td><td>{{ row.left }}</td><td>{{ number(row.turnover, true) }}</td><td>{{ row.interviews }}</td><td>{{ number(row.interview_coverage, true) }}</td><td>{{ number(row.retainable) }}</td></tr></tbody></table></div>
      <p class="muted">Среднесписочная и «Можно удержать» вводятся вручную. Текучка = уволилось / среднесписочная × 100%. Сотрудник в нескольких подразделениях учитывается в каждом, в общей сводке — один раз.</p>
      <div class="reasons"><section><h3>Основные причины увольнений</h3><p class="muted">Причины, указанные при увольнении. Доли от всех уволенных.</p><p v-if="!report.reasons.length" class="muted">Увольнений за период нет.</p><div v-for="reason in report.reasons" :key="reason.label" class="reason"><span>{{ reason.label }}</span><strong>{{ reason.count }} чел. · {{ number(reason.share, true) }}</strong></div></section><section><h3>Оценка условий</h3><p class="assessment">{{ report.conditions_assessment || 'Оценка пока не внесена.' }}</p></section></div>
      <details v-if="report.interview_reasons.length"><summary>Причины из выходных анкет</summary><p class="muted">Одинаковые формулировки объединены; доля считается от прошедших интервью.</p><div v-for="reason in report.interview_reasons" :key="reason.label" class="reason"><span>{{ reason.label }}</span><strong>{{ reason.count }} чел. · {{ number(reason.share, true) }}</strong></div></details>
    </template>
    <AppSheet :open="editing" title="Данные отчёта об увольнениях" hint="Значения сохраняются для выбранного периода и фильтров." wide @close="!saving && (editing = false)">
      <form class="manual-form" @submit.prevent="save">
        <label>Можно было удержать, человек<input v-model="retainable" class="input" type="number" min="0" :max="report?.summary.interviews" step="1" placeholder="Не указано"></label>
        <label>Оценка условий<textarea v-model="conditions" class="input" rows="4" maxlength="5000" placeholder="Внесите оценку условий за период" /></label>
        <h3>По подразделениям</h3><div v-for="row in rows" :key="row.id" class="manual-row"><strong>{{ row.name }}</strong><label>Среднесписочная, человек<input v-model="row.average_headcount" class="input" type="number" min="0" max="10000000" step="0.01" placeholder="Не указано"></label><label>Можно удержать, человек<input v-model="row.retainable" class="input" type="number" min="0" step="1" placeholder="Не указано"></label></div>
        <p v-if="message" role="alert" class="alert alert--danger">{{ message }}</p><div class="actions"><button class="button-primary" :disabled="saving">{{ saving ? 'Сохраняем…' : 'Сохранить' }}</button><button type="button" class="button-secondary" :disabled="saving" @click="editing = false">Отмена</button></div>
      </form>
    </AppSheet>
  </section>
</template>

<style scoped>
.dismissals { margin: 28px 0; padding: 28px; }h2 { margin: 0; }h3 { margin: 24px 0 16px; }.section-heading,.actions { display: flex; gap: 12px; align-items: start; justify-content: space-between; flex-wrap: wrap; }.metrics { display: grid; grid-template-columns: repeat(auto-fit,minmax(155px,1fr)); gap: 12px; margin: 24px 0; }.metric { min-width: 0; overflow-wrap: anywhere; padding: 18px; border: 1px solid var(--color-border); border-radius: 18px; display: grid; gap: 12px; }.metric strong { font-size: 30px; }.metric small { color: var(--color-text-muted); }.table-scroll { overflow-x: auto; }table { border-collapse: collapse; width: 100%; }td,th { text-align: left; padding: 14px 12px; border-bottom: 1px solid var(--color-border); min-width: 100px; }th:first-child { min-width: 180px; }.reasons { display: grid; grid-template-columns: 1fr 1fr; gap: 28px; }.reason { display: flex; justify-content: space-between; gap: 20px; padding: 12px 0; border-bottom: 1px solid var(--color-border); }.reason span { overflow-wrap: anywhere; }.reason strong { flex-shrink: 0; }.assessment { white-space: pre-wrap; overflow-wrap: anywhere; }.manual-form { display: grid; gap: 20px; }.manual-form label { display: grid; gap: 8px; }.manual-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; padding-bottom: 16px; border-bottom: 1px solid var(--color-border); }summary { cursor: pointer; font-weight: 600; }.muted { line-height: 1.6; }
@media(max-width:700px) { .dismissals { padding: 20px; }.reasons,.manual-row { grid-template-columns: 1fr; }.metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); }.metric { padding: 14px; font-size: 14px; line-height: 1.4; }.metric strong { font-size: 26px; } }
</style>
