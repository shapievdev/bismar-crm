<script setup lang="ts">
defineProps<{
  title: string
  description?: string
}>()
</script>

<template>
  <div class="empty-state">
    <p class="empty-state__title">
      {{ title }}
    </p>
    <p v-if="description" class="empty-state__text">
      {{ description }}
    </p>
    <div class="empty-state__actions">
      <slot />
    </div>
  </div>
</template>

<style scoped>
.empty-state__text {
  margin: 0;
  font-size: 0.92rem;
}

.empty-state__actions:not(:empty) {
  margin-top: 1.1rem;
}
</style>
