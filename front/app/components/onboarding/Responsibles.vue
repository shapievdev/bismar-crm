<script setup lang="ts">
const props = withDefaults(defineProps<{ label?: string, hint?: string }>(), { label: 'Ответственные за этап', hint: 'Им придёт уведомление о начале этапа.' })
const pickerId = useId()
const ids = defineModel<number[]>({ required: true })
const api = useOnboardingApi()
const query = ref('')
const { data, status, error } = useAsyncData(`onboarding.responsibles.${pickerId}`, () => api.searchPeople(query.value, ids.value), { watch: [query] })
const candidates = computed(() => data.value?.data ?? [])
</script>
<template>
  <fieldset class="people">
    <legend class="field-label">{{ props.label }}</legend>
    <input v-model="query" class="input" placeholder="Найти сотрудника" :aria-label="`Поиск: ${props.label}`">
    <p v-if="error" class="faint" role="alert">Не удалось загрузить сотрудников. Попробуйте изменить запрос.</p>
    <p v-else-if="status === 'pending'" class="faint">Поиск сотрудников…</p>
    <div class="people__list">
      <label v-for="person in candidates" :key="person.id" class="choice">
        <input v-model="ids" type="checkbox" :value="person.id">{{ person.name }}
      </label>
    </div>
    <span class="faint">Выбрано: {{ ids.length }}. {{ props.hint }}</span>
  </fieldset>
</template>
<style scoped>.people { border: 0; padding: 0; display: grid; gap: .6rem; } .people__list { max-height: 12rem; overflow: auto; display: grid; gap: .4rem; }.people .choice { display: flex; align-items: center; gap: .6rem; min-height: 2rem; font-size: .875rem; cursor: pointer; } .people input[type="checkbox"] { flex-shrink: 0; }</style>
