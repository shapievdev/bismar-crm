<script setup lang="ts">
const { htmlAttribute } = useTheme()

useHead({
  titleTemplate: title => (title ? `${title} — Bismar CRM` : 'Bismar CRM'),
  // Stamped into the server-rendered HTML, so a chosen theme is already in
  // place on first paint instead of flashing the other one.
  htmlAttrs: { 'data-theme': htmlAttribute },
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