<script setup lang="ts">
import { pluralise } from '~/utils/plural'

/**
 * Панель режима выделения — вместо шапки, пока что-то выбрано.
 *
 * Вместо, а не рядом: выделение — это отдельное состояние разговора, и оставлять
 * на виду кнопки, которые в нём ничего не делают, значит предлагать нажать на
 * них. Выйти из него можно крестиком и клавишей Esc.
 */
defineProps<{
  count: number
  /** Можно ли удалить всё выделенное: чужое в группе удаляет только владелец. */
  canDelete: boolean
}>()

const emit = defineEmits<{ close: [], forward: [], copy: [], remove: [] }>()
</script>

<template>
  <header class="bar">
    <button type="button" class="bar__tool" aria-label="Отменить выделение" @click="emit('close')">
      ✕
    </button>

    <span class="bar__count">
      {{ count }} {{ pluralise(count, 'сообщение', 'сообщения', 'сообщений') }}
    </span>

    <button type="button" class="bar__tool" aria-label="Скопировать" title="Скопировать" @click="emit('copy')">
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <rect x="9" y="9" width="11" height="11" rx="2.5" stroke="currentColor" stroke-width="1.8" fill="none" />
        <path d="M15 5.5A2.5 2.5 0 0 0 12.5 3h-6A3.5 3.5 0 0 0 3 6.5v6A2.5 2.5 0 0 0 5.5 15" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linecap="round" />
      </svg>
    </button>

    <button type="button" class="bar__tool" aria-label="Переслать" title="Переслать" @click="emit('forward')">
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M15 5l6 5-6 5v-3H9a5 5 0 0 0-5 5v-2a7 7 0 0 1 7-7h4z" fill="currentColor" />
      </svg>
    </button>

    <button
      v-if="canDelete"
      type="button"
      class="bar__tool bar__tool--danger"
      aria-label="Удалить"
      title="Удалить"
      @click="emit('remove')"
    >
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path
          d="M5 7h14M10 7V5h4v2M7 7l1 13h8l1-13M11 11v6M13 11v6"
          stroke="currentColor"
          stroke-width="1.8"
          stroke-linecap="round"
          stroke-linejoin="round"
          fill="none"
        />
      </svg>
    </button>
  </header>
</template>

<style scoped>
.bar {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.6rem var(--pane-pad);
  border-bottom: 1px solid var(--color-border);
  background: var(--color-surface);
  /* Въезжает сверху, подменяя шапку: так видно, что разговор перешёл в другое
     состояние, а не что над ним появилась ещё одна полоса. */
  animation: drop 0.16s ease-out;
}

@keyframes drop {
  from { opacity: 0; transform: translateY(-0.5rem); }
  to { opacity: 1; transform: none; }
}

.bar__count {
  flex: 1;
  font-size: 0.92rem;
  font-weight: 550;
}

.bar__tool {
  display: grid;
  place-items: center;
  width: 2.1rem;
  height: 2.1rem;
  padding: 0;
  border: none;
  border-radius: 50%;
  background: transparent;
  color: var(--color-text-muted);
  font: inherit;
  cursor: pointer;
  transition: background-color 0.15s ease, color 0.15s ease;
}

.bar__tool:hover {
  background: var(--control-surface-hover);
  color: var(--color-text);
}

.bar__tool--danger:hover {
  color: var(--color-danger);
}

.bar__tool svg {
  width: 1.2rem;
  height: 1.2rem;
}

@media (prefers-reduced-motion: reduce) {
  .bar {
    animation: none;
  }

  .bar__tool {
    transition: none;
  }
}
</style>
