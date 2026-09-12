<script setup lang="ts">
definePageMeta({ middleware: 'auth', permission: 'documents.update' })
</script>

<template>
  <MaterialEditor section="documents" />
</template>
