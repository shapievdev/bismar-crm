<script setup lang="ts">
import type { SurveySummary } from '~/types/survey'

/**
 * Сводка опроса, которую компонент спрашивает сам.
 *
 * Откуда её брать, говорит тот, кто ставит панель: опрос висит и при уроке, и
 * при документе, и при новости, а панель об их различиях знать не должна.
 *
 * Показ живёт отдельно (SurveySummaryView): в отчёте аналитики сводка приходит
 * вместе со списком прошедших одним ответом, и там нужен показ без загрузки.
 */
const props = defineProps<{
  load: () => Promise<SurveySummary>
}>()

const summary = ref<SurveySummary | null>(null)
const isLoading = ref(true)
const errorMessage = ref<string | null>(null)

onMounted(async () => {
  try {
    summary.value = await props.load()
  }
  catch {
    errorMessage.value = 'Не удалось загрузить ответы.'
  }
  finally {
    isLoading.value = false
  }
})
</script>

<template>
  <div>
    <p v-if="isLoading" class="faint note">Читаем ответы…</p>
    <p v-else-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>
    <SurveySummaryView v-else-if="summary" :summary="summary" />
  </div>
</template>

<style scoped>
.note,
.error {
  margin: 0;
  font-size: 0.85rem;
}

.error {
  color: var(--color-danger);
}
</style>
