<script setup lang="ts">
import type { Conversation } from '~/types/chat'
import { chatStamp } from '~/utils/chatTime'
import { plainMessageText } from '~/utils/messageText'

/**
 * Строчка списка переписок.
 *
 * Отвечает на три вопроса разом: с кем говорят, чем кончилось и надо ли туда
 * идти. Последнее — цифрой непрочитанного и отдельным значком «@», если в этих
 * непрочитанных зовут по имени: сорок реплик в рабочей группе можно прочесть
 * вечером, вопрос лично тебе — нельзя.
 */
const props = defineProps<{
  conversation: Conversation
  active: boolean
  /** В сети ли собеседник — у группы точки нет. */
  online: boolean
  /** Кто читает: по нему подписывается своё последнее сообщение. */
  me: number | null
}>()

const emit = defineEmits<{ open: [], menu: [at: { x: number, y: number }] }>()

/**
 * Подпись под именем: кто и что сказал последним.
 *
 * Без разметки: звёздочки и подчёркивания в одной строке выглядят опечаткой, а
 * не жирным шрифтом. В группе добавляется имя говорившего — иначе непонятно,
 * кому отвечать.
 */
const preview = computed(() => {
  const last = props.conversation.last_message

  if (!last) {
    return 'Пока ничего не сказано'
  }

  const said = last.body
    ? plainMessageText(last.body)
    : (last.attachments?.some(file => file.is_voice)
        ? 'Голосовое сообщение'
        : (last.attachments?.length ? 'Файл' : ''))

  if (last.kind === 'system') {
    return said
  }

  const who = last.author?.id === props.me
    ? 'Вы: '
    : (props.conversation.is_group && last.author ? `${last.author.short_name}: ` : '')

  return `${who}${said}`
})

/** Долгое нажатие по строчке — то же меню, что и правая кнопка. */
let holding = 0

function onTouchStart(event: TouchEvent): void {
  const touch = event.touches[0]

  if (!touch) {
    return
  }

  const at = { x: touch.clientX, y: touch.clientY }

  holding = window.setTimeout(() => {
    holding = 0
    navigator.vibrate?.(8)
    emit('menu', at)
  }, 450)
}

function stopHolding(): void {
  if (holding) {
    window.clearTimeout(holding)
    holding = 0
  }
}

onBeforeUnmount(stopHolding)
</script>

<template>
  <button
    type="button"
    class="row"
    :class="{ 'row--active': active }"
    @click="emit('open')"
    @contextmenu.prevent="emit('menu', { x: $event.clientX, y: $event.clientY })"
    @touchstart.passive="onTouchStart"
    @touchmove.passive="stopHolding"
    @touchend="stopHolding"
    @touchcancel="stopHolding"
  >
    <span class="row__face">
      <UserAvatar
        :name="conversation.title"
        :src="conversation.companion?.avatar_url ?? null"
        :size="44"
      />
      <!-- Зелёная точка у собеседника: presence-канал знает, кто сейчас
           подключён, и это не стоит ни запроса, ни строки в базе. -->
      <span v-if="!conversation.is_group && online" class="row__online" title="В сети" />
    </span>

    <span class="row__body">
      <span class="row__top">
        <span class="row__name">
          <svg v-if="conversation.is_pinned" class="row__pin" viewBox="0 0 24 24" aria-label="Закреплено" role="img">
            <path
              d="M9 3h6l-1 6 3 3v2H7v-2l3-3-1-6zM12 14v7"
              stroke="currentColor"
              stroke-width="1.8"
              stroke-linecap="round"
              stroke-linejoin="round"
              fill="none"
            />
          </svg>
          {{ conversation.title }}
        </span>
        <span class="row__time faint">{{ chatStamp(conversation.last_message_at) }}</span>
      </span>

      <span class="row__bottom">
        <span class="row__preview faint">{{ preview }}</span>

        <span class="row__marks">
          <!-- Приглушённый разговор считает непрочитанное по-прежнему: приглушают
               звук, а не сам разговор. Значок стоит рядом с цифрой, чтобы было
               видно, почему телефон молчал. -->
          <svg
            v-if="conversation.is_muted"
            class="row__mute"
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

          <span v-if="conversation.unread_mentions" class="row__at" title="Вас упомянули">@</span>

          <span
            v-if="conversation.unread_count"
            class="row__unread"
            :class="{ 'row__unread--muted': conversation.is_muted }"
          >
            {{ conversation.unread_count > 99 ? '99+' : conversation.unread_count }}
          </span>
        </span>
      </span>
    </span>
  </button>
</template>

<style scoped>
.row {
  display: flex;
  width: 100%;
  align-items: center;
  gap: 0.7rem;
  padding: 0.5rem 0.6rem;
  border: none;
  border-radius: var(--radius);
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
  transition: background-color 0.15s ease;
}

.row:hover {
  background: var(--control-surface-hover);
}

.row--active {
  background: var(--color-surface-sunken);
}

.row__face {
  position: relative;
  flex-shrink: 0;
}

.row__online {
  position: absolute;
  right: -1px;
  bottom: -1px;
  width: 0.72rem;
  height: 0.72rem;
  border: 2px solid var(--color-surface);
  border-radius: 50%;
  background: var(--color-success);
}

.row__body {
  display: flex;
  min-width: 0;
  flex: 1;
  flex-direction: column;
  gap: 0.1rem;
}

/*
 * `min-width: 0` на каждом уровне, и это не перестраховка.
 *
 * Гибкий элемент по умолчанию не ужимается меньше своего содержимого, и
 * многоточие в строке предпросмотра включается только тогда, когда ужиматься
 * ей разрешено. Стоило пропустить один уровень — и присланная ссылка на
 * полтораста знаков распирала строку, за ней колонку, а за колонкой и весь
 * список: он уезжал за край экрана вместе с именами и поиском.
 */
.row__top,
.row__bottom {
  display: flex;
  min-width: 0;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.row__name {
  display: flex;
  min-width: 0;
  align-items: center;
  gap: 0.25rem;
  overflow: hidden;
  font-size: 0.92rem;
  font-weight: 550;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.row__pin {
  width: 0.75rem;
  height: 0.75rem;
  flex-shrink: 0;
  color: var(--color-text-faint);
}

.row__time {
  flex-shrink: 0;
  font-size: 0.72rem;
}

.row__preview {
  overflow: hidden;
  min-width: 0;
  flex: 1;
  font-size: 0.82rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.row__marks {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  gap: 0.28rem;
}

.row__mute {
  width: 0.85rem;
  height: 0.85rem;
  color: var(--color-text-faint);
}

.row__at {
  display: grid;
  place-items: center;
  width: 1.15rem;
  height: 1.15rem;
  border-radius: 50%;
  background: var(--color-accent);
  color: var(--color-accent-text);
  font-size: 0.72rem;
  font-weight: 700;
}

.row__unread {
  min-width: 1.3rem;
  padding: 0 0.35rem;
  border-radius: var(--radius-pill);
  background: var(--color-accent);
  color: var(--color-accent-text);
  font-size: 0.73rem;
  font-variant-numeric: tabular-nums;
  font-weight: 600;
  line-height: 1.3rem;
  text-align: center;
}

/* У приглушённого цифра тусклая: она сообщает, а не зовёт. */
.row__unread--muted {
  background: var(--color-border-strong);
  color: var(--color-text-muted);
}

@media (prefers-reduced-motion: reduce) {
  .row {
    transition: none;
  }
}
</style>
