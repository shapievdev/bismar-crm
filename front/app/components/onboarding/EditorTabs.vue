<script setup lang="ts">
const selected = defineModel<string>({ required: true })
const props = defineProps<{ counts: { materials: number, tasks: number, meetings: number } }>()
const sections = computed(() => [
  { id: 'main', label: 'Основное', count: null },
  { id: 'materials', label: 'Материалы', count: props.counts.materials },
  { id: 'tasks', label: 'Задания', count: props.counts.tasks },
  { id: 'meetings', label: 'Встречи', count: props.counts.meetings },
])
</script>
<template>
  <nav class="editor-tabs" aria-label="Разделы редактора этапа">
    <button v-for="section in sections" :key="section.id" type="button" :aria-pressed="selected === section.id"
      :class="{ active: selected === section.id }" @click="selected = section.id">
      {{ section.label }}<span v-if="section.count !== null">{{ section.count }}</span>
    </button>
  </nav>
</template>
<style scoped>
.editor-tabs { display: flex; gap: .35rem; padding: .35rem; border-radius: var(--radius-sm); background: var(--color-surface-sunken); overflow-x: auto; }
button { border: 0; background: transparent; font: inherit; display: inline-flex; align-items: center; justify-content: center; gap: .4rem; flex: 1; white-space: nowrap; padding: .65rem .8rem; border-radius: var(--radius-sm); font-size: .85rem; color: var(--color-text-muted); cursor: pointer; }
button.active { background: var(--color-accent); color: var(--color-accent-text); }
button span { font-size: .7rem; opacity: .75; }
@media (max-width: 480px) { .editor-tabs { gap: .1rem; } button { padding: .6rem .4rem; font-size: .75rem; } }
</style>
