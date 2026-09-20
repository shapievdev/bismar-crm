<script setup lang="ts">
import type { JSONContent } from '@tiptap/core'

/**
 * Статья — и она же во весь экран.
 *
 * Читают её в четырёх местах: урок, документ, справочник, новость. Вокруг
 * текста везде своё — плеер, проверка, файлы, ответственные, соседние
 * материалы, — и длинной статье это соседство мешает: на широком мониторе
 * колонка занимает треть экрана, а на телефоне текст идёт вперемежку с
 * карточками разделов. Кнопка разворачивает ровно статью: остальное пропадает,
 * текст занимает экран целиком, выход — той же кнопкой, клавишей Esc или
 * системной «назад».
 *
 * Разворачивается сама обёртка, а не вторая копия текста рядом. Внутри статьи
 * живут кадры — HTML-блоки и видео, — и копия означала бы второй такой кадр:
 * блок замерял бы себя заново, а начатое видео продолжало бы играть за спиной у
 * развёрнутого. Переносить узлы по документу нельзя по той же причине: всякий
 * переезд перезагружает кадр. Поэтому узел остаётся на месте, а меняется только
 * то, как он показан.
 */
const props = defineProps<{
  /** Статья в том виде, в каком её показывает страница, — с адресами вложений. */
  content: JSONContent | null
  /** Уроки старше редактора хранят текст строкой; показывается он тем же порядком. */
  fallbackText?: string | null
  /** Чем подписан полный экран: название урока, документа, новости. */
  title: string
  /** Уточнение к названию — курс урока, имя версии документа. */
  note?: string | null
}>()

/**
 * Есть ли что разворачивать.
 *
 * У материала может не быть статьи вовсе — только файлы, — и тогда страница
 * ничего здесь не рисует. Кнопка, разворачивающая пустоту, хуже отсутствующей.
 */
const hasArticle = computed(() => Boolean(props.content) || Boolean(props.fallbackText?.trim()))

const isOpen = ref(false)

const screen = useTemplateRef<HTMLElement>('screen')

/**
 * Где читатель стоял на странице.
 *
 * Развёрнутая статья выпадает из потока, страница под ней становится короче, и
 * браузер подтягивает прокрутку вверх. Свернув, читатель оказывался бы не там,
 * где оторвался, — поэтому место запоминается на входе и возвращается на
 * выходе, когда раскладка уже пересчитана.
 */
let restoreScrollTo = 0

function open(): void {
  if (isOpen.value) {
    return
  }

  restoreScrollTo = window.scrollY
  isOpen.value = true

  /*
   * Страницу под накладкой приходится придерживать самим: накрыть её мало —
   * палец на телефоне прокручивает то, что под ней, и, свернув статью, читатель
   * оказывается не там, где был.
   */
  document.body.style.overflow = 'hidden'

  /*
   * Полный экран браузера — сверх нашего и только там, где он есть: Safari на
   * телефоне разворачивает одно лишь видео. Накладка закрывает экран и без
   * него, поэтому отказ ничего не ломает — остаётся адресная строка.
   *
   * Просьба уходит в том же нажатии, что и открытие: отложенную до перерисовки
   * браузер считает непрошеной и отклоняет.
   */
  if (document.fullscreenEnabled === true) {
    void screen.value?.requestFullscreen?.()?.catch(() => {})
  }
}

async function close(): Promise<void> {
  if (!isOpen.value) {
    return
  }

  isOpen.value = false
  document.body.style.overflow = ''

  if (document.fullscreenElement === screen.value) {
    void document.exitFullscreen?.()?.catch(() => {})
  }

  // Место на странице возвращается после перерисовки: до неё статья ещё вне
  // потока, страница коротка, и прокрутке некуда встать.
  await nextTick()
  window.scrollTo(0, restoreScrollTo)
}

function onKey(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    void close()
  }
}

/**
 * Из полного экрана выходят не только нашей кнопкой: Esc, системная «назад»,
 * жест. Выйдя, читатель ждёт, что вернулась страница, — иначе статья осталась
 * бы поверх неё, и выходить пришлось бы дважды.
 */
function onFullscreenChange(): void {
  if (isOpen.value && document.fullscreenElement === null) {
    void close()
  }
}

// Слушаем, только пока развёрнуто: на странице урока таких статей может быть
// несколько — сам урок и приложенные к нему документы.
watch(isOpen, (open) => {
  if (open) {
    document.addEventListener('keydown', onKey)
    document.addEventListener('fullscreenchange', onFullscreenChange)
  }
  else {
    document.removeEventListener('keydown', onKey)
    document.removeEventListener('fullscreenchange', onFullscreenChange)
  }
})

onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKey)
  document.removeEventListener('fullscreenchange', onFullscreenChange)

  // Ушли со страницы прямо из развёрнутой статьи — прокрутку документу надо
  // вернуть, иначе следующий экран не листается вовсе.
  document.body.style.overflow = ''
})
</script>

<template>
  <div
    v-if="hasArticle"
    ref="screen"
    class="article"
    :class="{ 'article--screen': isOpen }"
    :role="isOpen ? 'dialog' : undefined"
    :aria-modal="isOpen ? 'true' : undefined"
    :aria-label="isOpen ? title : undefined"
  >
    <!-- Одна кнопка на оба положения, а не две: нажавший её палец остаётся на
         месте, и вместе с ним — фокус клавиатуры. В странице она стоит над
         текстом справа, на полном экране — в полосе рядом с названием. -->
    <div class="article__bar">
      <p v-if="isOpen" class="article__heading">
        <span class="article__title">{{ title }}</span>
        <span v-if="note" class="faint article__note">{{ note }}</span>
      </p>

      <button
        type="button"
        class="button-sm article__action"
        :class="isOpen ? 'button-secondary' : 'button-ghost'"
        :aria-expanded="isOpen"
        @click="isOpen ? close() : open()"
      >
        <svg
          viewBox="0 0 24 24"
          width="15"
          height="15"
          fill="none"
          stroke="currentColor"
          stroke-width="1.8"
          stroke-linecap="round"
          stroke-linejoin="round"
          aria-hidden="true"
        >
          <path v-if="isOpen" d="M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5" />
          <path v-else d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5" />
        </svg>

        {{ isOpen ? 'Свернуть' : 'Во весь экран' }}

        <span v-if="isOpen" class="article__hint">Esc</span>
      </button>
    </div>

    <div class="article__body">
      <div class="article__measure">
        <EditorRichTextRenderer :content="content" :fallback-text="fallbackText" />
      </div>
    </div>
  </div>
</template>

<style scoped>
.article {
  display: flex;
  flex-direction: column;
}

.article__bar {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 0.75rem;
  min-width: 0;
}

.article__action {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  flex-shrink: 0;
}

/* Подсказка про Esc — тому, у кого эта клавиша есть. Пальцем выходят кнопкой. */
.article__hint {
  padding: 0.05rem 0.35rem;
  border-radius: var(--radius-sm);
  background: var(--color-surface-sunken);
  color: var(--color-text-muted);
  font-size: 0.72rem;
}

@media (pointer: coarse) {
  .article__hint {
    display: none;
  }
}

/*
 * Развёрнутая статья.
 *
 * `fixed`, а не полный экран браузера: его умеют не все, а текст должен
 * разворачиваться везде. Где умеют — просьба уходит сверх этого, и тогда
 * пропадает ещё и адресная строка.
 *
 * Прокручивается тело, а не вся накладка: полоса с названием и выходом обязана
 * остаться на верхней кромке — длинную статью иначе пришлось бы отлистывать
 * обратно вверх, чтобы из неё выйти.
 */
.article--screen {
  position: fixed;
  inset: 0;
  z-index: 95;
  overflow: hidden;
  background: var(--color-bg);
  animation: reader 0.16s ease;
}

@keyframes reader {
  from { opacity: 0; }
  to { opacity: 1; }
}

.article--screen .article__bar {
  justify-content: space-between;
  padding: 0.7rem 1.25rem;
  /* Вырезы сверху — свои у каждого телефона: без этого название уходит под
     чёлку, а кнопка выхода оказывается наполовину за краем. */
  padding-top: max(0.7rem, env(safe-area-inset-top));
  border-bottom: 1px solid var(--color-border);
}

.article__heading {
  display: flex;
  align-items: baseline;
  gap: 0.6rem;
  min-width: 0;
  margin: 0;
  overflow: hidden;
}

.article__title {
  overflow: hidden;
  font-weight: 600;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.article__note {
  flex-shrink: 0;
  font-size: 0.85rem;
}

/* На телефоне в полосе помещается либо название, либо уточнение к нему —
   название нужнее. */
@media (max-width: 48rem) {
  .article__note {
    display: none;
  }
}

.article--screen .article__body {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  /* Докрутив статью до конца, дальше листают её же, а не страницу под ней. */
  overscroll-behavior: contain;
  padding: 1.5rem 1.25rem calc(3rem + env(safe-area-inset-bottom, 0px));
}

/*
 * Ширина строки и размер текста — как у книги, а не как у колонки в странице.
 *
 * Шире колонки урока (44rem): в статьях живут таблицы и HTML-блоки, и им место
 * нужнее, чем абзацу. Но не во весь монитор: строка на двадцать сантиметров
 * читается глазами по диагонали, и к её концу теряется начало.
 */
.article--screen .article__measure {
  max-width: 60rem;
  margin: 0 auto;
  font-size: 1.05rem;
  line-height: 1.7;
}

@media (prefers-reduced-motion: reduce) {
  .article--screen {
    animation: none;
  }
}
</style>
