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
    <div class="drive__pane">
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
    </div>

    <p class="faint drive__note">
      <button type="button" class="drive__expand" @click="expand">
        Во весь экран
      </button>
      · Рамку можно растянуть за нижний край. Файл на Google Диске.
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
 * Высота — по бумаге, а не по окну браузера.
 *
 * HTML-блок тянется по своему содержимому, но он наш: документ внутри сам
 * сообщает высоту. Диск чужой, померить его нечем, и всякое число здесь —
 * догадка. Лучшая догадка — пропорции листа: страница помещается целиком и
 * читается в том же размере, в каком её напечатали бы. Ограничения высотой
 * экрана нет намеренно — от него рамка и выходила приземистой.
 *
 * Ручка внизу справа остаётся человеку: таблицу тянут вширь, договор — вниз, и
 * спорить с этим бессмысленно.
 */
.drive__pane {
  aspect-ratio: 1 / 1.3;
  min-height: 30rem;
  resize: vertical;
  overflow: hidden;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface-sunken);
}

.drive__frame {
  display: block;
  width: 100%;
  height: 100%;
  border: 0;
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
