<script setup lang="ts">
import { REACTION_SET } from '~/utils/chatEmoji'

export interface MenuAction {
  key: string
  label: string
  danger?: boolean
}

/**
 * Что можно сделать с репликой — и чем на неё откликнуться.
 *
 * Открывается там, где нажали: долгим нажатием на телефоне, правой кнопкой или
 * многоточием на столе. Ряд знаков стоит над списком, потому что им пользуются
 * в разы чаще, чем всем остальным вместе, — и до него не надо тянуться сквозь
 * пункты.
 *
 * Положение считается от края экрана, а не от пузыря: у последних реплик — а
 * именно с ними чаще всего что-то делают — меню, раскрытое вниз, уезжало бы за
 * нижний край и не нажималось вовсе.
 */
const props = defineProps<{
  /** Где нажали — в координатах окна. */
  at: { x: number, y: number }
  actions: MenuAction[]
  /** Какой отклик уже стоит: он отмечен в ряду. */
  mine: string | null
  /** Можно ли откликаться: у системной отметки и уходящей реплики — нет. */
  canReact: boolean
}>()

const emit = defineEmits<{ pick: [key: string], react: [emoji: string], close: [] }>()

const panel = ref<HTMLElement | null>(null)

/** Куда поставить меню, чтобы оно целиком осталось на экране. */
const box = ref({ top: 0, left: 0 })

/** Раскрывается вверх или вниз — от этого зависит, откуда оно вырастает. */
const upwards = ref(false)

onMounted(async () => {
  await nextTick()

  const element = panel.value

  if (!element) {
    return
  }

  const size = element.getBoundingClientRect()
  const margin = 8

  const fitsBelow = props.at.y + size.height + margin < window.innerHeight

  upwards.value = !fitsBelow

  box.value = {
    top: fitsBelow ? props.at.y : Math.max(margin, props.at.y - size.height),
    left: Math.min(
      Math.max(margin, props.at.x - size.width / 2),
      window.innerWidth - size.width - margin,
    ),
  }
})
</script>

<template>
  <!-- Подложка ловит щелчок мимо и не пускает его в ленту: без неё нажатие
       «закрыть меню» заодно открывало бы то, по чему пришлось. -->
  <div class="veil" @click.stop="emit('close')" @contextmenu.prevent="emit('close')">
    <div
      ref="panel"
      class="menu"
      :class="{ 'menu--up': upwards }"
      :style="{ top: `${box.top}px`, left: `${box.left}px` }"
      @click.stop
    >
      <div v-if="canReact" class="quick">
        <button
          v-for="emoji in REACTION_SET"
          :key="emoji"
          type="button"
          class="quick__sign"
          :class="{ 'quick__sign--mine': mine === emoji }"
          :aria-label="`Откликнуться ${emoji}`"
          @click="emit('react', emoji)"
        >
          {{ emoji }}
        </button>
      </div>

      <ul class="list">
        <li v-for="action in actions" :key="action.key">
          <button
            type="button"
            :class="{ list__danger: action.danger }"
            @click="emit('pick', action.key)"
          >
            {{ action.label }}
          </button>
        </li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.veil {
  position: fixed;
  inset: 0;
  z-index: 60;
}

.menu {
  position: fixed;
  width: max-content;
  min-width: 11rem;
  max-width: min(18rem, calc(100vw - 1rem));
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface-raised);
  box-shadow: var(--shadow-lg);
  /* Вырастает из того места, где нажали: так видно, к чему меню относится. */
  transform-origin: top center;
  animation: grow 0.14s ease-out;
}

.menu--up {
  transform-origin: bottom center;
}

@keyframes grow {
  from { opacity: 0; transform: scale(0.92); }
  to { opacity: 1; transform: scale(1); }
}

/* Ряд знаков прокручивается вбок на узком экране: обрезать его нельзя — там
   стоит и тот знак, которым откликаются чаще всего. */
.quick {
  display: flex;
  gap: 0.1rem;
  overflow-x: auto;
  padding: 0.35rem;
  border-bottom: 1px solid var(--color-border);
  scrollbar-width: none;
}

.quick::-webkit-scrollbar {
  display: none;
}

.quick__sign {
  display: grid;
  flex-shrink: 0;
  place-items: center;
  width: 2.1rem;
  height: 2.1rem;
  padding: 0;
  border: none;
  border-radius: 50%;
  background: transparent;
  font-size: 1.2rem;
  line-height: 1;
  cursor: pointer;
  transition: transform 0.12s ease, background-color 0.15s ease;
}

.quick__sign:hover {
  background: var(--control-surface-hover);
  transform: scale(1.18);
}

.quick__sign--mine {
  background: var(--color-accent-soft);
}

.list {
  margin: 0;
  padding: 0.25rem;
  list-style: none;
}

.list button {
  display: block;
  width: 100%;
  padding: 0.5rem 0.6rem;
  border: none;
  border-radius: var(--radius-sm);
  background: transparent;
  color: inherit;
  font: inherit;
  font-size: 0.88rem;
  text-align: left;
  cursor: pointer;
}

.list button:hover {
  background: var(--control-surface-hover);
}

.list__danger {
  color: var(--color-danger);
}

@media (prefers-reduced-motion: reduce) {
  .menu {
    animation: none;
  }

  .quick__sign {
    transition: none;
  }

  .quick__sign:hover {
    transform: none;
  }
}
</style>
