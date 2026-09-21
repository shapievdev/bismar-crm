<script setup lang="ts">
import type { SearchHit, SearchSection } from '~/types/search'

/**
 * Поиск по платформе — поле в шапке справа, значок на телефоне.
 *
 * Состояние одно, рамок две: на широком экране поле стоит в шапке и роняет
 * подсказку под себя, на телефоне за значком разворачивается отдельный экран
 * поиска. Набранное, разбор клавиш и сама выдача при этом общие — расходиться им
 * незачем, и список находок один на обе рамки (AppSearchResults).
 *
 * Экран поиска выносится в конец страницы через `Teleport`, и это не украшение:
 * у шапки `backdrop-filter`, а он делает её блоком-контейнером для
 * `position: fixed`. Развёрнутый внутри шапки поиск занял бы не экран, а
 * сантиметр полосы под ним — см. layouts/default.vue.
 *
 * Выдача — подсказка, а не страница результатов: по пять находок на раздел, а
 * «показать все» ведёт в каталог раздела с тем же словом. Каталоги умеют и
 * листаться, и отбирать по категории; подсказка обязана успеть, пока человек не
 * убрал палец с клавиатуры.
 */
const api = useSearchApi()
const router = useRouter()
const route = useRoute()

/**
 * Короче двух букв не спрашиваем: сервер по одной букве всё равно молчит (см.
 * Everywhere::MIN_LENGTH), и запрос на каждое касание ему ни к чему.
 */
const MIN_CHARS = 2

const { query, results: sections, isSearching, clear } = useDebouncedSearch<SearchSection>(
  term => term.length < MIN_CHARS
    ? Promise.resolve([])
    : api.searchEverywhere(term).then(response => response.data),
)

const root = useTemplateRef<HTMLElement>('root')
const screen = useTemplateRef<HTMLElement>('screen')
const field = useTemplateRef<HTMLInputElement>('field')
const screenField = useTemplateRef<HTMLInputElement>('screenField')

/** Развёрнут ли поиск во весь экран. Только на телефоне — на столе поле и так на виду. */
const isExpanded = ref(false)

/** Открыта ли подсказка. Гаснет по Esc и по нажатию мимо, а не по потере фокуса. */
const isPanelOpen = ref(false)

const isAsking = computed(() => query.value.trim().length >= MIN_CHARS)

/** Подсказка под полем — только на столе: на телефоне выдача занимает весь экран. */
const hasPanel = computed(() => isPanelOpen.value && isAsking.value && !isExpanded.value)

const hits = computed<SearchHit[]>(() => sections.value.flatMap(section => section.items))

/**
 * Место в общем списке находок, на котором стоят стрелками.
 *
 * Разделы для глаза, а не для клавиатуры: человек жмёт «вниз» и ждёт следующую
 * строку, а не следующий заголовок.
 */
const activeIndex = ref(-1)

// Выдача сменилась — прежнее место подсветки в ней уже ничего не значит.
watch(sections, () => {
  activeIndex.value = -1
})

/**
 * Узкий экран — тот же, на котором шапка теряет логотип и аватар.
 *
 * Спрашивается в скрипте, потому что от него зависит поведение, а не только
 * вид: на телефоне поиск разворачивается в отдельный экран, на столе остаётся
 * подсказкой. Граница та же, что в стилях ниже и в layouts/default.vue, — 60rem;
 * меняя одну, поменяйте и остальные.
 */
const isNarrow = ref(false)

onMounted(() => {
  const narrow = window.matchMedia('(max-width: 60rem)')

  isNarrow.value = narrow.matches

  const onChange = (event: MediaQueryListEvent) => {
    isNarrow.value = event.matches
  }

  narrow.addEventListener('change', onChange)
  onBeforeUnmount(() => narrow.removeEventListener('change', onChange))
})

/*
 * Окно раздвинули, пока поиск был развёрнут, — экран поиска сворачивается: на
 * столе он превратился бы в подсказку без поля, из которой её уронили.
 */
watch(isNarrow, (narrow) => {
  if (!narrow) {
    isExpanded.value = false
  }
})

/*
 * Страницу под развёрнутым поиском приходится придерживать самим: экран поиска
 * лежит поверх неё, но палец на телефоне прокручивает то, что под ним. Так же
 * сделано и в окне раздела настроек (AppSheet).
 */
watch(isExpanded, (expanded) => {
  document.body.style.overflow = expanded ? 'hidden' : ''
})

onBeforeUnmount(() => {
  document.body.style.overflow = ''
})

/**
 * Открыть поиск: на телефоне — экраном, на столе — просто встать в поле.
 */
function open() {
  isPanelOpen.value = true

  if (isNarrow.value) {
    isExpanded.value = true
  }

  nextTick(() => (isExpanded.value ? screenField.value : field.value)?.focus())
}

function close() {
  isExpanded.value = false
  isPanelOpen.value = false
  activeIndex.value = -1
}

/** Закрыть и забыть набранное: прошлый вопрос поиск не хранит. */
function dismiss() {
  close()
  clear()
}

async function go(url: string) {
  dismiss()
  await router.push(url)
}

function hover(hit: SearchHit) {
  activeIndex.value = hits.value.indexOf(hit)
}

function move(step: number) {
  if (hits.value.length === 0) {
    return
  }

  isPanelOpen.value = true

  const next = activeIndex.value + step

  // Останавливается на краях, а не заворачивается по кругу: список, который с
  // последней строки прыгает на первую, скрывает, что он кончился.
  activeIndex.value = Math.min(Math.max(0, next), hits.value.length - 1)
}

function onKeydown(event: KeyboardEvent) {
  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault()
      move(1)
      break
    case 'ArrowUp':
      event.preventDefault()
      move(-1)
      break
    case 'Enter': {
      // Ничего не выбрано — Enter ведёт к первой находке: она и есть ответ на
      // набранное, и нажимать «вниз» ради неё незачем.
      const target = hits.value[activeIndex.value] ?? hits.value[0]

      if (target) {
        event.preventDefault()
        void go(target.url)
      }

      break
    }
    case 'Escape':
      event.preventDefault()
      dismiss()
      break
  }
}

/**
 * Cmd/Ctrl+K — открыть поиск с любого экрана.
 *
 * По `code`, а не по `key`: в русской раскладке та же клавиша даёт «л», и поиск,
 * открывающийся только при латинице, обходил бы стороной ровно тех, ради кого
 * заведена поблажка на забытую раскладку (см. utils/keyboardLayout).
 */
function onShortcut(event: KeyboardEvent) {
  if (event.code === 'KeyK' && (event.metaKey || event.ctrlKey)) {
    event.preventDefault()
    open()
  }
}

function onPointerDown(event: PointerEvent) {
  const target = event.target as Node

  if (root.value?.contains(target) || screen.value?.contains(target)) {
    return
  }

  // Набранное остаётся: человек мог отвлечься, а не передумать. Гаснет только
  // подсказка — она перекрывает страницу, и висеть над ней ей нечего.
  isPanelOpen.value = false
}

onMounted(() => {
  document.addEventListener('keydown', onShortcut)
  document.addEventListener('pointerdown', onPointerDown)
})

onBeforeUnmount(() => {
  document.removeEventListener('keydown', onShortcut)
  document.removeEventListener('pointerdown', onPointerDown)
})

// Ушли на другую страницу — поиск закрывается сам: он про переход, а переход
// сделан.
watch(() => route.fullPath, () => dismiss())
</script>

<template>
  <div ref="root" class="top-search">
    <!-- Значок на телефоне: места под поле в полосе сверху нет, а поиск нужен на
         любом экране. Нажатие разворачивает его во весь экран. -->
    <button
      type="button"
      class="top-search__trigger"
      aria-label="Поиск по платформе"
      @click="open"
    >
      <AppNavIcon name="search" :size="20" />
    </button>

    <div class="top-search__field">
      <span class="top-search__glyph" aria-hidden="true">
        <AppNavIcon name="search" :size="17" />
      </span>

      <input
        ref="field"
        v-model="query"
        type="search"
        class="top-search__input"
        placeholder="Поиск по платформе"
        aria-label="Поиск по платформе"
        role="combobox"
        aria-autocomplete="list"
        :aria-expanded="hasPanel"
        autocomplete="off"
        @focus="isPanelOpen = true"
        @keydown="onKeydown"
      >
    </div>

    <div v-if="hasPanel" class="top-search__panel">
      <AppSearchResults
        :sections="sections"
        :active-index="activeIndex"
        :is-searching="isSearching"
        :is-asking="isAsking"
        @pick="go"
        @hover="hover"
      />
    </div>
  </div>

  <!--
    Экран поиска на телефоне. В конце страницы, а не в шапке: у шапки
    `backdrop-filter`, и `position: fixed` внутри неё меряется по ней, а не по
    экрану.
  -->
  <Teleport to="body">
    <div v-if="isExpanded" ref="screen" class="search-screen">
      <div class="search-screen__bar">
        <span class="search-screen__glyph" aria-hidden="true">
          <AppNavIcon name="search" :size="18" />
        </span>

        <input
          ref="screenField"
          v-model="query"
          type="search"
          class="search-screen__input"
          placeholder="Поиск по платформе"
          aria-label="Поиск по платформе"
          autocomplete="off"
          @keydown="onKeydown"
        >

        <button
          type="button"
          class="search-screen__close"
          aria-label="Закрыть поиск"
          @click="dismiss"
        >
          <AppNavIcon name="close" :size="20" />
        </button>
      </div>

      <div class="search-screen__results">
        <AppSearchResults
          class="hits--screen"
          :sections="sections"
          :active-index="activeIndex"
          :is-searching="isSearching"
          :is-asking="isAsking"
          @pick="go"
          @hover="hover"
        />

        <!-- Пока не набрали: сказать, где будут искать, честнее пустоты. -->
        <p v-if="!isAsking" class="search-screen__hint faint">
          Курсы, уроки, документы, справочники, новости и сотрудники — всё сразу.
        </p>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.top-search {
  position: relative;
  flex-shrink: 0;
}

/* На широком экране поле и так на виду — значок здесь лишний. */
.top-search__trigger {
  display: none;
}

.top-search__field {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  width: 14rem;
  padding: 0 0.85rem;
  border: 1px solid transparent;
  border-radius: var(--radius-pill);
  background: var(--control-surface);
  transition: border-color 0.15s ease, width 0.18s ease;
}

.top-search__field:hover {
  border-color: var(--color-border-strong);
}

.top-search__field:focus-within {
  border-color: var(--color-border-strong);
  /* На время набора поле раздвигается: запрос длиннее четырнадцати знаков иначе
     уезжал бы из виду по букве за букву. */
  width: 18rem;
}

.top-search__glyph {
  display: flex;
  flex-shrink: 0;
  color: var(--color-text-faint);
}

.top-search__input {
  min-width: 0;
  flex: 1;
  padding: 0.5rem 0;
  border: 0;
  background: none;
  color: inherit;
  font: inherit;
  font-size: 0.9rem;
}

.top-search__input:focus {
  outline: none;
}

.top-search__input::placeholder {
  color: var(--color-text-faint);
}

/* Своего крестика у поля не нужно: подсказку гасит Esc, а набранное стирают как
   обычный текст. Браузерный при этом рисуется не в палитре приложения. */
.top-search__input::-webkit-search-cancel-button {
  display: none;
}

/*
 * Подсказка висит под полем и прижата к правому краю — как и само поле.
 *
 * Шире поля намеренно: в находке две строки, и название курса вместе с
 * категорией в четырнадцать знаков не читается.
 */
.top-search__panel {
  position: absolute;
  z-index: 20;
  top: calc(100% + 0.4rem);
  right: 0;
  width: 25rem;
  max-height: min(70vh, 32rem);
  overflow-y: auto;
  overscroll-behavior: contain;
  padding: 0.4rem;
  background: var(--color-surface-raised);
  border-radius: var(--radius);
  box-shadow: var(--shadow-lg);
}

/*
 * Телефон: вместо поля — значок.
 *
 * Граница та же, на которой шапка теряет логотип и аватар, и та же, что
 * спрашивается в скрипте.
 */
@media (max-width: 60rem) {
  .top-search__trigger {
    display: grid;
    place-items: center;
    width: 2.25rem;
    height: 2.25rem;
    padding: 0;
    border: 0;
    border-radius: 50%;
    background: var(--color-surface-raised);
    color: var(--color-text-muted);
    cursor: pointer;
  }

  /* Поля в полосе нет вовсе: оно живёт на своём экране. */
  .top-search__field,
  .top-search__panel {
    display: none;
  }
}

@media (prefers-reduced-motion: reduce) {
  .top-search__field {
    transition: none;
  }
}

/* ---------- Экран поиска на телефоне ---------- */

/*
 * Во весь экран, а не листом снизу: поиск — это ввод с клавиатурой, и лист,
 * поднятый клавиатурой, оставил бы от выдачи полоску в две строки.
 */
.search-screen {
  position: fixed;
  z-index: 50;
  inset: 0;
  display: flex;
  flex-direction: column;
  background: var(--color-bg);
}

.search-screen__bar {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-shrink: 0;
  /* Под вырез и строку состояния: поле стоит у самой верхней кромки. */
  padding: max(0.7rem, env(safe-area-inset-top)) 0.9rem 0.6rem;
}

.search-screen__glyph {
  display: flex;
  flex-shrink: 0;
  color: var(--color-text-faint);
}

.search-screen__input {
  min-width: 0;
  flex: 1;
  padding: 0.55rem 0.2rem;
  border: 0;
  background: none;
  color: inherit;
  font: inherit;
}

.search-screen__input:focus {
  outline: none;
}

.search-screen__input::placeholder {
  color: var(--color-text-faint);
}

.search-screen__input::-webkit-search-cancel-button {
  display: none;
}

.search-screen__close {
  display: grid;
  place-items: center;
  flex-shrink: 0;
  width: 2rem;
  height: 2rem;
  padding: 0;
  border: 0;
  border-radius: 50%;
  background: none;
  color: var(--color-text-muted);
  cursor: pointer;
}

.search-screen__results {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
  padding: 0 0.4rem env(safe-area-inset-bottom);
}

.search-screen__hint {
  margin: 0;
  padding: 0.6rem 0.7rem;
  font-size: 0.85rem;
  line-height: 1.5;
}

/*
 * Экран поиска живёт только на телефоне: на широком экране его закрывает
 * наблюдатель в скрипте, но пока окно раздвигают, кадр-другой он ещё здесь.
 */
@media (min-width: 60.01rem) {
  .search-screen {
    display: none;
  }
}
</style>
