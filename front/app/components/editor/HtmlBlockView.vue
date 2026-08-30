<script setup lang="ts">
import { NodeViewWrapper, nodeViewProps } from '@tiptap/vue-3'
import type { ThemePreference } from '~/composables/useTheme'
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

const isEditing = ref(false)
const draft = ref<string>(props.node.attrs.html ?? '')

const html = computed<string>(() => props.node.attrs.html ?? '')

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
  ? htmlBlockDocument(html.value, { theme, token })
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
    scrollOuterPageTo(Number(data.offset))
    return
  }

  if (data.type !== HTML_BLOCK_HEIGHT_MESSAGE) {
    return
  }

  const height = Number(data.height)

  if (!Number.isFinite(height) || height <= 0 || isSettled.value) {
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

onMounted(() => window.addEventListener('message', onMessage))
onBeforeUnmount(() => {
  window.removeEventListener('message', onMessage)
  clearTimeout(settleTimer)
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

    <!--
      No loading="lazy" here. A srcdoc frame has no network request to trigger
      the deferred load, so Chrome leaves it blank indefinitely.
    -->
    <iframe
      v-else-if="html"
      ref="frame"
      class="html-block__frame"
      :style="{ height: `${effectiveHeight}px` }"
      :sandbox="SANDBOX"
      :srcdoc="srcdoc"
      title="Встроенный HTML"
    />

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

.html-block__empty {
  margin: 0;
  padding: 1.5rem;
  text-align: center;
  font-size: 0.88rem;
}
</style>