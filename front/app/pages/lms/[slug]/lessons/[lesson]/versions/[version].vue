<script setup lang="ts">
/**
 * Правка версии урока — тот же экран, что у версии документа (2026-09-25).
 *
 * Своё у неё: статья, запись, файлы, проверка и опрос. Название урока, его
 * место в модуле и приложенные документы — общие, и правятся там же, где
 * правились всегда.
 */
definePageMeta({ middleware: 'auth', permission: 'courses.update' })

const route = useRoute()

const courseSlug = computed(() => String(route.params.slug))
const lessonId = computed(() => Number(route.params.lesson))

const { fetchLesson } = useLmsApi()

// Название урока — ради ссылки «назад»: редактор версии знает только про саму
// версию, и заголовок материала ему приносят снаружи.
const { data } = await useAsyncData(
  () => `lms.lesson.${lessonId.value}.title`,
  async () => (await fetchLesson(lessonId.value)).data,
)
</script>

<template>
  <MaterialVersionEditor
    :target="{ kind: 'lesson', lessonId, courseSlug }"
    :material-title="data?.title ?? 'Урок'"
    article-placeholder="Текст урока для этих людей. Можно вставить картинку или видео."
    credit-label="урок зачтётся пройденным"
  />
</template>
