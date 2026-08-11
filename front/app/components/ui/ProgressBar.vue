<script setup lang="ts">
const props = withDefaults(defineProps<{
  value: number
  label?: string
  size?: 'sm' | 'md'
  /**
   * Work is under way but there is no share of it to report — a file that has
   * finished travelling and is now being stored. A bar parked at 100% would
   * claim the job was done, so it keeps moving without claiming a position.
   */
  indeterminate?: boolean
}>(), { size: 'md', indeterminate: false })

const clamped = computed(() => Math.min(100, Math.max(0, Math.round(props.value))))
</script>

<template>
  <div class="wrap">
    <div
      class="track"
      :class="`track--${size}`"
      role="progressbar"
      :aria-valuenow="indeterminate ? undefined : clamped"
      aria-valuemin="0"
      aria-valuemax="100"
      :aria-label="label ?? 'Прогресс'"
    >
      <div
        class="fill"
        :class="{
          'fill--complete': !indeterminate && clamped === 100,
          'fill--indeterminate': indeterminate,
        }"
        :style="indeterminate ? undefined : { width: `${clamped}%` }"
      />
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
  background: var(--color-text);
  border-radius: inherit;
  transition: width 0.25s ease;
}

.fill--complete {
  background: var(--color-highlight);
}

/* A short bar sweeping the track: motion says "still working" where a position
   cannot. */
.fill--indeterminate {
  width: 35%;
  transition: none;
  animation: progress-sweep 1.2s ease-in-out infinite;
}

@keyframes progress-sweep {
  from { transform: translateX(-100%); }
  to { transform: translateX(286%); }
}

.label {
  color: var(--color-text-muted);
  font-size: 0.83rem;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

@media (prefers-reduced-motion: reduce) {
  .fill { transition: none; }

  /* Nothing left to animate, so the bar simply fills the track and the label
     beside it carries the message on its own. */
  .fill--indeterminate {
    width: 100%;
    animation: none;
  }
}
</style>
