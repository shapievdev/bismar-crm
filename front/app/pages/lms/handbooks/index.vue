<script setup lang="ts">
/**
 * Раздел справочников — ответов на ситуацию.
 *
 * Экран общий с документами: см. MaterialCatalogue.
 */
definePageMeta({ middleware: 'auth', permission: 'handbooks.view' })
</script>

<template>
  <MaterialCatalogue section="handbooks" />
</template>
