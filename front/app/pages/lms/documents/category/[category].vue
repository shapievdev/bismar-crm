<script setup lang="ts">
/**
 * Одна категория документов — адресом, а не запросом (решение пользователя
 * 2026-09-30): `/lms/documents/category/kassa` пересылают и кладут в закладки.
 *
 * Экран тот же, что у корня раздела, — см. MaterialCatalogue.
 */
definePageMeta({ middleware: 'auth', permission: 'documents.view' })

const route = useRoute()

const category = computed(() => String(route.params.category ?? ''))
</script>

<template>
  <MaterialCatalogue section="documents" :category="category" />
</template>
