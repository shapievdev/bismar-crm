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
 * Окно, в котором рамка стоит, пока её не измерили.
 *
 * Auto-sizing is circular whenever the embedded document uses viewport units:
 * a rule like `min-height: 100vh` grows with the frame, which grows the
 * measurement, which grows the frame again. So the frame is held at a reference
 * height while it measures, the result is applied once, and later reports are
 * ignored.
 *
 * Само окно задаёт CSS — `--viewport-free`, видимая часть страницы между нашими
 * плашками (см. `layouts/default.vue`). Прежде здесь стояло число: 800 точек
 * всем и всегда. Разметка, которую автор строит «ровно на экран», получала эти
 * 800 на любом устройстве — на высоком телефоне блок не доходил до низа, на
 * коротком уезжал под полосу разделов, и автору оставалось подгонять размеры
 * под свой аппарат руками. Окно из CSS верно на каждом экране, пересчитывается
 * само — при повороте, при другой шапке — и не стоит ни одного замера.
 */
const MAX_HEIGHT = 20000
const SETTLE_MS = 700

/**
 * Насколько замер может разойтись с окном и всё ещё считаться замером «по окну».
 *
 * Разметка, уложенная по выданной рамке, возвращает ровно её высоту — с точностью
 * до округлений и отступов тела. Всё, что дальше этого запаса в любую сторону, —
 * уже содержимое, и его высоту закрепляют пикселями.
 */
const WINDOW_SLACK = 24

/** Измеренная высота содержимого; null — пока не измерено или мерить нечего. */
const measuredHeight = ref<number | null>(null)

/** Разметка внутри меряет себя по рамке, а не по содержимому. */
const followsWindow = ref(false)

const isSettled = ref(false)
let tallestReport = 0
let reportedWindow = 0
let settleTimer: ReturnType<typeof setTimeout> | undefined

const frame = useTemplateRef<HTMLIFrameElement>('frame')

/**
 * Высота, которую рамке задаём мы, — или `null`, когда её задаёт CSS.
 *
 * Закреплённая автором высота сильнее всего; дальше — измеренное содержимое; а
 * разметке, которая меряет себя по окну, пикселей не задают вовсе: окно и так
 * верное, а пиксели запомнили бы чужой экран.
 */
const frameHeight = computed<number | null>(() => pinnedHeight.value
  ?? (followsWindow.value ? null : measuredHeight.value))

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
    overflow?: number
    viewport?: number
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

  const viewport = Number(data.viewport) || 0
  const overflow = Number(data.overflow) || 0

  // Замер с запасом берётся в расчёт, только когда он больше окна: ниже окна
  // он всегда равен окну и ничего о содержимом не говорит.
  const needed = Math.ceil(overflow > viewport + 8 ? Math.max(height, overflow) : height)

  // Reports keep arriving as fonts and images land. The tallest wins, and the
  // frame is resized only once they stop — measuring against a frame we have
  // already resized is what causes the runaway.
  if (needed > tallestReport) {
    tallestReport = needed

    // Окно запоминается от того же замера: судить о замере по чужому окну
    // нельзя, а между сообщениями оно могло измениться.
    reportedWindow = viewport
  }

  clearTimeout(settleTimer)
  settleTimer = setTimeout(() => {
    /*
     * Замер, равный окну, ничего нового не сообщает.
     *
     * Так отвечает разметка, уложенная по выданной рамке: страница на `100%`,
     * сетка «ровно в экран», карусель во весь кадр. Закрепить это число
     * пикселями значило бы запомнить то устройство, на котором блок открыли
     * первым, — и перестать считаться с остальными. Окно остаётся за CSS: на
     * другом экране и после поворота блок снова займёт видимую часть, и нам не
     * придётся ни мерить, ни перекладывать его заново.
     */
    followsWindow.value = reportedWindow > 0
      && Math.abs(tallestReport - reportedWindow) <= WINDOW_SLACK

    if (!followsWindow.value) {
      measuredHeight.value = Math.min(MAX_HEIGHT, Math.max(120, tallestReport))
    }

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
watch(srcdoc, () => forgetMeasurement())

/** Возвращает блок к началу замера: в окно и без принятого числа. */
function forgetMeasurement() {
  clearTimeout(settleTimer)
  tallestReport = 0
  reportedWindow = 0
  isSettled.value = false
  followsWindow.value = false
  measuredHeight.value = null
}

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

/** Высота рамки в разметке — или ничего, когда её задаёт CSS. */
const frameStyle = computed(() => (isScreen.value || frameHeight.value === null
  ? undefined
  : { height: `${frameHeight.value}px` }))

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
  forgetMeasurement()

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

/* ---------- Когда рамка заводится ---------- */

/**
 * Завелась ли рамка.
 *
 * Внутри рамки чужая страница: свои шрифты, свои скрипты, свой растр — и всё
 * это браузер держит с первой секунды, даже если блок лежит в конце статьи и до
 * него ещё не дошли. Телефону это стоит и памяти, и кадров: тяжёлая разметка
 * внутри продолжает занимать видеопамять, пока читают текст вокруг.
 *
 * Поэтому рамка появляется, когда блок подходит к экрану, а до того на её месте
 * стоит пустая коробка той же высоты — ни ссылки, ни прокрутка от этого не
 * съезжают.
 *
 * Обратно рамку не убираем никогда: перезагруженный блок потерял бы всё, что в
 * нём успели натыкать, — открытую вкладку, введённые числа, выбранную карточку.
 * Дальше за него отвечает `content-visibility` в стилях: ушедший за край блок
 * браузер не рисует, но и не выгружает.
 */
const isLive = ref(false)

let approach: IntersectionObserver | undefined

function watchApproach() {
  if (isLive.value || approach) {
    return
  }

  if (typeof IntersectionObserver !== 'function' || !pane.value) {
    isLive.value = true

    return
  }

  approach = new IntersectionObserver((entries) => {
    if (!entries.some(entry => entry.isIntersecting)) {
      return
    }

    isLive.value = true
    approach?.disconnect()
    approach = undefined
  }, { rootMargin: '600px 0px' })

  approach.observe(pane.value)
}

// Автор, вышедший из правки кода, смотрит на блок прямо сейчас — ждать от него
// приближения незачем.
watch(isEditing, (editing) => {
  if (!editing) {
    isLive.value = true
  }
})

onMounted(() => {
  window.addEventListener('message', onMessage)
  watchApproach()
})

onBeforeUnmount(() => {
  window.removeEventListener('message', onMessage)
  approach?.disconnect()
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
  // Отсчёт — от высоты, в которой рамка стоит сейчас: закреплённой, а если её
  // нет — той, что ей дало содержимое или окно.
  const current = pinnedHeight.value ?? frame.value?.clientHeight ?? 0
  const next = Math.min(MAX_HEIGHT, Math.max(120, current + delta))

  props.updateAttributes({ height: next })
}

function unpin() {
  props.updateAttributes({ height: null })
}

/** Чем высота блока задана — автором, содержимым или экраном. */
const heightNote = computed(() => {
  if (pinnedHeight.value) {
    return `${pinnedHeight.value} px`
  }

  return followsWindow.value ? 'высота по видимому экрану' : 'высота по содержимому'
})
</script>

<template>
  <NodeViewWrapper class="html-block" :class="{ 'html-block--selected': selected }">
    <header v-if="editor.isEditable" class="html-block__bar" contenteditable="false">
      <span class="badge badge--accent">HTML</span>
      <span class="faint html-block__note">
        Выполняется изолированно, без доступа к странице ·
        {{ heightNote }}
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
      :class="{ 'html-block__pane--screen': isScreen, 'html-block__pane--idle': isSettled && !isScreen }"
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
        the deferred load, so Chrome leaves it blank indefinitely — ждать
        приближения приходится самим, и до него на месте рамки стоит коробка
        той же высоты.
      -->
      <div v-if="!isLive" class="html-block__frame" :style="frameStyle" />

      <!--
        Во весь экран высота не задаётся вовсе: её даёт раскладка накладки, а
        замеренное число вернётся к рамке, когда блок свернут обратно. Не задаём
        её и разметке, которая меряет себя по окну, — окно за CSS.
      -->
      <iframe
        v-else
        ref="frame"
        class="html-block__frame"
        :style="frameStyle"
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
 * Блок, уехавший за край экрана, браузер не рисует.
 *
 * Внутри рамки может лежать что угодно — постеры во весь кадр, тени с большим
 * размытием, карты, — и всё это телефон держит в видеопамяти и перерисовывает,
 * пока читают текст вокруг. `content-visibility` снимает с него и отрисовку, и
 * растр, пока блок не на виду, не выгружая самой рамки: вернувшись к блоку,
 * читатель находит его в том же состоянии.
 *
 * Включается это не раньше, чем блок измерен, и вот почему: пропущенная
 * отрисовка откладывает и саму страницу внутри рамки — она не разбирается и не
 * сообщает свою высоту, пока на неё не посмотрят. Блок в конце статьи так и
 * стоял бы высотой в экран, а потом схлопывался до своих двух строк прямо под
 * рукой читателя. Измеренному блоку скрываться уже не во вред: высота у него
 * есть, и браузер запоминает её сам.
 *
 * Запасная высота — то же окно, на случай если вспоминать ещё нечего.
 */
.html-block__pane--idle {
  content-visibility: auto;
  contain-intrinsic-size: auto var(--viewport-free, 80svh);
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

  /*
   * Окно, в котором рамка стоит, пока её высоту не задали в разметке: видимая
   * часть страницы между нашими плашками (`layouts/default.vue`). По нему
   * раскладывается разметка, считающая себя страницей, — и оно же остаётся
   * высотой такого блока навсегда, потому что верно на любом экране.
   *
   * Запасное значение — для разметки вне нашей оболочки (письмо, печать): чуть
   * меньше экрана, чтобы блок не упирался в его края.
   */
  height: var(--viewport-free, 80svh);
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