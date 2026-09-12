<script setup lang="ts">
definePageMeta({ middleware: 'auth', permission: 'documents.update' })
</script>

<template>
  <MaterialVersionEditor section="documents" />
</template>
