<script setup lang="ts">
import type { ChatMessage } from '~/types/chat'
import { plainMessageText } from '~/utils/messageText'

/**
 * Полоса закреплённого над лентой.
 *
 * Показывает одно за раз и листает по нажатию — как в телеграме. Список из пяти
 * строк съел бы полэкрана ради того, к чему возвращаются раз в день; одна
 * строка с чёрточками сбоку говорит и что закреплено, и сколько их всего.
 */
const props = defineProps<{
  messages: ChatMessage[]
  /** Может ли этот человек снимать закрепление. */
  canUnpin: boolean
}>()

const emit = defineEmits<{ jump: [messageId: number], unpin: [messageId: number] }>()

const at = ref(0)

const current = computed(() => props.messages[at.value] ?? null)

// Закреплённых стало меньше — указатель мог остаться за краем.
watch(() => props.messages.length, (count) => {
  if (at.value >= count) {
    at.value = 0
  }
})

/**
 * Нажатие делает два дела разом: переносит к закреплённому и переводит полосу к
 * следующему. Так одним и тем же нажатием обходят все закреплённые по кругу —
 * отдельных стрелок для этого не нужно.
 */
function step(): void {
  const target = current.value

  if (!target) {
    return
  }

  emit('jump', target.id)

  if (props.messages.length > 1) {
    at.value = (at.value + 1) % props.messages.length
  }
}

function excerpt(message: ChatMessage): string {
  if (message.body) {
    return plainMessageText(message.body)
  }

  if (message.attachments?.some(file => file.is_voice)) {
    return 'Голосовое сообщение'
  }

  return message.attachments?.length ? 'Вложение' : 'Сообщение'
}
</script>

<template>
  <div v-if="current" class="pinned">
    <!-- Чёрточки слева: сколько закреплено и которое из них показано. При одном
         закреплённом это просто полоска. -->
    <span class="ticks" aria-hidden="true">
      <span
        v-for="(message, index) in messages"
        :key="message.id"
        class="ticks__one"
        :class="{ 'ticks__one--on': index === at }"
      />
    </span>

    <button type="button" class="pinned__body" @click="step">
      <span class="pinned__label">
        Закреплённое<template v-if="messages.length > 1"> {{ at + 1 }} из {{ messages.length }}</template>
      </span>
      <span class="pinned__text">{{ excerpt(current) }}</span>
    </button>

    <button
      v-if="canUnpin"
      type="button"
      class="pinned__off"
      aria-label="Открепить"
      @click.stop="emit('unpin', current.id)"
    >
      ✕
    </button>
  </div>
</template>

<style scoped>
.pinned {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.4rem var(--pane-pad);
  border-bottom: 1px solid var(--color-border);
  background: var(--color-surface);
}

.ticks {
  display: flex;
  height: 1.9rem;
  flex-direction: column;
  gap: 2px;
  justify-content: stretch;
  width: 2px;
}

.ticks__one {
  flex: 1;
  border-radius: var(--radius-pill);
  background: var(--color-border-strong);
  transition: background-color 0.2s ease;
}

.ticks__one--on {
  background: var(--color-accent);
}

.pinned__body {
  display: flex;
  min-width: 0;
  flex: 1;
  flex-direction: column;
  padding: 0;
  border: none;
  background: none;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.pinned__label {
  font-size: 0.7rem;
  font-weight: 600;
  color: var(--color-accent);
}

.pinned__text {
  overflow: hidden;
  font-size: 0.82rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.pinned__off {
  flex-shrink: 0;
  padding: 0.15rem 0.35rem;
  border: none;
  background: none;
  color: var(--color-text-faint);
  font: inherit;
  cursor: pointer;
}

.pinned__off:hover {
  color: var(--color-text);
}

@media (prefers-reduced-motion: reduce) {
  .ticks__one {
    transition: none;
  }
}
</style>
