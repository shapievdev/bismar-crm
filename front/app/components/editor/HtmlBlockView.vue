<script setup lang="ts">
import { NodeViewWrapper, nodeViewProps } from '@tiptap/vue-3'
import type { PalettePreference, ThemePreference } from '~/composables/useTheme'
import { htmlBlockDocument } from '~/utils/editor/htmlBlockDocument'
import { HTML_BLOCK_HEIGHT_MESSAGE, HTML_BLOCK_SCROLL_MESSAGE } from '~/utils/editor/htmlBlockRuntime'

const props = defineProps(nodeViewProps)

/**
 * Which theme the frame is dressed in. It cannot see the page's stylesheet, so
 * the choice has to travel into the document it is handed.
 *
 * Read from the root element, where app.vue stamps an explicit choice and
 * writes nothing at all for "system" — exactly the three cases the embedded
 * stylesheet knows. Nothing here has to react: the theme is changed on the
 * profile page and nowhere else, so coming back from it mounts the block anew,
 * and "system" is left to a media query inside the frame, which the browser
 * keeps up to date by itself.
 */
const chosenTheme = import.meta.client ? document.documentElement.dataset.theme : undefined
const theme: ThemePreference = chosenTheme === 'light' || chosenTheme === 'dark' ? chosenTheme : 'system'

/**
 * Палитра едет туда же и тем же путём — с корневого элемента. Она, в отличие
 * от схемы, проставлена всегда: системной палитры не бывает.
 */
const chosenPalette = import.meta.client ? document.documentElement.dataset.palette : undefined
const palette: PalettePreference = chosenPalette === 'crimson' ? chosenPalette : 'graphite'

const html = computed<string>(() => props.node.attrs.html ?? '')

// Только что заведённый блок пуст, и показывать в нём пустую рамку незачем:
// его завели ради разметки, значит открываем сразу поле для неё.
const isEditing = ref(props.editor.isEditable && html.value === '')
const draft = ref<string>(html.value)

// Разметку меняет не только это поле: отмена правки (Ctrl+Z) и перечитанная
// запись возвращают блоку прежний атрибут. Черновик обязан идти следом, иначе
// «Код» открывал бы отменённое, а «Применить» возвращало бы его в статью.
watch(html, (value) => {
  if (!isEditing.value) {
    draft.value = value
  }
})

/** A stored height pins the frame; null lets it follow its content. */
const pinnedHeight = computed<number | null>(() => {
  const value = props.node.attrs.height

  return typeof value === 'number' && value > 0 ? value : null
})

/**
 * The block renders in a sandboxed iframe with no `allow-same-origin`, so the
 * markup gets a unique opaque origin: its scripts cannot read this page, our
 * cookies, or anything in the storage bucket. That isolation is what makes it
 * safe to render author-supplied HTML at all. `allow-scripts` is granted so the
 * block can actually do something.
 */
const SANDBOX = 'allow-scripts allow-popups allow-popups-to-escape-sandbox allow-forms allow-modals'

/** Identifies this frame among the height messages arriving on the window. */
const token = useId()

/**
 * Height the frame is measured at.
 *
 * Auto-sizing is circular whenever the embedded document uses viewport units:
 * a rule like `min-height: 100vh` grows with the frame, which grows the
 * measurement, which grows the frame again. So the frame is held at this
 * reference height while it measures, the result is applied once, and later
 * reports are ignored. Viewport units inside a block therefore resolve against
 * this height rather than chasing the frame.
 */
const REFERENCE_HEIGHT = 800
const MAX_HEIGHT = 20000
const SETTLE_MS = 700

const measuredHeight = ref(REFERENCE_HEIGHT)
const isSettled = ref(false)
let tallestReport = 0
let settleTimer: ReturnType<typeof setTimeout> | undefined

const frame = useTemplateRef<HTMLIFrameElement>('frame')

const effectiveHeight = computed(() => pinnedHeight.value ?? measuredHeight.value)

/**
 * The document handed to the frame: the author's markup, the site's typography
 * and our runtime.
 */
const srcdoc = computed(() => (html.value
  ? htmlBlockDocument(html.value, { theme, palette, token })
  : ''))

function onMessage(event: MessageEvent) {
  // Origin is "null" for a sandboxed frame, so identity is established by the
  // window that sent the message rather than by where it claims to come from.
  if (!frame.value || event.source !== frame.value.contentWindow) {
    return
  }

  const data = event.data as {
    type?: string
    token?: string
    height?: number
    offset?: number
  } | null

  if (data?.token !== token) {
    return
  }

  if (data.type === HTML_BLOCK_SCROLL_MESSAGE) {
    // Развёрнутая рамка прокручивается сама — ссылку внутри неё отрабатывает
    // `scrollIntoView` в самом документе, а страницу под накладкой двигать
    // некуда и незачем.
    if (!isScreen.value) {
      scrollOuterPageTo(Number(data.offset))
    }

    return
  }

  if (data.type !== HTML_BLOCK_HEIGHT_MESSAGE) {
    return
  }

  const height = Number(data.height)

  // Во весь экран рамка меряется по экрану, а не по разметке: принятое оттуда
  // число осталось бы с блоком и после сворачивания — свёрнутый блок стал бы
  // высотой с монитор.
  if (!Number.isFinite(height) || height <= 0 || isSettled.value || isScreen.value) {
    return
  }

  // Reports keep arriving as fonts and images land. The tallest wins, and the
  // frame is resized only once they stop — measuring against a frame we have
  // already resized is what causes the runaway.
  tallestReport = Math.max(tallestReport, Math.ceil(height))

  clearTimeout(settleTimer)
  settleTimer = setTimeout(() => {
    measuredHeight.value = Math.min(MAX_HEIGHT, Math.max(120, tallestReport))
    isSettled.value = true
  }, SETTLE_MS)
}

/** Brings a section inside the block into view by moving the outer page. */
function scrollOuterPageTo(offset: number) {
  if (!frame.value || !Number.isFinite(offset)) {
    return
  }

  const frameTop = frame.value.getBoundingClientRect().top + window.scrollY
  const headerAllowance = 80

  window.scrollTo({ top: Math.max(0, frameTop + offset - headerAllowance), behavior: 'smooth' })
}

// A new document has to be measured from scratch.
watch(srcdoc, () => {
  clearTimeout(settleTimer)
  tallestReport = 0
  isSettled.value = false
  measuredHeight.value = REFERENCE_HEIGHT
})

/* ---------- Блок во весь экран ---------- */

/**
 * Развёрнут ли блок.
 *
 * Блоком в статью кладут не абзац, а разметку: таблицу на двадцать колонок,
 * схему, расчёт. В колонке статьи такому тесно, а раздвинуть колонку нельзя —
 * рядом с ней живёт сама статья. Поэтому блок разворачивается на экран целиком
 * и сворачивается обратно.
 *
 * Разворачивается обёртка вокруг рамки, а не сама рамка: внутри рамки чужая
 * разметка, кнопки «свернуть» у неё нет, и, отдав ей весь экран, мы оставили бы
 * читателя без выхода. Обёртка же наша — в ней и живёт кнопка.
 *
 * Рамка при этом остаётся тем же узлом документа: её не переносят и не рисуют
 * заново, поэтому всё, что в блоке успели натыкать — открытая вкладка, введённые
 * числа, проигранная анимация, — переживает и разворот, и сворачивание. Любой
 * переезд по документу перезагрузил бы её с нуля.
 */
const isScreen = ref(false)

const pane = useTemplateRef<HTMLElement>('pane')

/**
 * Где читатель стоял на странице.
 *
 * Развёрнутый блок выпадает из потока, страница под ним становится короче, и
 * браузер подтягивает прокрутку вверх. Свернув, читатель оказывался бы не там,
 * где оторвался, — место запоминается на входе и возвращается на выходе, когда
 * раскладка уже пересчитана.
 */
let restoreScrollTo = 0

function expand() {
  if (isScreen.value) {
    return
  }

  restoreScrollTo = window.scrollY
  isScreen.value = true

  // Страницу под накладкой приходится придерживать самим: накрыть её мало —
  // палец на телефоне прокручивает то, что под ней.
  document.body.style.overflow = 'hidden'

  /*
   * Полный экран браузера — сверх нашего и только там, где он есть: Safari на
   * телефоне разворачивает одно лишь видео. Накладка закрывает экран и без
   * него, поэтому отказ ничего не ломает — остаётся адресная строка.
   *
   * Просьба уходит в том же нажатии, что и разворот: отложенную до перерисовки
   * браузер считает непрошеной и отклоняет.
   */
  if (document.fullscreenEnabled === true) {
    void pane.value?.requestFullscreen?.()?.catch(() => {})
  }
}

async function collapse() {
  if (!isScreen.value) {
    return
  }

  isScreen.value = false
  document.body.style.overflow = ''

  if (document.fullscreenElement === pane.value) {
    void document.exitFullscreen?.()?.catch(() => {})
  }

  /*
   * Свернувшись, блок меряется заново.
   *
   * Во весь экран разметка внутри раскладывается иначе — сетка в три колонки
   * вместо одной, таблица без переносов, — и, главное, замер в это время не
   * принимается вовсе. Оставить прежнее число значило бы полагаться на то, что
   * оно успело сняться до разворота: блок, развёрнутый в первые же полсекунды
   * после загрузки, вернулся бы в колонку высотой с заглушку.
   */
  clearTimeout(settleTimer)
  tallestReport = 0
  isSettled.value = false

  // Место на странице возвращается после перерисовки: до неё блок ещё вне
  // потока, страница коротка, и прокрутке некуда встать.
  await nextTick()
  window.scrollTo(0, restoreScrollTo)
}

function onKey(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    void collapse()
  }
}

/**
 * Из полного экрана выходят не только нашей кнопкой: Esc, системная «назад»,
 * жест. Выйдя, читатель ждёт, что вернулась страница, — иначе блок остался бы
 * поверх неё, и выходить пришлось бы дважды.
 */
function onFullscreenChange() {
  if (isScreen.value && document.fullscreenElement === null) {
    void collapse()
  }
}

// Слушаем, только пока развёрнуто: блоков в статье бывает с десяток, и каждый
// держал бы свою пару обработчиков на документе просто так.
watch(isScreen, (screen) => {
  if (screen) {
    document.addEventListener('keydown', onKey)
    document.addEventListener('fullscreenchange', onFullscreenChange)
  }
  else {
    document.removeEventListener('keydown', onKey)
    document.removeEventListener('fullscreenchange', onFullscreenChange)
  }
})

onMounted(() => window.addEventListener('message', onMessage))
onBeforeUnmount(() => {
  window.removeEventListener('message', onMessage)
  document.removeEventListener('keydown', onKey)
  document.removeEventListener('fullscreenchange', onFullscreenChange)
  clearTimeout(settleTimer)

  // Ушли со страницы прямо из развёрнутого блока — прокрутку документу надо
  // вернуть, иначе следующий экран не листается вовсе.
  document.body.style.overflow = ''
})

function save() {
  props.updateAttributes({ html: draft.value })
  isEditing.value = false
}

function cancel() {
  draft.value = html.value
  isEditing.value = false
}

function pin(delta: number) {
  const next = Math.min(MAX_HEIGHT, Math.max(120, effectiveHeight.value + delta))

  props.updateAttributes({ height: next })
}

function unpin() {
  props.updateAttributes({ height: null })
}
</script>

<template>
  <NodeViewWrapper class="html-block" :class="{ 'html-block--selected': selected }">
    <header v-if="editor.isEditable" class="html-block__bar" contenteditable="false">
      <span class="badge badge--accent">HTML</span>
      <span class="faint html-block__note">
        Выполняется изолированно, без доступа к странице ·
        {{ pinnedHeight ? `${pinnedHeight} px` : 'высота по содержимому' }}
      </span>

      <div class="html-block__actions">
        <button type="button" class="button-ghost button-sm" title="Уменьшить" @click="pin(-120)">
          −
        </button>
        <button type="button" class="button-ghost button-sm" title="Увеличить" @click="pin(120)">
          +
        </button>
        <button
          v-if="pinnedHeight"
          type="button"
          class="button-ghost button-sm"
          title="Подстраивать под содержимое"
          @click="unpin"
        >
          Авто
        </button>
        <button type="button" class="button-secondary button-sm" @click="isEditing = !isEditing">
          {{ isEditing ? 'Просмотр' : 'Код' }}
        </button>
        <button type="button" class="button-danger button-sm" @click="deleteNode()">
          Удалить
        </button>
      </div>
    </header>

    <div v-if="isEditing" class="html-block__editor" contenteditable="false">
      <textarea
        v-model="draft"
        class="textarea html-block__code"
        spellcheck="false"
        placeholder="<div>Ваша разметка…</div>"
      />
      <div class="html-block__editor-actions">
        <button type="button" class="button-primary button-sm" @click="save">
          Применить
        </button>
        <button type="button" class="button-ghost button-sm" @click="cancel">
          Отмена
        </button>
      </div>
    </div>

    <div
      v-else-if="html"
      ref="pane"
      class="html-block__pane"
      :class="{ 'html-block__pane--screen': isScreen }"
      contenteditable="false"
    >
      <!-- Кнопка стоит поверх рамки, а не рядом: полоса над блоком есть только
           у автора, а разворачивает блок читатель, и другого места для неё в
           статье нет. -->
      <button
        type="button"
        class="html-block__screen"
        :title="isScreen ? 'Свернуть (Esc)' : 'Во весь экран'"
        :aria-label="isScreen ? 'Свернуть блок' : 'Развернуть блок во весь экран'"
        :aria-expanded="isScreen"
        @click="isScreen ? collapse() : expand()"
      >
        <svg
          viewBox="0 0 24 24"
          width="17"
          height="17"
          fill="none"
          stroke="currentColor"
          stroke-width="1.8"
          stroke-linecap="round"
          stroke-linejoin="round"
          aria-hidden="true"
        >
          <path v-if="isScreen" d="M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5" />
          <path v-else d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5" />
        </svg>
      </button>

      <!--
        No loading="lazy" here. A srcdoc frame has no network request to trigger
        the deferred load, so Chrome leaves it blank indefinitely.

        Во весь экран высота не задаётся вовсе: её даёт раскладка накладки, а
        замеренное число вернётся к рамке, когда блок свернут обратно.
      -->
      <iframe
        ref="frame"
        class="html-block__frame"
        :style="isScreen ? undefined : { height: `${effectiveHeight}px` }"
        :sandbox="SANDBOX"
        :srcdoc="srcdoc"
        title="Встроенный HTML"
      />
    </div>

    <p v-else class="html-block__empty faint">
      Пустой HTML-блок — нажмите «Код», чтобы вставить разметку.
    </p>
  </NodeViewWrapper>
</template>

<style scoped>
.html-block {
  margin: 1.25rem 0;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  overflow: hidden;
}

.html-block--selected {
  border-color: var(--color-accent);
}

.html-block__bar {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.45rem 0.7rem;
  background: var(--color-surface-sunken);
  border-bottom: 1px solid var(--color-border);
}

.html-block__note {
  flex: 1;
  font-size: 0.78rem;
}

.html-block__actions {
  display: flex;
  gap: 0.3rem;
}

.html-block__editor {
  padding: 0.7rem;
}

.html-block__code {
  width: 100%;
  min-height: 18rem;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.82rem;
  line-height: 1.5;
}

.html-block__editor-actions {
  display: flex;
  gap: 0.4rem;
  margin-top: 0.5rem;
}

.html-block__pane {
  position: relative;
}

/*
 * Развёрнутый блок.
 *
 * `fixed`, а не полный экран браузера: его умеют не все, а блок должен
 * разворачиваться везде. Где умеют — просьба уходит сверх этого, и тогда
 * пропадает ещё и адресная строка.
 */
.html-block__pane--screen {
  position: fixed;
  inset: 0;
  z-index: 95;
  background: var(--color-bg);
  animation: html-block-screen 0.16s ease;
}

@keyframes html-block-screen {
  from { opacity: 0; }
  to { opacity: 1; }
}

/*
 * The frame carries the surface the block sits on, and the document inside it
 * is transparent — so author markup that paints nothing still lands on the
 * card's tone in both themes instead of on a white slab.
 */
.html-block__frame {
  display: block;
  width: 100%;
  border: 0;
  background: var(--color-surface-raised);
}

/* Во весь экран рамка занимает его целиком, и разметка внутри прокручивается
   сама — высоту ей больше не задают. */
.html-block__pane--screen .html-block__frame {
  height: 100%;
}

/*
 * Кнопка разворота — поверх чужой разметки.
 *
 * Подложка тёмная и непрозрачная наполовину, а не в цвет темы: под кнопкой
 * авторский HTML, и какого он цвета, мы не знаем. Приглушена, пока на блок не
 * навели: она нужна раз за чтение, а стоит поверх содержимого.
 */
.html-block__screen {
  position: absolute;
  top: 0.5rem;
  right: 0.5rem;
  z-index: 1;
  display: grid;
  place-items: center;
  width: 2.25rem;
  height: 2.25rem;
  padding: 0;
  border: none;
  border-radius: 50%;
  background: rgb(0 0 0 / 55%);
  color: #fff;
  cursor: pointer;
  opacity: 0.45;
  box-shadow: 0 2px 8px rgb(0 0 0 / 25%);
  transition: opacity 0.15s ease, background-color 0.15s ease;
}

.html-block:hover .html-block__screen,
.html-block__screen:hover,
.html-block__screen:focus-visible {
  opacity: 1;
}

.html-block__screen:hover {
  background: rgb(0 0 0 / 78%);
}

/*
 * Пальцем на блок не наводят — кнопка видна сразу. И размер у неё под палец:
 * разворачивают блок чаще всего как раз на телефоне, где колонка уже всего.
 */
@media (pointer: coarse) {
  .html-block__screen {
    width: 2.75rem;
    height: 2.75rem;
    opacity: 1;
  }
}

/* Развёрнутый блок выходят из, а не любуются им: кнопка перестаёт быть
   приглушённой и отступает от выреза экрана. */
.html-block__pane--screen .html-block__screen {
  top: max(0.6rem, env(safe-area-inset-top));
  right: 0.6rem;
  width: 2.75rem;
  height: 2.75rem;
  opacity: 1;
}

@media (prefers-reduced-motion: reduce) {
  .html-block__pane--screen {
    animation: none;
  }

  .html-block__screen {
    transition: none;
  }
}

.html-block__empty {
  margin: 0;
  padding: 1.5rem;
  text-align: center;
  font-size: 0.88rem;
}
</style>