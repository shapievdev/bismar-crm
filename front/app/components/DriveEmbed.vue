<script setup lang="ts">
/**
 * Файл с Google Диска, показанный на месте.
 *
 * Одна рамка на все места, где такой файл встречается: список материалов урока,
 * страница документа, текст статьи. Адрес приходит готовым — его собирает
 * сервер из номера файла (App\Support\Lms\GoogleDrive), и подставить сюда
 * что-то помимо Google нельзя.
 *
 * Рядом всегда ссылка на сам Диск, и это не украшение. Пустит внутрь рамки не
 * наше приложение, а Google: если сотруднику файл не открыт, он увидит здесь
 * просьбу войти — и должен понимать, куда идти за доступом.
 */
defineProps<{
  /** Адрес просмотра, собранный сервером. */
  src: string
  /** Имя файла — им подписана рамка для тех, кто читает экраном. */
  title: string
  /** Сам файл на Диске: туда уходят за доступом и за полным окном. */
  openUrl: string
}>()
</script>

<template>
  <div class="drive">
    <iframe
      :src="src"
      :title="title"
      class="drive__frame"
      loading="lazy"
      allow="autoplay"
      referrerpolicy="strict-origin-when-cross-origin"
    />

    <p class="faint drive__note">
      Файл на Google Диске.
      <a :href="openUrl" target="_blank" rel="noopener noreferrer">Открыть на Диске</a>
      — там же просят доступ, если файл не открывается.
    </p>
  </div>
</template>

<style scoped>
.drive {
  margin: 0.6rem 0 0;
}

/*
 * Высота от окна, а не от содержимого: чужую страницу внутри рамки не измерить,
 * а бумажный лист и таблица на весь экран читаются одинаково плохо.
 */
.drive__frame {
  display: block;
  width: 100%;
  height: min(70vh, 34rem);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface-sunken);
}

.drive__note {
  margin: 0.35rem 0 0;
  font-size: 0.82rem;
}
</style>
