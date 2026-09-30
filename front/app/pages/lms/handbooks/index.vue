<script setup lang="ts">
/**
 * Раздел справочников — ответов на ситуацию.
 *
 * Экран общий с документами: см. MaterialCatalogue. Прежний вид адреса —
 * `?category=…` — переводится на новый посредником.
 */
definePageMeta({
  middleware: ['auth', 'category-in-the-path'],
  permission: 'handbooks.view',
})
</script>

<template>
  <MaterialCatalogue section="handbooks" />
</template>
