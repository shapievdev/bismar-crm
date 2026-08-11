<script setup lang="ts">
import type { Freshness } from '~/types/analytics'
import { formatAge, formatDate } from '~/utils/numbers'

/**
 * По какое число доехали данные.
 *
 * Стоит на каждой вкладке, потому что выгрузки идут вразнобой: продажи и чеки
 * приходят ночью, долги отстают на день, а остатки застряли в марте. Человек,
 * который сверяет склад с выручкой и не знает об этом, решит, что сходится
 * плохо учёт, а не выгрузка.
 */
const props = defineProps<{
  freshness: Freshness[]
  /** Какие источники относятся к этой вкладке. */
  sources: string[]
}>()

/** Больше суток — данные уже не сегодняшние, и это стоит сказать вслух. */
const STALE_AFTER_DAYS = 1

const shown = computed(() =>
  props.freshness.filter(item => props.sources.includes(item.source)),
)

const stale = computed(() => shown.value.filter(item => item.age_days > STALE_AFTER_DAYS))
</script>

<template>
  <p v-if="shown.length" class="freshness" :class="{ 'freshness--stale': stale.length > 0 }">
    <span v-for="(item, index) in shown" :key="item.source">
      <template v-if="index > 0"> · </template>
      {{ item.label }}: {{ formatDate(item.last_date) }}
      <span class="freshness__age">({{ formatAge(item.age_days) }})</span>
    </span>
  </p>
</template>

<style scoped>
.freshness {
  margin: 0 0 1rem;
  font-size: 0.76rem;
  color: var(--color-text-faint);
}

/* Отставшая выгрузка — предупреждение, а не сноска. */
.freshness--stale {
  color: var(--color-warning);
}

.freshness__age {
  opacity: 0.85;
}
</style>