<script setup lang="ts">
import type { ExitSurveyDetail, ExitSurveyReport, ExitSurveyRow } from '~/types/exit-survey'
definePageMeta({ middleware: 'auth', permission: 'users.manage' })
useHead({ title: 'Выходные анкеты — Аналитика' })
const { $api } = useNuxtApp()
const status = ref(''), search = ref(''), department = ref(''), from = ref(''), to = ref('')
const page = ref(1)
const applied = ref<Record<string, string>>({})
const { data, pending, error, refresh } = await useAsyncData('analytics-exit-surveys', () => $api<ExitSurveyReport>('/api/analytics/exit-surveys', { query: { ...applied.value, page: page.value } }), { watch: [applied, page] })
const detail = ref<ExitSurveyDetail | null>(null)
const dialog = ref<HTMLDialogElement | null>(null)
const opening = ref<number | null>(null)
const detailError = ref('')
const labels = { pending: 'Ожидается заполнение', submitted: 'Заполнена', cancelled: 'Отменена: сотрудник восстановлен' }
function apply() { page.value = 1; applied.value = Object.fromEntries(Object.entries({ status: status.value, search: search.value, department: department.value, from: from.value, to: to.value }).filter(([, value]) => value)) }
function date(value: string | null) { return value ? new Date(value).toLocaleDateString('ru-RU') : '—' }
async function open(row: ExitSurveyRow) {
  opening.value = row.id; detailError.value = ''
  try { detail.value = (await $api<{ data: ExitSurveyDetail }>(`/api/analytics/exit-surveys/${row.id}`)).data; await nextTick(); dialog.value?.showModal() }
  catch (caught) { detailError.value = messageFromError(caught, 'Не удалось открыть ответы. Попробуйте ещё раз.') }
  finally { opening.value = null }
}
function answer(id: string) { const value = detail.value?.answers?.[id]; return value === 'other' ? `Другое: ${detail.value?.answers?.[`${id}_other`] ?? ''}` : value || 'Не указано' }
</script>
<template>
  <div class="exit-report">
    <header class="heading"><div><h1>Выходные анкеты</h1><p class="muted">Обратная связь сотрудников после увольнения. Анкеты назначаются при новых увольнениях.</p></div><button class="button-secondary" :disabled="pending" @click="refresh()">Обновить</button></header>
    <div v-if="data" class="totals"><div v-for="key in (['pending', 'submitted', 'cancelled'] as const)" :key="key" class="card"><strong>{{ data.summary[key] }}</strong><span>{{ labels[key] }}</span></div></div>
    <form class="filters card" @submit.prevent="apply">
      <label>Сотрудник<input v-model="search" class="input" placeholder="Поиск по имени"></label>
      <label>Статус<select v-model="status" class="input"><option value="">Все анкеты</option><option value="pending">Ожидается заполнение</option><option value="submitted">Заполнены</option><option value="cancelled">Отменены</option></select></label>
      <label>Подразделение<select v-model="department" class="input"><option value="">Все подразделения</option><option v-for="item in data?.departments" :key="item">{{ item }}</option></select></label>
      <label>Уволены с<input v-model="from" type="date" class="input"></label><label>По<input v-model="to" type="date" class="input" :min="from"></label><button class="button-primary" :disabled="pending">Показать</button>
    </form>
    <p v-if="error" role="alert">Не удалось загрузить анкеты. Проверьте фильтры и попробуйте ещё раз.</p><p v-if="detailError" role="alert">{{ detailError }}</p>
    <section class="card" :aria-busy="pending"><h2>Сотрудники <span v-if="data" class="muted">· {{ data.meta.total }}</span></h2><p v-if="pending" class="muted">Загружаем…</p><p v-else-if="!data?.data.length" class="muted">Анкет по выбранным условиям пока нет.</p>
      <div v-for="row in data?.data" :key="row.id" class="row"><div><NuxtLink v-if="row.user_id" :to="`/staff/${row.user_id}`">{{ row.employee.name }}</NuxtLink><strong v-else>{{ row.employee.name }}</strong><p class="muted">{{ [row.employee.department, row.employee.job_title].filter(Boolean).join(' · ') || 'Подразделение не указано' }}</p></div><div><span class="badge" :class="row.status === 'pending' ? 'badge--warning' : ''">{{ labels[row.status] }}</span><p class="muted">Уволен {{ date(row.dismissed_at) }}<template v-if="row.submitted_at"> · Ответил {{ date(row.submitted_at) }}</template></p></div><button v-if="row.status === 'submitted'" class="button-secondary" :disabled="opening !== null" @click="open(row)">{{ opening === row.id ? 'Открываем…' : 'Ответы' }}</button></div>
      <div v-if="data && data.meta.last_page > 1" class="pagination"><button class="button-secondary" :disabled="page === 1 || pending" @click="page--">Назад</button><span>{{ page }} / {{ data.meta.last_page }}</span><button class="button-secondary" :disabled="page === data.meta.last_page || pending" @click="page++">Далее</button></div>
    </section>
    <section v-if="data?.breakdowns.length" class="card"><h2>Что говорят сотрудники</h2><p class="muted">Распределение ответов по выбранному периоду, подразделению и поиску. Фильтр статуса применяется к списку сотрудников.</p><details v-for="item in data.breakdowns" :key="item.label" class="distribution"><summary>{{ item.label }}</summary><div v-for="(count, option) in item.options" :key="option" class="answer"><span>{{ option }}</span><strong>{{ count }}</strong></div></details></section>
    <dialog ref="dialog" class="detail" aria-labelledby="exit-detail-title" @click="($event.target === dialog) && dialog?.close()"><template v-if="detail"><header class="heading"><div><h2 id="exit-detail-title">{{ detail.employee.name }}</h2><p class="muted">Ответы от {{ date(detail.submitted_at) }}</p></div><button class="button-secondary" autofocus @click="dialog?.close()">Закрыть</button></header><dl><template v-for="field in detail.definition.fields" :key="field.id"><dt>{{ field.label }}</dt><dd>{{ answer(field.id) }}</dd></template></dl></template></dialog>
  </div>
</template>
<style scoped>
.exit-report { display: grid; gap: 24px; }.heading { display: flex; align-items: start; justify-content: space-between; gap: 20px; }h1 { margin: 0 0 12px; }h2 { margin: 0 0 16px; font-size: 23px; }
.card { background: var(--color-surface); border-radius: 24px; padding: 28px; min-width: 0; }.totals { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }.totals .card { display: grid; gap: 12px; }.totals strong { font-size: 36px; }
.filters { display: flex; align-items: end; flex-wrap: wrap; gap: 16px; }.filters label { display: grid; gap: 8px; flex: 1 1 160px; }.input { min-width: 0; width: 100%; }
.row { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto; gap: 20px; align-items: center; border-top: 1px solid var(--color-border); padding: 20px 0; }.row p { margin: 8px 0 0; font-size: 14px; }.row a { font-weight: 600; }.pagination { display: flex; justify-content: center; align-items: center; gap: 20px; margin-top: 20px; }
.distribution { border-top: 1px solid var(--color-border); padding: 20px 0; }.distribution summary { cursor: pointer; font-weight: 600; line-height: 1.5; }.answer { display: flex; justify-content: space-between; gap: 24px; padding: 12px 0 0; line-height: 1.5; }
.detail { width: min(800px, calc(100vw - 24px)); max-height: 85dvh; overflow: auto; border: 0; border-radius: 24px; padding: 32px; background: var(--color-surface); color: var(--color-text); }.detail::backdrop { background: rgb(0 0 0 / 45%); }dt { font-weight: 600; padding-top: 20px; line-height: 1.5; }dd { margin: 10px 0 0; padding-bottom: 20px; border-bottom: 1px solid var(--color-border); white-space: pre-wrap; overflow-wrap: anywhere; line-height: 1.6; }
@media (max-width: 700px) { .heading { flex-wrap: wrap; }.totals { grid-template-columns: minmax(0, 1fr); gap: 10px; }.totals .card { display: flex; align-items: center; gap: 20px; padding: 20px; }.card { padding: 20px; }.row { grid-template-columns: minmax(0, 1fr); gap: 12px; }.row button { justify-self: start; }.detail { padding: 22px; } }
</style>
