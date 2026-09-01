<script setup lang="ts" generic="T extends string | number | null">
export interface SelectOption<V> {
  value: V
  label: string
  /** A second line under the label, for anything the name alone leaves open. */
  hint?: string
}

const props = withDefaults(defineProps<{
  options: SelectOption<T>[]
  id?: string
  placeholder?: string
  disabled?: boolean
  /** Matches the width to the widest option instead of filling the row. */
  auto?: boolean
  /**
   * Поле поиска в списке. По умолчанию появляется само, когда вариантов больше
   * горсти: у списка из трёх оно только мешает, а в списке из тридцати
   * категорий нужное иначе приходится искать глазами.
   */
  searchable?: boolean
  searchPlaceholder?: string
}>(), {
  placeholder: 'Не выбрано',
  disabled: false,
  auto: false,
  searchPlaceholder: 'Найти…',
})

const model = defineModel<T>({ required: true })

/**
 * A select the application draws itself.
 *
 * A native <select> can be styled shut but not open: the list is drawn by the
 * operating system, so it arrives in the wrong palette, the wrong radius and
 * the wrong typeface however the page is themed. This one is ordinary markup
 * all the way down, which is the only way the open state can match.
 */
const isOpen = ref(false)
const root = useTemplateRef<HTMLElement>('root')
const list = useTemplateRef<HTMLElement>('list')
const search = useTemplateRef<HTMLInputElement>('search')

/** Where the keyboard is, which is not where the selection is until Enter. */
const activeIndex = ref(-1)

const query = ref('')

/** С этого числа вариантов список без поиска читают уже не глазами, а перебором. */
const SEARCH_FROM = 7

const isSearchShown = computed(() => props.searchable ?? props.options.length > SEARCH_FROM)

/**
 * Что видно в списке: всё или подходящее под набранное.
 *
 * Ищем и по подписи, и по пояснению: у отдела в пояснении стоит число людей, а
 * у материала — раздел, и набранное человек ждёт найденным в обоих.
 */
const visible = computed(() => {
  const term = query.value.trim().toLowerCase()

  if (term === '') {
    return props.options
  }

  return props.options.filter(option =>
    `${option.label} ${option.hint ?? ''}`.toLowerCase().includes(term))
})

const selected = computed(() => props.options.find(option => option.value === model.value) ?? null)
const label = computed(() => selected.value?.label ?? props.placeholder)

function open() {
  if (props.disabled) {
    return
  }

  isOpen.value = true
  query.value = ''
  activeIndex.value = Math.max(0, props.options.findIndex(option => option.value === model.value))

  nextTick(() => {
    // Курсор сразу в поиске: список открывают, чтобы найти, а не чтобы потом
    // ещё раз щёлкнуть по полю.
    search.value?.focus()
    scrollActiveIntoView()
  })
}

function close() {
  isOpen.value = false
  activeIndex.value = -1
  query.value = ''
}

// Набранное сдвигает список под собой, и прежнее место подсветки в нём уже
// ничего не значит.
watch(query, () => {
  activeIndex.value = visible.value.length > 0 ? 0 : -1
  nextTick(() => scrollActiveIntoView())
})

function choose(option: SelectOption<T>) {
  model.value = option.value
  close()
}

function move(step: number) {
  if (! isOpen.value) {
    open()

    return
  }

  if (visible.value.length === 0) {
    return
  }

  const next = activeIndex.value + step
  // Stops at the ends rather than wrapping: a list that jumps from the last
  // item back to the first hides how long it is.
  activeIndex.value = Math.min(Math.max(0, next), visible.value.length - 1)

  scrollActiveIntoView()
}

function scrollActiveIntoView() {
  list.value?.children[activeIndex.value]?.scrollIntoView({ block: 'nearest' })
}

/**
 * Клавиши, общие для кнопки и для поля поиска: ими ходят по списку.
 *
 * Пробел и Home/End сюда не входят намеренно — в поле поиска это пробел в слове
 * и перемещение по строке, а не выбор варианта и не прыжок в конец списка.
 */
function onNavigationKey(event: KeyboardEvent) {
  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault()
      move(1)
      break
    case 'ArrowUp':
      event.preventDefault()
      move(-1)
      break
    case 'Enter':
      event.preventDefault()
      if (isOpen.value && visible.value[activeIndex.value]) {
        choose(visible.value[activeIndex.value]!)
      }
      else {
        open()
      }
      break
    case 'Escape':
      if (isOpen.value) {
        event.preventDefault()
        close()
      }
      break
    case 'Tab':
      // Leaving the control commits nothing: the value only changes on Enter
      // or a click, as it does in a native select on every platform we target.
      close()
      break
  }
}

function onKeydown(event: KeyboardEvent) {
  switch (event.key) {
    case 'Home':
      if (isOpen.value) {
        event.preventDefault()
        activeIndex.value = 0
        scrollActiveIntoView()
      }
      break
    case 'End':
      if (isOpen.value) {
        event.preventDefault()
        activeIndex.value = visible.value.length - 1
        scrollActiveIntoView()
      }
      break
    case ' ':
      // На кнопке пробел работает как Enter — так ведёт себя и нативный
      // список; в поле поиска он остаётся пробелом, потому что там до этой
      // ветки дело не доходит.
      event.preventDefault()

      if (isOpen.value && visible.value[activeIndex.value]) {
        choose(visible.value[activeIndex.value]!)
      }
      else {
        open()
      }

      break
    default:
      onNavigationKey(event)
  }
}

function onPointerDown(event: PointerEvent) {
  if (!root.value?.contains(event.target as Node)) {
    close()
  }
}

onMounted(() => document.addEventListener('pointerdown', onPointerDown))
onBeforeUnmount(() => document.removeEventListener('pointerdown', onPointerDown))
</script>

<template>
  <div ref="root" class="select-field" :class="{ 'select-field--auto': auto }">
    <button
      :id="id"
      type="button"
      class="select-field__control"
      :class="{ 'select-field__control--empty': !selected }"
      :disabled="disabled"
      role="combobox"
      aria-haspopup="listbox"
      :aria-expanded="isOpen"
      @click="isOpen ? close() : open()"
      @keydown="onKeydown"
    >
      <span class="select-field__label">{{ label }}</span>

      <svg
        class="select-field__chevron"
        :class="{ 'select-field__chevron--open': isOpen }"
        viewBox="0 0 24 24"
        width="16"
        height="16"
        fill="none"
        stroke="currentColor"
        stroke-width="1.8"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
      >
        <path d="m6 9 6 6 6-6" />
      </svg>
    </button>

    <div v-if="isOpen" class="select-field__popup">
      <!-- Поиск в самом списке: искать нужное глазами в тридцати категориях —
           не выбор, а перебор. -->
      <div v-if="isSearchShown" class="select-field__search">
        <input
          ref="search"
          v-model="query"
          type="search"
          class="select-field__query"
          :placeholder="searchPlaceholder"
          aria-label="Поиск по списку"
          autocomplete="off"
          @keydown="onNavigationKey"
        >
      </div>

      <ul ref="list" class="select-field__list" role="listbox">
        <li
          v-for="(option, index) in visible"
          :key="String(option.value)"
          class="select-field__option"
          :class="{
            'select-field__option--active': index === activeIndex,
            'select-field__option--chosen': option.value === model,
          }"
          role="option"
          :aria-selected="option.value === model"
          @click="choose(option)"
          @mousemove="activeIndex = index"
        >
          <span class="select-field__option-label">{{ option.label }}</span>
          <span v-if="option.hint" class="select-field__option-hint">{{ option.hint }}</span>
        </li>
      </ul>

      <p v-if="!visible.length" class="select-field__empty">
        Ничего не нашли
      </p>
    </div>
  </div>
</template>

<style scoped>
.select-field {
  position: relative;
  width: 100%;
}

.select-field--auto {
  width: auto;
  min-width: 12rem;
}

.select-field__control {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  width: 100%;
  padding: 0.65rem 1rem;
  border: 1px solid transparent;
  border-radius: var(--radius-pill);
  background: var(--control-surface);
  color: var(--color-text);
  font: inherit;
  text-align: left;
  cursor: pointer;
  transition: border-color 0.15s ease;
}

.select-field__control:hover:not(:disabled) {
  border-color: var(--color-border-strong);
}

.select-field__control:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.select-field__control--empty .select-field__label {
  color: var(--color-text-faint);
}

.select-field__label {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.select-field__chevron {
  flex-shrink: 0;
  color: var(--color-text-muted);
  transition: transform 0.15s ease;
}

.select-field__chevron--open {
  transform: rotate(180deg);
}

.select-field__popup {
  position: absolute;
  z-index: 20;
  top: calc(100% + 0.35rem);
  left: 0;
  right: 0;
  padding: 0.3rem;
  background: var(--color-surface-raised);
  border-radius: var(--radius);
  box-shadow: var(--shadow-lg);
}

/* Поиск не уезжает вместе со списком: он остаётся на месте, пока варианты под
   ним прокручиваются. */
.select-field__search {
  padding: 0.15rem 0.15rem 0.35rem;
}

.select-field__query {
  width: 100%;
  padding: 0.45rem 0.7rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: var(--color-surface);
  color: var(--color-text);
  font: inherit;
}

.select-field__query:focus {
  outline: none;
  border-color: var(--color-border-strong);
}

.select-field__query::-webkit-search-cancel-button {
  display: none;
}

.select-field__list {
  max-height: 15rem;
  overflow-y: auto;
  margin: 0;
  padding: 0;
  list-style: none;
}

.select-field__empty {
  margin: 0;
  padding: 0.5rem 0.7rem 0.6rem;
  color: var(--color-text-muted);
  font-size: 0.88rem;
}

.select-field__option {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  padding: 0.5rem 1.6rem 0.5rem 0.7rem;
  border-radius: var(--radius-sm);
  cursor: pointer;
}

/* Where the pointer or the keyboard is. */
.select-field__option--active {
  background: var(--color-surface-sunken);
}

/* What is actually chosen — weight plus a lime mark, so it still reads when
   the cursor has moved the highlight somewhere else. */
.select-field__option--chosen .select-field__option-label {
  font-weight: 600;
}

.select-field__option--chosen::after {
  content: '';
  position: absolute;
  top: 50%;
  right: 0.7rem;
  width: 0.45rem;
  height: 0.45rem;
  border-radius: 50%;
  background: var(--color-highlight);
  transform: translateY(-50%);
}

.select-field__option-hint {
  color: var(--color-text-muted);
  font-size: 0.82rem;
}

@media (prefers-reduced-motion: reduce) {
  .select-field__chevron {
    transition: none;
  }
}
</style>