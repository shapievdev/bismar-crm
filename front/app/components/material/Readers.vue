<script setup lang="ts">
interface Person { id: number, name: string, job_title: string | null, avatar_url: string | null, acknowledged_at: string | null }
interface Readership { total: number, acknowledged: Person[], pending: Person[] }
const props = withDefaults(defineProps<{ endpoint: string, kind?: 'material' | 'course' | 'lesson' }>(), { kind: 'material' })
const { $api } = useNuxtApp()
const isOpen = ref(false)
const loading = ref(false)
const error = ref('')
const report = ref<Readership | null>(null)
const search = ref('')
let generation = 0
async function load() {
  const current = ++generation
  loading.value = true; error.value = ''
  try {
    const response = await $api<{ data: Readership }>(props.endpoint)
    if (current === generation) report.value = response.data
  }
  catch { if (current === generation) error.value = 'Не удалось загрузить список. Попробуйте ещё раз.' }
  finally { if (current === generation) loading.value = false }
}
function open() { isOpen.value = true; search.value = ''; report.value = null; void load() }
function close() { isOpen.value = false; generation++; loading.value = false }
watch(() => props.endpoint, () => { report.value = null; if (isOpen.value) void load() })
function filtered(people: Person[]) {
  const term = search.value.trim().toLocaleLowerCase('ru-RU')
  return term ? people.filter(person => `${person.name} ${person.job_title ?? ''}`.toLocaleLowerCase('ru-RU').includes(term)) : people
}
function date(value: string | null) { return value ? new Date(value).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' }) : '' }
const groups = computed(() => report.value ? [
  { key: 'done', label: props.kind === 'course' ? 'Прошли курс' : props.kind === 'lesson' ? 'Прошли урок' : 'Ознакомились', people: report.value.acknowledged },
  { key: 'pending', label: props.kind === 'course' ? 'Ещё не прошли курс' : props.kind === 'lesson' ? 'Ещё не прошли урок' : 'Ещё не ознакомились', people: report.value.pending },
] : [])
</script>
<template>
  <button type="button" class="button-secondary button-sm" @click="open">Кто ознакомился</button>
  <AppSheet :open="isOpen" wide title="Кто ознакомился" @close="close">
    <p class="faint">Учитываются действующие сотрудники, которым открыт материал: лично, через группы или отделы. Для открытого материала — все с правом чтения раздела.</p>
    <p class="faint">{{ kind === 'course' ? 'Курс считается пройденным после завершения обучения.' : kind === 'lesson' ? 'Урок считается пройденным после выполнения условий прохождения.' : 'Ознакомление засчитывается после подтверждения и выполнения обязательных проверок.' }} Само открытие страницы не считается ознакомлением.</p>
    <p v-if="loading" class="faint" role="status">Загружаем сотрудников…</p>
    <div v-else-if="error" role="alert"><p class="alert alert--danger">{{ error }}</p><button class="button-secondary" @click="load">Попробовать снова</button></div>
    <template v-else-if="report">
      <div class="readers-toolbar"><p>Ознакомились {{ report.acknowledged.length }} из {{ report.total }}</p><button type="button" class="button-ghost button-sm" @click="load">Обновить</button></div>
      <label class="readers-search"><span class="field-label">Найти сотрудника</span><input v-model="search" class="input" type="search" placeholder="Имя или должность"></label>
      <p v-if="!report.total" class="faint">Сейчас нет допущенных сотрудников с правом чтения этого материала.</p>
      <div v-else class="readers-columns">
        <section v-for="group in groups" :key="group.key" class="readers-group"><h2>{{ group.label }} <span class="badge" :class="{ 'badge--success': group.key === 'done' }">{{ group.people.length }}</span></h2>
          <p v-if="!filtered(group.people).length" class="faint">{{ search.trim() ? 'По вашему поиску никого нет.' : group.key === 'done' ? 'Пока никто.' : 'Все ознакомились.' }}</p>
          <ul v-else class="readers-list"><li v-for="person in filtered(group.people)" :key="person.id"><UserAvatar :name="person.name" :src="person.avatar_url" :size="32" /><div><span>{{ person.name }}</span><span v-if="person.job_title" class="faint">{{ person.job_title }}</span><span v-if="person.acknowledged_at" class="faint">{{ date(person.acknowledged_at) }}</span></div></li></ul>
        </section>
      </div>
    </template>
  </AppSheet>
</template>
<style scoped>
.readers-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; }.readers-toolbar p { margin: 0; }.readers-search { display: grid; gap: 8px; }.readers-columns { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 28px; }.readers-group { min-width: 0; }.readers-group h2 { font-size: 1.05rem; display: flex; align-items: center; gap: 10px; }.readers-list { list-style: none; padding: 0; margin: 0; }.readers-list li { display: flex; align-items: flex-start; gap: 12px; border-top: 1px solid var(--color-border); padding: 14px 0; }.readers-list li div { display: grid; gap: 5px; min-width: 0; overflow-wrap: anywhere; }.readers-list .faint { font-size: .84rem; }@media (max-width: 680px) { .readers-columns { grid-template-columns: minmax(0, 1fr); gap: 12px; } }
</style>
