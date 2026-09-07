<script setup lang="ts">
definePageMeta({ middleware: 'auth', permission: 'courses.create' })
</script>

<template>
  <MaterialCreator section="documents" />
</template>
