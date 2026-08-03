<script setup lang="ts">
const props = withDefaults(defineProps<{
  value: number
  size?: number
}>(), { size: 64 })

const clamped = computed(() => Math.min(100, Math.max(0, Math.round(props.value))))

const RADIUS = 20
const CIRCUMFERENCE = 2 * Math.PI * RADIUS

const dashOffset = computed(() => CIRCUMFERENCE * (1 - clamped.value / 100))
</script>

<template>
  <div class="ring" :style="{ width: `${size}px`, height: `${size}px` }">
    <svg viewBox="0 0 48 48" aria-hidden="true">
      <circle class="ring__track" cx="24" cy="24" :r="RADIUS" />
      <circle
        class="ring__value"
        :class="{ 'ring__value--complete': clamped === 100 }"
        cx="24"
        cy="24"
        :r="RADIUS"
        :stroke-dasharray="CIRCUMFERENCE"
        :stroke-dashoffset="dashOffset"
      />
    </svg>
    <span class="ring__label">{{ clamped }}<small>%</small></span>
  </div>
</template>

<style scoped>
.ring {
  position: relative;
  display: inline-grid;
  place-items: center;
  flex-shrink: 0;
}

svg {
  width: 100%;
  height: 100%;
  /* Start the arc at twelve o'clock. */
  transform: rotate(-90deg);
}

circle {
  fill: none;
  stroke-width: 4;
  stroke-linecap: round;
}

.ring__track {
  stroke: var(--color-surface-sunken);
}

.ring__value {
  stroke: var(--color-accent);
  transition: stroke-dashoffset 0.35s ease;
}

.ring__value--complete {
  stroke: var(--color-success);
}

.ring__label {
  position: absolute;
  font-size: 0.82rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.ring__label small {
  font-size: 0.65em;
  opacity: 0.7;
}

@media (prefers-reduced-motion: reduce) {
  .ring__value { transition: none; }
}
</style>
