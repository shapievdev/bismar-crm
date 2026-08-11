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
}>(), { placeholder: 'Не выбрано', disabled: false, auto: false })

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

/** Where the keyboard is, which is not where the selection is until Enter. */
const activeIndex = ref(-1)

const selected = computed(() => props.options.find(option => option.value === model.value) ?? null)
const label = computed(() => selected.value?.label ?? props.placeholder)

function open() {
  if (props.disabled) {
    return
  }

  isOpen.value = true
  activeIndex.value = Math.max(0, props.options.findIndex(option => option.value === model.value))

  nextTick(() => scrollActiveIntoView())
}

function close() {
  isOpen.value = false
  activeIndex.value = -1
}

function choose(option: SelectOption<T>) {
  model.value = option.value
  close()
}

function move(step: number) {
  if (props.options.length === 0) {
    return
  }

  if (!isOpen.value) {
    open()

    return
  }

  const next = activeIndex.value + step
  // Stops at the ends rather than wrapping: a list that jumps from the last
  // item back to the first hides how long it is.
  activeIndex.value = Math.min(Math.max(0, next), props.options.length - 1)

  scrollActiveIntoView()
}

function scrollActiveIntoView() {
  list.value?.children[activeIndex.value]?.scrollIntoView({ block: 'nearest' })
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
        activeIndex.value = props.options.length - 1
        scrollActiveIntoView()
      }
      break
    case 'Enter':
    case ' ':
      event.preventDefault()
      if (isOpen.value && props.options[activeIndex.value]) {
        choose(props.options[activeIndex.value]!)
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

    <ul v-if="isOpen" ref="list" class="select-field__list" role="listbox">
      <li
        v-for="(option, index) in options"
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

.select-field__list {
  position: absolute;
  z-index: 20;
  top: calc(100% + 0.35rem);
  left: 0;
  right: 0;
  max-height: 16rem;
  overflow-y: auto;
  margin: 0;
  padding: 0.3rem;
  list-style: none;
  background: var(--color-surface-raised);
  border-radius: var(--radius);
  box-shadow: var(--shadow-lg);
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