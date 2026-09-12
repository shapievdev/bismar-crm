<script setup lang="ts">
definePageMeta({ middleware: 'auth', permission: 'documents.update' })
</script>

<template>
  <MaterialCategories section="documents" />
</template>
