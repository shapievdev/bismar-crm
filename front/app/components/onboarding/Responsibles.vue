<script setup lang="ts">
const ids = defineModel<number[]>({ required: true })
const api = useOnboardingApi()
const query = ref('')
const { data } = await useAsyncData('onboarding.responsibles', () => api.searchPeople(query.value), { watch: [query] })
const candidates = computed(() => data.value?.data ?? [])
</script>
<template>
  <fieldset class="people">
    <legend class="field-label">Ответственные за этап</legend>
    <input v-model="query" class="input" placeholder="Найти сотрудника" aria-label="Поиск ответственных">
    <div class="people__list">
      <label v-for="person in candidates" :key="person.id" class="choice">
        <input v-model="ids" type="checkbox" :value="person.id">{{ person.name }}
      </label>
    </div>
    <span class="faint">Выбрано: {{ ids.length }}. Им придёт уведомление о начале этапа.</span>
  </fieldset>
</template>
<style scoped>.people { border: 0; padding: 0; display: grid; gap: .6rem; } .people__list { max-height: 12rem; overflow: auto; display: grid; gap: .4rem; }</style>
