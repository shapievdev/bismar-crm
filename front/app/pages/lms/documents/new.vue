<script setup lang="ts">
definePageMeta({ middleware: 'auth', permission: 'documents.create' })
</script>

<template>
  <MaterialCreator section="documents" />
</template>
