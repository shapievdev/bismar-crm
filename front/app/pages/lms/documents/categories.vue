<script setup lang="ts">
definePageMeta({ middleware: 'auth', permission: 'courses.update' })
</script>

<template>
  <MaterialCategories section="documents" />
</template>
