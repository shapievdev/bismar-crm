<script setup lang="ts">
definePageMeta({ middleware: 'auth', permission: 'courses.update' })
</script>

<template>
  <MaterialEditor section="handbooks" />
</template>
