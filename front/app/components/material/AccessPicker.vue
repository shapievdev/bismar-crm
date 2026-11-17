<script setup lang="ts">
import type { AccessDepartment, AccessPickerFilters, AccessPickerOptions, AccessSelection, CoursePerson } from '~/types/lms'
import { flattenDepartments } from '~/utils/departments'

const props = defineProps<{
  open: boolean
  mode: 'people' | 'departments'
  load: (filters: AccessPickerFilters) => Promise<AccessPickerOptions>
  people: CoursePerson[]
  departments: AccessDepartment[]
  isSaving: boolean
  errorMessage?: string | null
}>()
const emit = defineEmits<{ close: [], select: [selection: AccessSelection] }>()
const jobInputId = useId()
const options = ref<AccessPickerOptions | null>(null)
const jobTitle = ref('')
const query = ref('')
const selectedPeopleIds = ref<number[]>([])
const selectedDepartmentIds = ref<number[]>([])
const peopleById = shallowRef(new Map<number, CoursePerson>())
const isLoading = ref(false)
const loadError = ref<string | null>(null)
const submitted = ref(false)
let generation = 0
let timer: ReturnType<typeof setTimeout> | undefined
const alreadyPeople = computed(() => new Set(props.people.map(person => person.id)))
const alreadyDepartments = computed(() => new Set(props.departments.map(unit => unit.id)))
const candidates = computed(() => (options.value?.people ?? []).filter(person => !alreadyPeople.value.has(person.id)))
const tree = computed(() => flattenDepartments(options.value?.departments ?? []))
const units = computed(() => {
  const term = query.value.trim().toLocaleLowerCase('ru')
  return tree.value.filter(unit => !term || `${unit.name} ${unit.parent ?? ''}`.toLocaleLowerCase('ru').includes(term))
})
const chosenPeople = computed(() => selectedPeopleIds.value.map(id => peopleById.value.get(id)).filter((person): person is CoursePerson => person !== undefined && !alreadyPeople.value.has(person.id)))
const chosenDepartments = computed(() => tree.value.filter(unit => selectedDepartmentIds.value.includes(unit.id) && !alreadyDepartments.value.has(unit.id)))
const selectedCount = computed(() => props.mode === 'people' ? chosenPeople.value.length : chosenDepartments.value.length)

async function loadOptions(token: number) {
  isLoading.value = true
  loadError.value = null
  try {
    const next = await props.load({ job_title: jobTitle.value || undefined, search: props.mode === 'people' ? query.value.trim() || undefined : undefined })
    if (token !== generation) return
    options.value = next
    peopleById.value = new Map([...peopleById.value, ...next.people.map(person => [person.id, person] as const)])
  }
  catch {
    if (token === generation) loadError.value = 'Не удалось загрузить список. Попробуйте ещё раз.'
  }
  finally { if (token === generation) isLoading.value = false }
}
watch(() => props.open, open => {
  clearTimeout(timer)
  const token = ++generation
  if (!open) return
  options.value = null
  jobTitle.value = ''
  query.value = ''
  selectedPeopleIds.value = []
  selectedDepartmentIds.value = []
  peopleById.value = new Map()
  submitted.value = false
  void loadOptions(token)
})
watch([jobTitle, query], () => {
  if (!props.open || !options.value || props.mode !== 'people') return
  clearTimeout(timer)
  const token = ++generation
  isLoading.value = true
  timer = setTimeout(() => { void loadOptions(token) }, 250)
})
watch(() => props.isSaving, (saving, wasSaving) => {
  if (wasSaving && !saving && submitted.value) {
    submitted.value = false
    if (!props.errorMessage) emit('close')
  }
})
onBeforeUnmount(() => { clearTimeout(timer); generation++ })
function selectVisible() {
  selectedPeopleIds.value = [...new Set([...selectedPeopleIds.value, ...candidates.value.map(person => person.id)])]
}
function submit() {
  if (!selectedCount.value || isLoading.value || props.isSaving) return
  submitted.value = true
  emit('select', { people: chosenPeople.value, departments: chosenDepartments.value.map(({ id, name, parent }) => ({ id, name, parent })) })
}
</script>

<template>
  <AppSheet :open="open" :title="mode === 'people' ? 'Добавить сотрудников по должности' : 'Допуск по отделам'" wide @close="!isSaving && emit('close')">
    <div class="access-picker">
      <p class="faint">{{ mode === 'people' ? 'Выберите должность и отметьте сотрудников. Можно добавить всех найденных сразу.' : 'Выберите отделы из структуры компании. Доступ получат их сотрудники, включая вложенные отделы и новых сотрудников этих отделов.' }}</p>
      <p v-if="errorMessage" class="alert alert--danger" role="alert">{{ errorMessage }}</p>
      <p v-if="loadError" class="alert alert--danger" role="alert">{{ loadError }} <button type="button" class="button-secondary" @click="loadOptions(++generation)">Повторить</button></p>
      <div v-if="mode === 'people'" class="field">
        <label :for="jobInputId">Должность</label>
        <UiSelect :id="jobInputId" v-model="jobTitle" :options="[{ value: '', label: 'Выберите должность' }, ...(options?.job_titles ?? []).map(title => ({ value: title, label: title }))]" searchable :disabled="isSaving" />
      </div>
      <label class="field">
        <span>{{ mode === 'people' ? 'Поиск сотрудника' : 'Поиск отдела' }}</span>
        <input v-model="query" class="input" type="search" :disabled="isSaving" :placeholder="mode === 'people' ? 'Фамилия или имя' : 'Название отдела'">
      </label>
      <p v-if="isLoading" class="faint" role="status">Загружаем…</p>
      <template v-else-if="mode === 'people'">
        <div v-if="candidates.length" class="selection-actions">
          <span>Найдено: {{ candidates.length }}</span>
          <button type="button" class="button-secondary" :disabled="isSaving" @click="selectVisible">Выбрать всех найденных</button>
          <button type="button" class="button-ghost" :disabled="isSaving" @click="selectedPeopleIds = []">Снять выбор</button>
        </div>
        <div class="candidate-list">
          <label v-for="person in candidates" :key="person.id" class="candidate">
            <input v-model="selectedPeopleIds" type="checkbox" :value="person.id" :disabled="isSaving">
            <span>{{ person.name }}<small v-if="person.email">{{ person.email }}</small></span>
          </label>
        </div>
        <p v-if="!candidates.length" class="faint">{{ jobTitle ? 'Нет подходящих сотрудников без личного допуска.' : 'Сначала выберите должность.' }}</p>
        <details v-if="chosenPeople.length" class="chosen">
          <summary>Выбрано сотрудников: {{ chosenPeople.length }}</summary>
          <p v-for="person in chosenPeople" :key="person.id">{{ person.name }}</p>
        </details>
        <p class="faint">Добавляются выбранные сотрудники. При последующем изменении их должности личный допуск сохраняется.</p>
      </template>
      <div v-else class="candidate-list">
        <label v-for="unit in units" :key="unit.id" class="candidate" :style="{ paddingLeft: `${Math.min(unit.depth, 8) * 16 + 12}px` }">
          <input v-model="selectedDepartmentIds" type="checkbox" :value="unit.id" :disabled="isSaving || alreadyDepartments.has(unit.id)">
          <span>{{ unit.name }}<small v-if="alreadyDepartments.has(unit.id)">Уже допущен</small><small v-else-if="unit.parent">{{ unit.parent }}</small></span>
        </label>
        <p v-if="!units.length && !loadError" class="faint">Отделы не найдены.</p>
      </div>
      <div class="picker-footer">
        <button type="button" class="button-primary" :disabled="!selectedCount || isLoading || isSaving" @click="submit">{{ isSaving ? 'Сохраняем…' : `Добавить выбранных (${selectedCount})` }}</button>
        <button type="button" class="button-ghost" :disabled="isSaving" @click="emit('close')">Отмена</button>
      </div>
    </div>
  </AppSheet>
</template>
<style scoped>
.access-picker { display:grid; gap:1rem; }
.field { display:grid; gap:.5rem; font-size:.9rem; }
.input { width:100%; border:1px solid var(--color-border); border-radius:var(--radius-sm); background:var(--control-surface); color:var(--color-text); padding:.75rem 1rem; font:inherit; }
.candidate-list { max-height:22rem; overflow:auto; }
.candidate { display:flex; gap:.75rem; align-items:center; padding:.75rem; border-bottom:1px solid var(--color-border); cursor:pointer; font-size:.9rem; }
.candidate input { flex-shrink:0; }
.candidate small { display:block; color:var(--color-text-muted); font-size:.78rem; margin-top:.2rem; }
.selection-actions,.picker-footer { display:flex; gap:.6rem; align-items:center; flex-wrap:wrap; }
.selection-actions { font-size:.85rem; }
.picker-footer { position:sticky; bottom:-1.4rem; background:var(--color-surface-raised); padding:1rem 0; border-top:1px solid var(--color-border); }
.chosen summary { cursor:pointer; font-size:.9rem; }
.chosen p { font-size:.85rem; margin:.4rem 0; }
.faint { color:var(--color-text-muted); font-size:.85rem; margin:0; line-height:1.5; }
</style>
