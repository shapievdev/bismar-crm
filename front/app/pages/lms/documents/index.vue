<script setup lang="ts">
/**
 * Раздел документов — правил, по которым работают.
 *
 * Экран общий со справочниками: устроены они одинаково, и различает их только
 * раздел (см. MaterialCatalogue и useMaterialSection).
 *
 * Прежний вид адреса — `?category=…` — переводится на новый посредником:
 * ссылки с ним лежат у людей в закладках и в отправленных сообщениях.
 */
definePageMeta({
  middleware: ['auth', 'category-in-the-path'],
  permission: 'documents.view',
})
</script>

<template>
  <MaterialCatalogue section="documents" />
</template>
