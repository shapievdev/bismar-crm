<script setup lang="ts">
definePageMeta({ middleware: 'auth', permission: 'documents.view' })
</script>

<template>
  <MaterialPage section="documents" />
</template>
