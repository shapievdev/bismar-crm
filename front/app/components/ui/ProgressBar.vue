<script setup lang="ts">
const props = withDefaults(defineProps<{
  value: number
  label?: string
  size?: 'sm' | 'md'
}>(), { size: 'md' })

const clamped = computed(() => Math.min(100, Math.max(0, Math.round(props.value))))
</script>

<template>
  <div class="wrap">
    <div
      class="track"
      :class="`track--${size}`"
      role="progressbar"
      :aria-valuenow="clamped"
      aria-valuemin="0"
      aria-valuemax="100"
      :aria-label="label ?? 'Прогресс'"
    >
      <div class="fill" :class="{ 'fill--complete': clamped === 100 }" :style="{ width: `${clamped}%` }" />
    </div>
    <span v-if="label" class="label">{{ label }}</span>
  </div>
</template>

<style scoped>
.wrap {
  display: flex;
  align-items: center;
  gap: 0.6rem;
}

.track {
  flex: 1;
  background: var(--color-surface-sunken);
  border-radius: var(--radius-pill);
  overflow: hidden;
}

.track--sm { height: 0.3rem; }
.track--md { height: 0.5rem; }

.fill {
  height: 100%;
  background: var(--color-accent);
  border-radius: inherit;
  transition: width 0.25s ease;
}

.fill--complete {
  background: var(--color-success);
}

.label {
  color: var(--color-text-muted);
  font-size: 0.83rem;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

@media (prefers-reduced-motion: reduce) {
  .fill { transition: none; }
}
</style>
