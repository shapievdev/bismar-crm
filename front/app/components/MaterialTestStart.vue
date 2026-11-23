<script setup lang="ts">
defineProps<{ title?: string, disabled?: boolean, reason?: string }>()
defineEmits<{ start: [] }>()
</script>
<template>
  <section class="card test-start">
    <h2>{{ title || 'Проверка знаний' }}</h2>
    <p class="faint">Готовы проверить знания? Во время теста текст, видео и файлы материала будут скрыты. После отправки ответов материал снова станет доступен.</p>
    <p v-if="reason" class="faint">{{ reason }}</p>
    <button type="button" class="button-primary" :disabled="disabled" @click="$emit('start')">Пройти тест</button>
  </section>
</template>
<style scoped>
.test-start { padding: 1.5rem; }.test-start h2 { margin: 0 0 .75rem; font-size: 1.2rem; }.test-start p { line-height: 1.6; margin: 0 0 1rem; }
</style>
