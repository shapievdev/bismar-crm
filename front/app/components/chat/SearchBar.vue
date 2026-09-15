<script setup lang="ts">
import type { MessageHit } from '~/types/chat'

/**
 * Поиск по открытой переписке.
 *
 * Стоит под шапкой и работает как в телеграме: нашли — переносимся к последней
 * находке, стрелками ходим по остальным, счётчик говорит, сколько их всего.
 *
 * Именно от свежих к старым, а не наоборот: ищут в переписке обычно недавнее, и
 * начинать обход с реплики годичной давности значило бы заставлять человека
 * пролистать весь год.
 */
const props = defineProps<{ conversationId: number }>()

const emit = defineEmits<{ close: [], jump: [messageId: number] }>()

const api = useChatApi()

const query = ref('')
const hits = ref<MessageHit[]>([])
const total = ref(0)
const at = ref(-1)
const searching = ref(false)

const field = ref<HTMLInputElement | null>(null)

onMounted(() => field.value?.focus())

let timer: ReturnType<typeof setTimeout> | undefined

watch(query, (value) => {
  clearTimeout(timer)

  if (value.trim().length < 2) {
    hits.value = []
    total.value = 0
    at.value = -1

    return
  }

  searching.value = true

  timer = setTimeout(async () => {
    try {
      const found = await api.searchInConversation(props.conversationId, value.trim())

      hits.value = found.data
      total.value = found.meta.total

      // Сразу переносим к первой находке: ещё одно нажатие ради того, что и так
      // единственное разумное действие, — лишнее.
      if (found.data.length > 0) {
        at.value = 0
        emit('jump', found.data[0]!.id)
      }
      else {
        at.value = -1
      }
    }
    finally {
      searching.value = false
    }
  }, 300)
})

onBeforeUnmount(() => clearTimeout(timer))

/**
 * Следующая находка. Вниз по списку — значит назад по времени: список идёт от
 * свежих к старым, и стрелка «вниз» ведёт в прошлое, как и прокрутка ленты.
 */
async function step(by: number): Promise<void> {
  const next = at.value + by

  if (next < 0 || hits.value.length === 0) {
    return
  }

  // Дошли до конца загруженного, а всего находок больше — догружаем следующую
  // страницу, не прерывая обхода.
  if (next >= hits.value.length) {
    if (hits.value.length >= total.value) {
      return
    }

    const oldest = hits.value[hits.value.length - 1]
    const more = await api.searchInConversation(props.conversationId, query.value.trim(), oldest?.id)

    if (more.data.length === 0) {
      return
    }

    hits.value = [...hits.value, ...more.data]
  }

  at.value = next
  emit('jump', hits.value[next]!.id)
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    emit('close')

    return
  }

  // Enter ведёт к следующей находке, Shift+Enter — к предыдущей: руки уже
  // научены этим в поиске по странице.
  if (event.key === 'Enter') {
    event.preventDefault()
    void step(event.shiftKey ? -1 : 1)
  }
}
</script>

<template>
  <div class="find">
    <input
      ref="field"
      v-model="query"
      type="search"
      class="find__field"
      placeholder="Найти в переписке"
      aria-label="Найти в переписке"
      @keydown="onKeydown"
    >

    <span class="find__count faint">
      <template v-if="searching">Ищем…</template>
      <template v-else-if="query.trim().length >= 2 && total === 0">Ничего</template>
      <template v-else-if="total">{{ at + 1 }} из {{ total }}</template>
    </span>

    <button
      type="button"
      class="find__step"
      :disabled="at <= 0"
      aria-label="Предыдущая находка"
      @click="step(-1)"
    >
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M6 15l6-6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none" />
      </svg>
    </button>

    <button
      type="button"
      class="find__step"
      :disabled="at < 0 || at + 1 >= total"
      aria-label="Следующая находка"
      @click="step(1)"
    >
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none" />
      </svg>
    </button>

    <button type="button" class="find__close" aria-label="Закрыть поиск" @click="emit('close')">
      ✕
    </button>
  </div>
</template>

<style scoped>
.find {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.5rem var(--pane-pad);
  border-bottom: 1px solid var(--color-border);
  background: var(--color-surface);
  animation: drop 0.16s ease-out;
}

@keyframes drop {
  from { opacity: 0; transform: translateY(-0.4rem); }
  to { opacity: 1; transform: none; }
}

.find__field {
  min-width: 0;
  flex: 1;
  padding: 0.4rem 0.7rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-pill);
  background: var(--color-bg);
  color: inherit;
  font: inherit;
  font-size: 0.88rem;
}

.find__field:focus {
  border-color: var(--color-border-strong);
  outline: none;
}

.find__field::-webkit-search-cancel-button {
  display: none;
}

.find__count {
  flex-shrink: 0;
  font-size: 0.75rem;
  font-variant-numeric: tabular-nums;
}

.find__step,
.find__close {
  display: grid;
  flex-shrink: 0;
  place-items: center;
  width: 1.9rem;
  height: 1.9rem;
  padding: 0;
  border: none;
  border-radius: 50%;
  background: transparent;
  color: var(--color-text-muted);
  font: inherit;
  cursor: pointer;
}

.find__step:hover:not(:disabled),
.find__close:hover {
  background: var(--control-surface-hover);
  color: var(--color-text);
}

.find__step:disabled {
  opacity: 0.35;
  cursor: default;
}

.find__step svg {
  width: 1.1rem;
  height: 1.1rem;
}

@media (prefers-reduced-motion: reduce) {
  .find {
    animation: none;
  }
}
</style>
