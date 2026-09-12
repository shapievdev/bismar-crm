<script setup lang="ts">
definePageMeta({ middleware: 'auth', permission: 'handbooks.create' })
</script>

<template>
  <MaterialCreator section="handbooks" />
</template>
