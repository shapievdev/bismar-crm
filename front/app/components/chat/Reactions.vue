<script setup lang="ts">
import type { MessageReaction } from '~/types/chat'

/**
 * Отклики под репликой.
 *
 * Стоят внизу пузыря и по нему же считаются: нажатие по уже стоящему знаку
 * снимает свой отклик, по чужому — ставит свой рядом. Это то же движение, что и
 * в подсказке, и разделять их на «добавить» и «убрать» не нужно — человек
 * просто нажимает по знаку.
 *
 * Цифра появляется со второго отклика: «👍 1» сообщает ровно столько же, сколько
 * «👍», и занимает вдвое больше.
 */
const props = defineProps<{
  reactions: MessageReaction[]
  /** Номер читателя: по нему свой отклик узнаётся в общем наборе. */
  me: number | null
}>()

const emit = defineEmits<{ react: [emoji: string] }>()

function isMine(reaction: MessageReaction): boolean {
  return props.me !== null && reaction.user_ids.includes(props.me)
}

/** «Иванов И., Петров П.» — подсказка на случай «а кто именно согласился». */
function whom(reaction: MessageReaction): string {
  if (reaction.people.length === 0) {
    return `${reaction.emoji} · ${reaction.count}`
  }

  const named = reaction.people.join(', ')
  const rest = reaction.count - reaction.people.length

  return rest > 0 ? `${named} и ещё ${rest}` : named
}
</script>

<template>
  <div v-if="reactions.length" class="reactions">
    <button
      v-for="reaction in reactions"
      :key="reaction.emoji"
      type="button"
      class="chip"
      :class="{ 'chip--mine': isMine(reaction) }"
      :title="whom(reaction)"
      @click.stop="emit('react', reaction.emoji)"
    >
      <span class="chip__sign">{{ reaction.emoji }}</span>
      <span v-if="reaction.count > 1" class="chip__count">{{ reaction.count }}</span>
    </button>
  </div>
</template>

<style scoped>
.reactions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem;
  margin-top: 0.35rem;
}

.chip {
  display: inline-flex;
  align-items: center;
  gap: 0.2rem;
  padding: 0.12rem 0.42rem;
  border: 1px solid transparent;
  border-radius: var(--radius-pill);
  background: rgba(var(--tint-rgb), 0.1);
  color: inherit;
  font: inherit;
  font-size: 0.8rem;
  line-height: 1.4;
  cursor: pointer;
  transition: transform 0.12s ease, background-color 0.15s ease, border-color 0.15s ease;
}

.chip:hover {
  background: rgba(var(--tint-rgb), 0.18);
}

/* Нажатие проседает под пальцем: отклик — самое частое движение в мессенджере,
   и он обязан отзываться, не дожидаясь ответа сервера. */
.chip:active {
  transform: scale(0.92);
}

/* Свой отклик обведён: в наборе из пяти знаков иначе не найти, который твой. */
.chip--mine {
  border-color: rgba(var(--tint-rgb), 0.45);
  background: rgba(var(--tint-rgb), 0.2);
}

/* Знак чуть крупнее цифры: читают его, а не её. */
.chip__sign {
  font-size: 0.92rem;
  line-height: 1;
}

.chip__count {
  font-variant-numeric: tabular-nums;
  font-weight: 600;
}

@media (prefers-reduced-motion: reduce) {
  .chip {
    transition: none;
  }

  .chip:active {
    transform: none;
  }
}
</style>
