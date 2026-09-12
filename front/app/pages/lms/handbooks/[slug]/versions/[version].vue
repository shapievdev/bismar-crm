<script setup lang="ts">
definePageMeta({ middleware: 'auth', permission: 'handbooks.update' })
</script>

<template>
  <MaterialVersionEditor section="handbooks" />
</template>
