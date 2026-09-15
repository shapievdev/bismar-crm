<script setup lang="ts">
import type { ChatPerson, Conversation } from '~/types/chat'

/**
 * Шапка открытой переписки.
 *
 * Под именем — строка состояния, и она сменяется по важности: «печатает»
 * перекрывает всё, потому что это происходит прямо сейчас; дальше состав группы
 * или «в сети». Показывать их одновременно негде, а спорят они редко.
 */
defineProps<{
  conversation: Conversation
  /** Кто печатает прямо сейчас. */
  typing: ChatPerson[]
  online: boolean
  managing: boolean
}>()

const emit = defineEmits<{
  back: []
  search: []
  toggleCrew: []
  menu: [at: { x: number, y: number }]
}>()

const typingLabel = (typing: ChatPerson[]): string => {
  if (typing.length === 0) {
    return ''
  }

  return typing.length === 1 ? `${typing[0]?.short_name} печатает…` : 'Печатают…'
}
</script>

<template>
  <header class="head">
    <button type="button" class="head__back" aria-label="К списку" @click="emit('back')">
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path
          d="M15 5l-7 7 7 7"
          stroke="currentColor"
          stroke-width="2"
          stroke-linecap="round"
          stroke-linejoin="round"
          fill="none"
        />
      </svg>
    </button>

    <UserAvatar
      class="head__avatar"
      :name="conversation.title"
      :src="conversation.companion?.avatar_url ?? null"
      :size="40"
    />

    <div class="head__who">
      <span class="head__name">
        {{ conversation.title }}
        <svg
          v-if="conversation.is_muted"
          class="head__mute"
          viewBox="0 0 24 24"
          aria-label="Без уведомлений"
          role="img"
        >
          <path
            d="M6 9a6 6 0 0 1 9.4-4.9M18 10v4l2 3H7M4 4l16 16M10 20a2 2 0 0 0 4 0"
            stroke="currentColor"
            stroke-width="1.7"
            stroke-linecap="round"
            stroke-linejoin="round"
            fill="none"
          />
        </svg>
      </span>

      <!-- Строка состояния меняется на месте, с проявлением: подмена текста
           рывком читается как ошибка загрузки. -->
      <Transition name="fade" mode="out-in">
        <span v-if="typingLabel(typing)" key="typing" class="head__status head__status--live">
          {{ typingLabel(typing) }}
        </span>
        <span v-else-if="conversation.is_group" key="crew" class="faint head__status">
          {{ conversation.participants_count }} участников
        </span>
        <span v-else-if="online" key="online" class="head__status head__status--live">В сети</span>
        <span v-else key="offline" class="faint head__status">Не в сети</span>
      </Transition>
    </div>

    <button type="button" class="head__tool" aria-label="Поиск по переписке" @click="emit('search')">
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <circle cx="11" cy="11" r="6.5" stroke="currentColor" stroke-width="2" fill="none" />
        <path d="M16 16l4.5 4.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
      </svg>
    </button>

    <button
      v-if="conversation.is_group"
      type="button"
      class="head__tool"
      :aria-pressed="managing"
      aria-label="Участники"
      @click="emit('toggleCrew')"
    >
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <circle cx="9" cy="8" r="3.2" stroke="currentColor" stroke-width="1.8" fill="none" />
        <path d="M3.5 19a5.5 5.5 0 0 1 11 0" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linecap="round" />
        <path d="M16 6.2a3 3 0 0 1 0 5.6M17.5 19a5.5 5.5 0 0 0-2-3.9" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linecap="round" />
      </svg>
    </button>

    <button
      type="button"
      class="head__tool"
      aria-label="Действия с перепиской"
      @click="emit('menu', { x: $event.clientX, y: $event.clientY })"
    >
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <circle cx="12" cy="5" r="1.8" fill="currentColor" />
        <circle cx="12" cy="12" r="1.8" fill="currentColor" />
        <circle cx="12" cy="19" r="1.8" fill="currentColor" />
      </svg>
    </button>
  </header>
</template>

<style scoped>
.head {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.6rem var(--pane-pad);
  border-bottom: 1px solid var(--color-border);
}

/* На столе кнопки «назад» нет: обе панели и так на экране. */
.head__back {
  display: none;
  padding: 0;
  border: none;
  background: none;
  color: inherit;
  cursor: pointer;
}

.head__back svg {
  width: 1.4rem;
  height: 1.4rem;
}

.head__avatar {
  flex-shrink: 0;
}

.head__who {
  display: flex;
  min-width: 0;
  flex: 1;
  flex-direction: column;
}

.head__name {
  display: flex;
  align-items: center;
  gap: 0.3rem;
  overflow: hidden;
  font-weight: 550;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.head__mute {
  width: 0.85rem;
  height: 0.85rem;
  flex-shrink: 0;
  color: var(--color-text-faint);
}

.head__status {
  overflow: hidden;
  font-size: 0.78rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Происходящее сейчас — цветом: «печатает» и «в сети» отличаются от справки о
   составе тем, что они правда прямо сейчас. */
.head__status--live {
  color: var(--color-success);
}

.head__tool {
  display: grid;
  flex-shrink: 0;
  place-items: center;
  width: 2.1rem;
  height: 2.1rem;
  padding: 0;
  border: none;
  border-radius: 50%;
  background: transparent;
  color: var(--color-text-muted);
  cursor: pointer;
  transition: background-color 0.15s ease, color 0.15s ease;
}

.head__tool:hover {
  background: var(--control-surface-hover);
  color: var(--color-text);
}

.head__tool[aria-pressed='true'] {
  background: var(--color-surface-sunken);
  color: var(--color-text);
}

.head__tool svg {
  width: 1.2rem;
  height: 1.2rem;
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.14s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

@media (max-width: 47.9rem) {
  .head__back {
    display: block;
  }
}

@media (prefers-reduced-motion: reduce) {
  .head__tool,
  .fade-enter-active,
  .fade-leave-active {
    transition: none;
  }
}
</style>
