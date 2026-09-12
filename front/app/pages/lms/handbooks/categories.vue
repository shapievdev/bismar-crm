<script setup lang="ts">
definePageMeta({ middleware: 'auth', permission: 'handbooks.update' })
</script>

<template>
  <MaterialCategories section="handbooks" />
</template>
