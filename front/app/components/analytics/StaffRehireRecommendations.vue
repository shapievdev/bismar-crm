<script setup lang="ts">
import type { StaffQuery } from '~/types/analytics'
import type { RehireRecommendationList } from '~/types/staff-dismissal'
import { formatDate } from '~/utils/numbers'

const props = defineProps<{ query: StaffQuery, count: number }>()
const { $api } = useNuxtApp()
const { can } = useAuth()
const opened = ref(false), pending = ref(false), error = ref('')
const result = ref<RehireRecommendationList | null>(null)
const search = ref(''), appliedSearch = ref(''), page = ref(1)
let requestNumber = 0
async function load() {
  const request = ++requestNumber
  pending.value = true; error.value = ''
  try {
    const response = await $api<RehireRecommendationList>('/api/analytics/staff/dismissals/rehire', {
      query: { ...props.query, page: page.value, search: appliedSearch.value || undefined },
    })
    if (request === requestNumber) result.value = response
  }
  catch { if (request === requestNumber) error.value = 'Не удалось загрузить список. Попробуйте ещё раз.' }
  finally { if (request === requestNumber) pending.value = false }
}
function toggle(event: Event) {
  opened.value = (event.target as HTMLDetailsElement).open
  if (opened.value) void load()
}
function find() { page.value = 1; appliedSearch.value = search.value.trim(); void load() }
function move(step: number) { page.value += step; void load() }
watch(() => props.query, () => {
  requestNumber++; result.value = null; search.value = ''; appliedSearch.value = ''; page.value = 1
  if (opened.value) void load()
  else pending.value = false
})
</script>

<template>
  <details class="rehire-list" @toggle="toggle">
    <summary><span>Список рекомендованных к повторному трудоустройству</span><b>{{ count }}</b></summary>
    <p class="rehire-note">Уволенные за выбранный период с отметкой «Да». Применяются фильтры отчёта.</p>
    <form class="rehire-search" @submit.prevent="find"><input v-model="search" type="search" class="input" placeholder="Найти по имени" aria-label="Найти рекомендованного сотрудника"><button class="button-secondary button-sm" :disabled="pending">Найти</button></form>
    <p v-if="error" role="alert">{{ error }}</p><p v-else-if="pending" class="rehire-note">Загружаем…</p><p v-else-if="!result?.data.length" class="rehire-note">{{ appliedSearch ? 'Сотрудники не найдены.' : 'За этот период рекомендованных сотрудников пока нет.' }}</p>
    <div v-else class="rehire-people">
      <article v-for="person in result.data" :key="person.id" class="rehire-person">
        <div><NuxtLink v-if="can('users.view')" :to="`/staff/${person.id}`">{{ person.name }}</NuxtLink><strong v-else>{{ person.name }}</strong><p>{{ [person.job_title, ...person.departments].filter(Boolean).join(' · ') || 'Должность и подразделение не указаны' }}</p></div>
        <div class="rehire-person-status"><span>Рекомендован · Да</span><p>Уволен {{ formatDate(person.dismissed_at) }}</p></div>
      </article>
    </div>
    <div v-if="result && result.meta.last_page > 1" class="rehire-pages"><button type="button" class="button-secondary button-sm" :disabled="pending || page <= 1" @click="move(-1)">Назад</button><span>{{ result.meta.current_page }} / {{ result.meta.last_page }}</span><button type="button" class="button-secondary button-sm" :disabled="pending || page >= result.meta.last_page" @click="move(1)">Далее</button></div>
  </details>
</template>

<style scoped>
.rehire-list { border-top: 1px solid var(--color-border); padding-top: 20px; margin-top: 24px; }
.rehire-list summary { cursor: pointer; color: var(--color-text); font-size: 14px; line-height: 1.6; font-weight: 500; }
.rehire-list summary b { display: inline-block; background: var(--color-accent-soft); border-radius: 20px; padding: 2px 10px; margin-left: 10px; font-size: 12px; font-weight: 500; }
.rehire-note { color: var(--color-text-muted); font-size: 12px; line-height: 1.7; margin-top: 12px; }
.rehire-search { display: flex; gap: 10px; margin: 18px 0; }
.rehire-search input { max-width: 360px; min-width: 0; font-size: 13px; }
.rehire-person { display: flex; justify-content: space-between; gap: 20px; padding: 18px 0; border-top: 1px solid var(--color-border); }
.rehire-person a,.rehire-person strong { font-size: 14px; font-weight: 500; }
.rehire-person p { margin: 7px 0 0; font-size: 12px; color: var(--color-text-muted); line-height: 1.6; }
.rehire-person-status { flex-shrink: 0; text-align: right; font-size: 12px; }
.rehire-person-status > span { display: inline-block; padding: 5px 10px; border-radius: 20px; background: var(--control-surface); }
.rehire-pages { display: flex; align-items: center; justify-content: center; gap: 14px; font-size: 12px; padding-top: 12px; }
@media (max-width: 600px) { .rehire-person { flex-direction: column; gap: 12px; }.rehire-person-status { text-align: left; }.rehire-search input { width: 100%; } }
</style>
