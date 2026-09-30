<script setup lang="ts">
/**
 * Одна категория справочников — адресом, а не запросом; см. страницу категории
 * документов и MaterialCatalogue.
 */
definePageMeta({ middleware: 'auth', permission: 'handbooks.view' })

const route = useRoute()

const category = computed(() => String(route.params.category ?? ''))
</script>

<template>
  <MaterialCatalogue section="handbooks" :category="category" />
</template>
