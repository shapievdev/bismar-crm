<script setup lang="ts">
/**
 * Раздел документов — правил, по которым работают.
 *
 * Экран общий со справочниками: устроены они одинаково, и различает их только
 * раздел (см. MaterialCatalogue и useMaterialSection).
 */
definePageMeta({ middleware: 'auth', permission: 'documents.view' })
</script>

<template>
  <MaterialCatalogue section="documents" />
</template>
