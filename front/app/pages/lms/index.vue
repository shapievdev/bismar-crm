<script setup lang="ts">
/**
 * Корень базы знаний: все категории курсов.
 *
 * Сам экран — в CourseCatalogue: он же показывает и одну категорию по адресу
 * `/lms/category/…`, а два маршрута одной страницей не обслужить.
 *
 * Прежний вид адреса — `?category=…` — переводится на новый посредником:
 * ссылки с ним лежат у людей в закладках и в отправленных сообщениях.
 */
definePageMeta({
  middleware: ['auth', 'category-in-the-path'],
  permission: 'courses.view',
})

useHead({ title: 'База знаний' })
</script>

<template>
  <CourseCatalogue />
</template>
