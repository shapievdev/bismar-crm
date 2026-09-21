<script setup lang="ts">
import type { Conversation } from '~/types/chat'
import { pluralise } from '~/utils/plural'

/**
 * Куда переслать выделенное.
 *
 * Список тот же, что слева, и отбирается на месте: переписки у вкладки уже на
 * руках. Отдельного поиска по людям здесь нет намеренно — переслать можно в
 * разговор, а разговора с человеком, которому ещё не писали, не существует;
 * заводят его кнопкой «Написать», и это другое действие.
 */
const props = defineProps<{
  conversations: Conversation[]
  /** Откуда пересылают: этот разговор в списке не предлагают. */
  fromId: number
  count: number
}>()

const emit = defineEmits<{ pick: [conversationId: number], close: [] }>()

const query = ref('')

const options = computed(() => {
  const needle = query.value.trim()

  return props.conversations
    .filter(one => one.id !== props.fromId)
    .filter(one => needle === '' || matchesTyped(one.title, needle))
})
</script>

<template>
  <div class="sheet" @click.self="emit('close')">
    <div class="sheet__panel card">
      <header class="sheet__head">
        <h2 class="sheet__title">
          Переслать {{ count }} {{ pluralise(count, 'сообщение', 'сообщения', 'сообщений') }}
        </h2>
        <button type="button" class="button-ghost button-sm" @click="emit('close')">
          Закрыть
        </button>
      </header>

      <input v-model="query" type="search" class="input" placeholder="Кому: имя или название группы">

      <ul class="sheet__list">
        <li v-for="conversation in options" :key="conversation.id">
          <button type="button" class="option" @click="emit('pick', conversation.id)">
            <UserAvatar
              :name="conversation.title"
              :src="conversation.companion?.avatar_url ?? null"
              :size="32"
            />
            <span class="option__title">{{ conversation.title }}</span>
          </button>
        </li>
      </ul>

      <p v-if="!options.length" class="muted">
        Подходящих переписок нет.
      </p>
    </div>
  </div>
</template>

<style scoped>
.sheet {
  position: fixed;
  inset: 0;
  z-index: 70;
  display: grid;
  place-items: center;
  padding: 1rem;
  background: rgb(0 0 0 / 40%);
  animation: veil 0.16s ease;
}

@keyframes veil {
  from { opacity: 0; }
  to { opacity: 1; }
}

.sheet__panel {
  display: flex;
  width: min(26rem, 100%);
  max-height: min(32rem, 90vh);
  flex-direction: column;
  gap: 0.6rem;
  animation: rise 0.2s cubic-bezier(0.22, 1, 0.36, 1);
}

@keyframes rise {
  from { opacity: 0; transform: translateY(1rem) scale(0.98); }
  to { opacity: 1; transform: none; }
}

.sheet__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.sheet__title {
  margin: 0;
  font-size: 1.05rem;
}

.sheet__list {
  display: flex;
  min-height: 0;
  flex: 1;
  flex-direction: column;
  gap: 0.1rem;
  overflow-y: auto;
  margin: 0;
  padding: 0;
  list-style: none;
}

.option {
  display: flex;
  width: 100%;
  align-items: center;
  gap: 0.6rem;
  padding: 0.45rem 0.5rem;
  border: none;
  border-radius: var(--radius-sm);
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.option:hover {
  background: var(--control-surface-hover);
}

.option__title {
  overflow: hidden;
  font-size: 0.9rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}

@media (prefers-reduced-motion: reduce) {
  .sheet,
  .sheet__panel {
    animation: none;
  }
}
</style>
