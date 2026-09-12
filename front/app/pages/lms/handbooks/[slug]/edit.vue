<script setup lang="ts">
definePageMeta({ middleware: 'auth', permission: 'handbooks.update' })
</script>

<template>
  <MaterialEditor section="handbooks" />
</template>
