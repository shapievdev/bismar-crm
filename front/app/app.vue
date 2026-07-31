<script setup lang="ts">
useHead({
  titleTemplate: title => (title ? `${title} — Bismar CRM` : 'Bismar CRM'),
})
</script>

<template>
  <div>
    <NuxtRouteAnnouncer />
    <NuxtLayout>
      <NuxtPage />
    </NuxtLayout>
  </div>
</template>