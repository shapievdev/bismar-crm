<script setup lang="ts">
import type { StaffQuery } from '~/types/analytics'
import type { DismissalDepartmentInput, StaffDismissalReport } from '~/types/staff-dismissal'
import { ApiValidationError, toValidationError } from '~/composables/useAuth'

const props = defineProps<{ query: StaffQuery }>()
const emit = defineEmits<{ period: [value: { from: string, to: string }] }>()
const { $api } = useNuxtApp()
const { can } = useAuth()
const { data, error, pending, refresh } = await useAsyncData('staff-dismissals',
  () => $api<{ data: StaffDismissalReport }>('/api/analytics/staff/dismissals', { query: props.query }),
  { watch: [() => props.query] })
const report = computed(() => data.value?.data)
const editing = ref(false), saving = ref(false), message = ref('')
const retainable = ref<number | string>(''), conditions = ref('')
const rows = ref<DismissalDepartmentInput[]>([])
const manualReport = ref<StaffDismissalReport | null>(null)
const manualPeriod = reactive({ from: '', to: '' })
const periodLoading = ref(false)
const periodChanged = computed(() => !manualReport.value || manualPeriod.from !== manualReport.value.period.from || manualPeriod.to !== manualReport.value.period.to)
function fillManual(value: StaffDismissalReport) {
  manualReport.value = value
  retainable.value = value.summary.retainable ?? ''
  conditions.value = value.conditions_assessment ?? ''
  rows.value = value.departments.map(row => ({ id: row.id, name: row.name, average_headcount: row.average_headcount ?? '', retainable: row.retainable ?? '' }))
}
async function loadManualPeriod() {
  message.value = ''
  if (!manualPeriod.from || !manualPeriod.to || manualPeriod.to < manualPeriod.from) {
    message.value = 'Укажите начало и конец периода. Конец не может быть раньше начала.'
    return
  }
  periodLoading.value = true
  const period = { ...manualPeriod }, scope = JSON.stringify(props.query)
  try {
    const response = await $api<{ data: StaffDismissalReport }>('/api/analytics/staff/dismissals', { query: { ...props.query, ...period } })
    if (editing.value && scope === JSON.stringify(props.query) && period.from === manualPeriod.from && period.to === manualPeriod.to) fillManual(response.data)
  }
  catch { message.value = 'Не удалось загрузить данные за выбранный период.' }
  finally { periodLoading.value = false }
}
function number(value: number | null | undefined, percent = false) {
  return value == null ? '—' : `${value.toLocaleString('ru-RU', { maximumFractionDigits: 1 })}${percent ? '%' : ''}`
}
function edit() {
  if (!report.value) return
  fillManual(report.value)
  Object.assign(manualPeriod, report.value.period)
  message.value = ''; editing.value = true
}
watch(() => props.query, () => { editing.value = false; message.value = '' })
async function save() {
  if (periodLoading.value || periodChanged.value || !manualReport.value) return
  saving.value = true; message.value = ''
  const applied = JSON.stringify(props.query)
  const nullable = (value: number | string) => value === '' ? null : Number(value)
  try {
    const saved = await $api<{ data: StaffDismissalReport }>('/api/analytics/staff/dismissals', {
      method: 'PUT', query: { ...props.query, ...manualReport.value.period }, body: {
        retainable: nullable(retainable.value), conditions_assessment: conditions.value,
        department_values: rows.value.map(row => ({ id: row.id,
          average_headcount: nullable(row.average_headcount), retainable: nullable(row.retainable) })),
      },
    }).catch(toValidationError)
    if (JSON.stringify(props.query) === applied) {
      const changed = saved.data.period.from !== report.value?.period.from || saved.data.period.to !== report.value?.period.to
      if (changed) emit('period', saved.data.period)
      else data.value = saved
    }
    editing.value = false
  }
  catch (caught) { message.value = caught instanceof ApiValidationError ? caught.firstMessage : 'Не удалось сохранить данные. Попробуйте ещё раз.' }
  finally { saving.value = false }
}
function day(value: string) {
  return new Date(`${value}T12:00:00`).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' })
}
</script>


<template>
  <section id="dismissals" class="sd-report card" :aria-busy="pending">
    <header class="sd-header">
      <div class="sd-title"><span class="sd-eyebrow">Выходные интервью</span><h2>Аналитика увольнений</h2><p v-if="report">{{ day(report.period.from) }} — {{ day(report.period.to) }} · {{ report.period.to.slice(0, 4) }}</p></div>
      <div class="sd-actions">
        <NuxtLink v-if="can('users.manage')" class="button-secondary button-sm" to="/analytics/exit-surveys">Выходные анкеты</NuxtLink>
        <button type="button" class="button-secondary button-sm" :disabled="pending || saving" @click="refresh()">Обновить</button>
        <button v-if="report?.can_edit" type="button" class="button-primary button-sm" :disabled="pending" @click="edit">Ввести данные</button>
      </div>
    </header>
    <p v-if="error" class="alert alert--danger" role="alert">Не удалось загрузить аналитику увольнений.</p>
    <template v-if="report">
      <div class="sd-summary">
        <div class="sd-kpi"><span class="sd-kpi-label">Уволилось</span><div class="sd-kpi-value">{{ number(report.summary.left) }}<small>чел.</small></div><span class="sd-kpi-note">За выбранный период</span></div>
        <div class="sd-kpi"><span class="sd-kpi-label">Прошли интервью</span><div class="sd-kpi-value">{{ number(report.summary.interviews) }}<small>чел.</small></div><span class="sd-kpi-note">Заполнили выходную анкету</span></div>
        <div class="sd-kpi sd-kpi-accent"><span class="sd-kpi-label">Охват интервью</span><div class="sd-kpi-value">{{ number(report.summary.interview_coverage, true) }}</div><span class="sd-kpi-note">От всех уволившихся</span></div>
      </div>
      <div class="sd-secondary">
        <section class="sd-detail-card"><div class="sd-detail-heading"><h3>Можно было удержать</h3><span class="sd-badge">Ручной ввод</span></div><div class="sd-detail-value"><strong>{{ number(report.summary.retainable) }}<small>чел.</small></strong><span><b>{{ number(report.summary.retainable_share, true) }}</b> от прошедших интервью</span></div></section>
        <section class="sd-detail-card"><div class="sd-detail-heading"><h3>Ушли до 3 месяцев</h3><span class="sd-badge">Ранние уходы</span></div><div class="sd-detail-value"><strong>{{ number(report.summary.early_left) }}<small>чел.</small></strong><span><b>{{ number(report.summary.early_share, true) }}</b> от всех увольнений</span></div></section>
      </div>
      <div class="sd-status"><span>Ожидают анкету <b>{{ report.summary.pending }}</b></span><span>Без анкеты <b>{{ report.summary.without_survey }}</b></span><span v-if="report.summary.without_hire_date">Без даты приёма <b>{{ report.summary.without_hire_date }}</b></span></div>

      <section class="sd-rehire-summary"><div><h3>Повторное трудоустройство</h3><p>Решение, указанное при увольнении или в карточке сотрудника</p></div><div class="sd-rehire-counts"><span>Да <b>{{ number(report.summary.rehire_recommended_count) }}</b></span><span>Нет <b>{{ number(report.summary.rehire_not_recommended_count) }}</b></span><span>Не указано <b>{{ number(report.summary.rehire_unset_count) }}</b></span></div></section>

      <section class="sd-departments">
        <div class="sd-block-heading"><div><h3>Увольнения по подразделениям</h3><p>Структура компании. Нажмите на подразделение, чтобы раскрыть вложенные отделы.</p></div></div>
        <AnalyticsStaffDismissalDepartments :departments="report.departments" />
        <p class="sd-footnote">Родительский отдел включает всю свою ветку. Сотрудник в нескольких дочерних отделах учитывается в итоге ветки один раз. Среднесписочная и удерживаемые вводятся вручную для каждой строки.</p>
      </section>

      <div class="sd-insights">
        <section class="sd-insight"><h3>Причины увольнений</h3><p class="sd-description">Из причин, указанных при увольнении</p><p v-if="!report.reasons.length" class="sd-empty">За этот период увольнений нет.</p><div v-for="reason in report.reasons" :key="reason.label" class="sd-reason"><div><span>{{ reason.label }}</span><strong>{{ reason.count }} чел.<small>{{ number(reason.share, true) }}</small></strong></div><div class="sd-bar" aria-hidden="true"><span :style="{ width: `${Math.min(reason.share ?? 0, 100)}%` }" /></div></div></section>
        <section class="sd-insight"><div class="sd-detail-heading"><h3>Оценка условий</h3><span class="sd-badge">Ручной ввод</span></div><p class="sd-assessment" :class="{ 'sd-empty': !report.conditions_assessment }">{{ report.conditions_assessment || 'Оценка за этот период пока не внесена.' }}</p><button v-if="report.can_edit && !report.conditions_assessment" type="button" class="button-secondary button-sm" @click="edit">Добавить оценку</button></section>
      </div>
      <details v-if="report.interview_reasons.length" class="sd-explanation"><summary>Причины из выходных анкет <span>{{ report.interview_reasons.length }}</span></summary><p>Одинаковые формулировки объединены. Доли рассчитаны от прошедших интервью.</p><div v-for="reason in report.interview_reasons" :key="reason.label" class="sd-written-reason"><span>{{ reason.label }}</span><strong>{{ reason.count }} чел. · {{ number(reason.share, true) }}</strong></div></details>
      <AnalyticsStaffRehireRecommendations :query="query" :count="report.summary.rehire_recommended_count" />
      <details class="sd-explanation"><summary>Как считаются показатели</summary><ul><li>Охват интервью — заполненные анкеты / все уволившиеся × 100%.</li><li>Доля удерживаемых — введённое количество / прошедшие интервью × 100%.</li><li>Ранний уход — увольнение до трёх календарных месяцев с даты приёма. Доля — от всех увольнений.</li><li>Текучка подразделения — уволившиеся / ручная среднесписочная × 100%.</li><li>«—» означает, что данных для расчёта пока нет. «Без анкеты» включает не прошедших стажировку и старые увольнения.</li></ul></details>
    </template>
    <AppSheet :open="editing" title="Данные отчёта об увольнениях" hint="Значения сохраняются для выбранного периода и фильтров." wide @close="!saving && (editing = false)">
      <form class="sd-manual-form" @submit.prevent="save">
        <section class="sd-period"><h3>Период отчёта</h3><div class="sd-period-fields"><label>С<input v-model="manualPeriod.from" class="input" type="date" required :disabled="saving || periodLoading"></label><label>По<input v-model="manualPeriod.to" class="input" type="date" required :min="manualPeriod.from" :disabled="saving || periodLoading"></label><button v-if="periodChanged" type="button" class="button-secondary" :disabled="saving || periodLoading" @click="loadManualPeriod">{{ periodLoading ? 'Загружаем…' : 'Загрузить период' }}</button></div><p v-if="periodChanged" class="sd-description">Загрузите выбранный период перед вводом данных. Это заменит несохранённые значения.</p></section>
        <div class="sd-manual-summary"><label>Можно было удержать, человек<input v-model="retainable" :disabled="saving || periodLoading || periodChanged" class="input" type="number" min="0" :max="manualReport?.summary.interviews" step="1" placeholder="Не указано"></label><p>Количество сотрудников, которых можно было удержать, среди прошедших интервью.</p></div>
        <label>Оценка условий<textarea v-model="conditions" :disabled="saving || periodLoading || periodChanged" class="input" rows="4" maxlength="5000" placeholder="Внесите оценку условий за период" /></label>
        <section><h3>По подразделениям</h3><p class="sd-description">Для родительского отдела укажите данные по всей ветке. Связанные отделы можно раскрыть.</p><AnalyticsStaffDismissalDepartments v-if="manualReport" v-model="rows" :departments="manualReport.departments" editing :disabled="saving || periodLoading || periodChanged" /></section>
        <p v-if="message" role="alert" class="alert alert--danger">{{ message }}</p><div class="sd-actions sd-form-actions"><button class="button-primary" :disabled="saving || periodLoading || periodChanged">{{ saving ? 'Сохраняем…' : 'Сохранить' }}</button><button type="button" class="button-secondary" :disabled="saving" @click="editing = false">Отмена</button></div>
      </form>
    </AppSheet>
  </section>
</template>

<style scoped>
.sd-report { margin: 28px 0; padding: 28px; min-width: 0; }
.sd-header { display: flex; justify-content: space-between; align-items: center; gap: 24px; flex-wrap: wrap; }
.sd-title { min-width: 0; }
.sd-eyebrow { display: block; margin-bottom: 8px; color: var(--color-text-muted); font-size: 11px; letter-spacing: .07em; text-transform: uppercase; }
.sd-title h2 { font-size: 22px; font-weight: 500; letter-spacing: -.02em; margin: 0; line-height: 1.3; }
.sd-title p { margin: 8px 0 0; color: var(--color-text-muted); font-size: 13px; }
.sd-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.sd-summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-top: 26px; }
.sd-kpi { padding: 22px; border-radius: 18px; background: var(--control-surface); min-width: 0; }
.sd-kpi-label { display: block; font-size: 14px; font-weight: 400; line-height: 1.5; color: var(--color-text-muted); letter-spacing: normal; }
.sd-kpi-value { font-size: 36px; line-height: 1.25; letter-spacing: -.035em; font-weight: 500; margin-top: 14px; font-variant-numeric: tabular-nums; }
.sd-kpi-value small,.sd-detail-value strong small { font-size: 13px; font-weight: 400; letter-spacing: normal; color: var(--color-text-muted); margin-left: 8px; }
.sd-kpi-note { display: block; color: var(--color-text-muted); font-size: 12px; line-height: 1.5; margin-top: 8px; }
.sd-kpi-accent { background: var(--color-accent-soft); }
.sd-secondary { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-top: 12px; }
.sd-detail-card { padding: 20px 22px; border: 1px solid var(--color-border); border-radius: 18px; min-width: 0; }
.sd-detail-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.sd-detail-heading h3 { font-size: 14px; line-height: 1.5; font-weight: 500; margin: 0; }
.sd-badge { border-radius: 20px; padding: 5px 9px; color: var(--color-text-muted); background: var(--control-surface); font-size: 10px; line-height: 1.4; white-space: nowrap; }
.sd-detail-value { display: flex; align-items: baseline; gap: 18px; margin-top: 16px; flex-wrap: wrap; }
.sd-detail-value strong { font-size: 26px; font-weight: 500; line-height: 1.2; font-variant-numeric: tabular-nums; }
.sd-detail-value > span { color: var(--color-text-muted); font-size: 12px; line-height: 1.6; }
.sd-detail-value b { font-weight: 500; color: var(--color-text); }
.sd-status { display: flex; gap: 10px 24px; flex-wrap: wrap; padding: 18px 0 26px; color: var(--color-text-muted); font-size: 12px; line-height: 1.5; }
.sd-status b { color: var(--color-text); font-weight: 500; margin-left: 8px; }
.sd-departments { border-top: 1px solid var(--color-border); padding-top: 24px; }
.sd-block-heading h3,.sd-insight h3,.sd-manual-form h3 { margin: 0; font-size: 17px; font-weight: 500; line-height: 1.4; letter-spacing: -.015em; }
.sd-block-heading p,.sd-description { margin: 8px 0 0; color: var(--color-text-muted); font-size: 12px; line-height: 1.65; }
.sd-footnote { margin: 14px 0 0; color: var(--color-text-muted); font-size: 11px; line-height: 1.7; max-width: 900px; }
.sd-insights { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 24px; padding-top: 28px; margin-top: 28px; border-top: 1px solid var(--color-border); }
.sd-insight { min-width: 0; }
.sd-insight + .sd-insight { border-left: 1px solid var(--color-border); padding-left: 24px; }
.sd-empty { color: var(--color-text-muted); font-size: 13px; line-height: 1.7; }
.sd-assessment { margin: 20px 0; font-size: 14px; line-height: 1.8; white-space: pre-wrap; overflow-wrap: anywhere; }
.sd-reason { margin-top: 20px; }
.sd-reason > div:first-child { display: flex; justify-content: space-between; gap: 16px; font-size: 13px; line-height: 1.5; }
.sd-reason strong { font-size: 12px; font-weight: 500; flex-shrink: 0; white-space: nowrap; }
.sd-reason small { display: inline-block; margin-left: 12px; font-size: 12px; font-weight: 400; color: var(--color-text-muted); }
.sd-bar { height: 5px; border-radius: 8px; overflow: hidden; background: var(--control-surface); margin-top: 9px; }
.sd-bar span { display: block; height: 100%; border-radius: inherit; background: var(--color-accent); opacity: .65; }
.sd-explanation { margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--color-border); font-size: 12px; color: var(--color-text-muted); line-height: 1.75; }
.sd-explanation summary { cursor: pointer; color: var(--color-text); font-weight: 500; }
.sd-explanation summary span { margin-left: 8px; color: var(--color-text-muted); }
.sd-explanation ul { padding-left: 18px; margin-bottom: 0; }
.sd-written-reason { display: flex; align-items: start; justify-content: space-between; gap: 18px; padding: 12px 0; border-top: 1px solid var(--color-border); }
.sd-written-reason span { overflow-wrap: anywhere; }
.sd-written-reason strong { flex-shrink: 0; font-weight: 500; }
.sd-rehire-summary { display: flex; justify-content: space-between; gap: 20px; align-items: center; padding: 20px 0; margin-bottom: 4px; border-top: 1px solid var(--color-border); flex-wrap: wrap; }
.sd-rehire-summary h3 { margin: 0; font-size: 15px; font-weight: 500; }
.sd-rehire-summary p { margin: 7px 0 0; color: var(--color-text-muted); font-size: 12px; line-height: 1.6; }
.sd-rehire-counts { display: flex; flex-wrap: wrap; gap: 12px; font-size: 12px; color: var(--color-text-muted); }
.sd-rehire-counts span { padding: 8px 12px; border-radius: 12px; background: var(--control-surface); }
.sd-rehire-counts b { color: var(--color-text); font-weight: 500; margin-left: 8px; }
.sd-period-fields { display: flex; align-items: end; gap: 14px; flex-wrap: wrap; margin-top: 14px; }
.sd-period-fields label { flex: 1 1 160px; }
.sd-period-fields input { width: 100%; }
.sd-manual-form { display: grid; gap: 24px; }
.sd-manual-form label { display: grid; gap: 10px; font-size: 13px; }
.sd-manual-form .input { min-width: 0; width: 100%; font-size: 14px; }
.sd-manual-summary { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 24px; align-items: end; }
.sd-manual-summary p { font-size: 12px; color: var(--color-text-muted); line-height: 1.6; margin: 0; }
.sd-form-actions { position: sticky; bottom: -24px; background: var(--color-surface); padding: 16px 0; border-top: 1px solid var(--color-border); z-index: 1; }
@media (max-width: 1000px) { .sd-header { align-items: start; }.sd-detail-value { gap: 10px; }.sd-kpi { padding: 18px; } }
@media (max-width: 760px) {
  .sd-report { padding: 20px; }
  .sd-header { gap: 18px; }
  .sd-title h2 { font-size: 20px; }
  .sd-actions { gap: 8px; }
  .sd-actions .button-sm { font-size: 12px; }
  .sd-summary { grid-template-columns: minmax(0, 1fr); gap: 8px; margin-top: 20px; }
  .sd-kpi { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 4px 14px; padding: 16px 18px; align-items: center; }
  .sd-kpi-label { font-size: 13px; }
  .sd-kpi-value { grid-column: 2; grid-row: 1 / 3; margin: 0; font-size: 30px; }
  .sd-kpi-note { font-size: 11px; margin: 0; }
  .sd-kpi-value small { display: none; }
  .sd-secondary { grid-template-columns: minmax(0, 1fr); }
  .sd-detail-card { padding: 18px; }
  .sd-detail-heading h3 { font-size: 13px; }
  .sd-status { gap: 10px 18px; padding: 18px 0 22px; font-size: 11px; }
  .sd-block-heading h3,.sd-insight h3 { font-size: 16px; }
  .sd-insights { grid-template-columns: minmax(0, 1fr); gap: 24px; margin-top: 24px; padding-top: 24px; }
  .sd-insight + .sd-insight { border-left: 0; padding-left: 0; padding-top: 24px; border-top: 1px solid var(--color-border); }
  .sd-manual-summary { grid-template-columns: minmax(0, 1fr); gap: 10px; }
}
</style>
