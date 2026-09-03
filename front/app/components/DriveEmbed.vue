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

/**
 * Разворачивается не сама рамка, а обёртка вокруг неё.
 *
 * Внутри рамки живёт Google, и кнопки «закрыть» у него нет. Развернув рамку, мы
 * отдали бы весь экран чужой странице, а выход остался бы только у системной
 * кнопки «назад» — на телефоне человек из такого просмотра просто не выбирается.
 * Обёртка же наша: в ней и живёт крестик.
 */
const pane = useTemplateRef<HTMLElement>('pane')

/** Развёрнута ли обёртка сейчас: по этому показывается крестик. */
const isFullscreen = ref(false)

/**
 * Развёрнута ли рамка.
 *
 * На узком экране — нет, и это не экономия, а проходимость: рамка высотой в
 * экран перегораживает урок, а палец, попавший в неё, листает документ вместо
 * страницы. Выйти из такой ловушки можно только промахнувшись мимо, и человек
 * скорее решит, что страница сломалась. Поэтому на телефоне сначала карточка с
 * именем файла, а документ — по нажатию.
 *
 * На большом экране рамка открыта сразу: там она стоит в колонке, страница
 * листается полями, и прятать содержимое незачем.
 */
const isOpen = ref(true)

/**
 * Умеет ли браузер разворачивать рамку во весь экран.
 *
 * Safari на телефоне не умеет: полный экран там есть только у видео. Кнопка,
 * которая молча ничего не делает, хуже отсутствующей.
 */
const canGoFullscreen = ref(false)

onMounted(() => {
  const narrow = window.matchMedia('(max-width: 48rem)')

  const apply = () => {
    isOpen.value = !narrow.matches
  }

  apply()
  narrow.addEventListener('change', apply)

  canGoFullscreen.value = document.fullscreenEnabled === true

  // Выйти можно не только крестиком — клавишей Esc, кнопкой «назад», жестом, —
  // поэтому состояние читается у браузера, а не запоминается при нажатии.
  const sync = () => {
    isFullscreen.value = document.fullscreenElement === pane.value
  }

  document.addEventListener('fullscreenchange', sync)

  onBeforeUnmount(() => {
    narrow.removeEventListener('change', apply)
    document.removeEventListener('fullscreenchange', sync)
  })
})

/**
 * Во весь экран.
 *
 * Лист A4 в колонке урока читается мелко, а увести человека на Диск — значит
 * увести его из урока. Полный экран решает и то и другое: страница остаётся
 * открытой, документ становится размером с монитор.
 */
function expand() {
  pane.value?.requestFullscreen?.()
}

function collapse() {
  void document.exitFullscreen?.()
}
</script>

<template>
  <div class="drive">
    <div v-if="isOpen" ref="pane" class="drive__pane">
      <!-- Крестик появляется только на полном экране: в странице у рамки и так
           есть «Свернуть», а поверх документа лишняя кнопка только мешала бы. -->
      <button
        v-if="isFullscreen"
        type="button"
        class="drive__close"
        aria-label="Выйти из полноэкранного режима"
        @click="collapse"
      >
        ✕
      </button>

      <iframe
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
      <button type="button" class="drive__action" @click="isOpen = !isOpen">
        {{ isOpen ? 'Свернуть' : 'Показать документ' }}
      </button>

      <button
        v-if="isOpen && canGoFullscreen"
        type="button"
        class="drive__action"
        @click="expand"
      >
        Во весь экран
      </button>

      <span>Файл на Google Диске.</span>
      <a :href="openUrl" target="_blank" rel="noopener noreferrer">Открыть на Диске</a>
      <span>— там же просят доступ, если файл не открывается.</span>
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
  position: relative;
  aspect-ratio: 1 / 1.3;
  min-height: 30rem;
  resize: vertical;
  overflow: hidden;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface-sunken);
}

/* На полном экране обёртка занимает его целиком: пропорции листа и рамка со
   скруглением здесь ни к чему. */
.drive__pane:fullscreen {
  aspect-ratio: auto;
  width: 100%;
  height: 100%;
  min-height: 0;
  border: 0;
  border-radius: 0;
  resize: none;
}

/*
 * Крестик поверх документа.
 *
 * Размер — под палец, а не под курсор: выходят из полного экрана чаще всего на
 * телефоне, где системной кнопки «назад» под рукой может и не быть. Тень вместо
 * подложки в цвет темы: под ним чужая страница, и какого она цвета, мы не знаем.
 */
.drive__close {
  position: absolute;
  top: 0.6rem;
  right: 0.6rem;
  z-index: 1;
  width: 2.75rem;
  height: 2.75rem;
  border: none;
  border-radius: 50%;
  background: rgb(0 0 0 / 62%);
  color: #fff;
  font-size: 1.1rem;
  line-height: 1;
  cursor: pointer;
  box-shadow: 0 2px 10px rgb(0 0 0 / 35%);
}

.drive__close:hover {
  background: rgb(0 0 0 / 80%);
}

/*
 * Пальцем ручку не потянешь — она рассчитана на курсор и на телефоне только
 * съедает угол документа. Спрашиваем не про ширину экрана, а про способ ввода:
 * планшет с мышью вправе её иметь.
 */
@media (pointer: coarse) {
  .drive__pane {
    resize: none;
  }
}

/*
 * На узком экране лист целиком не нужен: рамка в полтора экрана высотой
 * превращает урок в бесконечную прокрутку. Высота — по экрану, и внутри
 * документа своя прокрутка.
 */
@media (max-width: 48rem) {
  .drive__pane {
    aspect-ratio: auto;
    height: 70vh;
    min-height: 20rem;
  }
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

/* Кнопки выглядят ссылками: они стоят в строке подписи, и рамка вокруг них
   спорила бы с самой рамкой просмотра. */
.drive__action {
  padding: 0;
  border: none;
  background: none;
  color: inherit;
  font: inherit;
  text-decoration: underline;
  text-underline-offset: 0.2em;
  cursor: pointer;
}

.drive__action:hover {
  color: var(--color-accent);
}

/* На телефоне «Показать документ» — главное действие в строке, и промахнуться
   по нему пальцем не должно быть легко. */
@media (max-width: 48rem) {
  .drive__action {
    padding: 0.35rem 0;
  }
}
</style>
