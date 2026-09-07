<script setup lang="ts">
const { htmlAttribute, paletteAttribute } = useTheme()

useHead({
  titleTemplate: title => (title ? `${title} — Bismar CRM` : 'Bismar CRM'),
  // Stamped into the server-rendered HTML, so a chosen theme and palette are
  // already in place on first paint instead of flashing the other one.
  htmlAttrs: { 'data-theme': htmlAttribute, 'data-palette': paletteAttribute },
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