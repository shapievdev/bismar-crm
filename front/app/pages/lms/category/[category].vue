<script setup lang="ts">
/**
 * Одна категория курсов — адресом, а не запросом (решение пользователя
 * 2026-09-30): `/lms/category/kassa` пересылают и кладут в закладки.
 *
 * Экран тот же, что у корня, — см. CourseCatalogue. Заголовок страницы здесь
 * общий: название категории приезжает вместе с деревом, то есть уже внутри
 * каталога, и ждать его ради одной строки в заголовке окна незачем.
 */
definePageMeta({ middleware: 'auth', permission: 'courses.view' })

useHead({ title: 'База знаний' })

const route = useRoute()

const category = computed(() => String(route.params.category ?? ''))
</script>

<template>
  <CourseCatalogue :category="category" />
</template>
