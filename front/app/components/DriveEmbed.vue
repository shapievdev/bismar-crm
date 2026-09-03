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

const frame = useTemplateRef<HTMLIFrameElement>('frame')

/**
 * Во весь экран.
 *
 * Лист A4 в колонке урока читается мелко, а увести человека на Диск — значит
 * увести его из урока. Полный экран решает и то и другое: страница остаётся
 * открытой, документ становится размером с монитор.
 */
function expand() {
  frame.value?.requestFullscreen?.()
}
</script>

<template>
  <div class="drive">
    <iframe
      ref="frame"
      :src="src"
      :title="title"
      class="drive__frame"
      loading="lazy"
      allow="autoplay"
      allowfullscreen
      referrerpolicy="strict-origin-when-cross-origin"
    />

    <p class="faint drive__note">
      <button type="button" class="drive__expand" @click="expand">
        Во весь экран
      </button>
      · Файл на Google Диске.
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
 * Высота от окна, а не от содержимого: чужую страницу внутри рамки не измерить.
 * Считана под лист A4 — в такой рамке он читается целиком, не превращаясь в
 * почтовую марку; кому мало, тот разворачивает на весь экран.
 */
.drive__frame {
  display: block;
  width: 100%;
  height: min(85vh, 50rem);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface-sunken);
}

.drive__note {
  display: flex;
  flex-wrap: wrap;
  gap: 0.3rem;
  align-items: baseline;
  margin: 0.35rem 0 0;
  font-size: 0.82rem;
}

/* Кнопка выглядит ссылкой: она стоит в строке подписи, и рамка вокруг неё
   спорила бы с самой рамкой просмотра. */
.drive__expand {
  padding: 0;
  border: none;
  background: none;
  color: inherit;
  font: inherit;
  text-decoration: underline;
  text-underline-offset: 0.2em;
  cursor: pointer;
}

.drive__expand:hover {
  color: var(--color-accent);
}
</style>
