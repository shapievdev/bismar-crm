<script setup lang="ts">
import type { EmojiItem } from '~/utils/chatEmoji'
import { EMOJI_GROUPS, RECENT_EMOJI_KEY, RECENT_EMOJI_LIMIT, searchEmoji } from '~/utils/chatEmoji'

/**
 * Подсказка со знаками над полем ввода.
 *
 * Недавние стоят первыми и ведутся сами: в рабочей переписке один и тот же
 * десяток знаков покрывает почти всё, и искать их каждый раз заново — лишняя
 * работа. Хранит их сам браузер: это привычка человека за этим устройством, а
 * не запись о нём.
 */
const emit = defineEmits<{ pick: [emoji: string], close: [] }>()

const query = ref('')
const recent = ref<string[]>([])

const found = computed<EmojiItem[]>(() => searchEmoji(query.value))

onMounted(() => {
  recent.value = readRecent()
})

function readRecent(): string[] {
  try {
    const raw = window.localStorage.getItem(RECENT_EMOJI_KEY)

    return raw ? (JSON.parse(raw) as string[]).slice(0, RECENT_EMOJI_LIMIT) : []
  }
  catch {
    return []
  }
}

function pick(emoji: string): void {
  recent.value = [emoji, ...recent.value.filter(one => one !== emoji)].slice(0, RECENT_EMOJI_LIMIT)

  try {
    window.localStorage.setItem(RECENT_EMOJI_KEY, JSON.stringify(recent.value))
  }
  catch {
    // Закрытое хранилище — не повод не вставить знак: недавние просто не
    // переживут перезагрузку.
  }

  emit('pick', emoji)
}
</script>

<template>
  <div class="picker" @click.stop>
    <input
      v-model="query"
      type="search"
      class="picker__find"
      placeholder="Найти знак"
      aria-label="Найти знак"
    >

    <div class="picker__body">
      <template v-if="query.trim()">
        <div v-if="found.length" class="grid">
          <button
            v-for="item in found"
            :key="item.emoji"
            type="button"
            class="grid__sign"
            @click="pick(item.emoji)"
          >
            {{ item.emoji }}
          </button>
        </div>
        <p v-else class="faint picker__empty">
          Ничего не нашлось.
        </p>
      </template>

      <template v-else>
        <template v-if="recent.length">
          <p class="picker__label faint">
            Недавние
          </p>
          <div class="grid">
            <button
              v-for="emoji in recent"
              :key="`recent-${emoji}`"
              type="button"
              class="grid__sign"
              @click="pick(emoji)"
            >
              {{ emoji }}
            </button>
          </div>
        </template>

        <template v-for="group in EMOJI_GROUPS" :key="group.title">
          <p class="picker__label faint">
            {{ group.title }}
          </p>
          <div class="grid">
            <button
              v-for="item in group.items"
              :key="item.emoji"
              type="button"
              class="grid__sign"
              @click="pick(item.emoji)"
            >
              {{ item.emoji }}
            </button>
          </div>
        </template>
      </template>
    </div>
  </div>
</template>

<style scoped>
/*
 * Прижата к правому краю кнопки, а не к левому: кнопка знаков стоит у правого
 * края переписки, и подсказка, растущая вправо, уезжала за него — а панель
 * переписки обрезает всё, что из неё торчит.
 */
.picker {
  position: absolute;
  right: 0;
  bottom: calc(100% + 0.5rem);
  z-index: 20;
  display: flex;
  width: min(20rem, calc(100vw - 2rem));
  flex-direction: column;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface-raised);
  box-shadow: var(--shadow-lg);
  /* Вырастает из кнопки, а не появляется по центру: так видно, что она
     относится к полю ввода. */
  transform-origin: bottom right;
  animation: grow 0.15s ease-out;
}

@keyframes grow {
  from { opacity: 0; transform: scale(0.94) translateY(0.4rem); }
  to { opacity: 1; transform: none; }
}

.picker__find {
  margin: 0.5rem 0.5rem 0.25rem;
  padding: 0.4rem 0.7rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-pill);
  background: var(--color-bg);
  color: inherit;
  font: inherit;
  font-size: 0.85rem;
}

.picker__find:focus {
  border-color: var(--color-border-strong);
  outline: none;
}

.picker__body {
  max-height: 17rem;
  overflow-y: auto;
  padding: 0 0.4rem 0.5rem;
}

.picker__label {
  margin: 0.5rem 0 0.15rem;
  padding: 0 0.2rem;
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.picker__empty {
  padding: 0.6rem 0.2rem;
  font-size: 0.85rem;
}

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(2.1rem, 1fr));
  gap: 0.1rem;
}

.grid__sign {
  display: grid;
  place-items: center;
  height: 2.1rem;
  padding: 0;
  border: none;
  border-radius: var(--radius-sm);
  background: transparent;
  font-size: 1.25rem;
  line-height: 1;
  cursor: pointer;
  transition: transform 0.1s ease, background-color 0.12s ease;
}

.grid__sign:hover {
  background: var(--control-surface-hover);
  transform: scale(1.15);
}

@media (prefers-reduced-motion: reduce) {
  .picker {
    animation: none;
  }

  .grid__sign {
    transition: none;
  }

  .grid__sign:hover {
    transform: none;
  }
}
</style>
