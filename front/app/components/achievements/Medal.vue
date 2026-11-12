<script setup lang="ts">
import type { AchievementBadge } from '~/types/achievements'

/**
 * Значок достижения — картинка как есть.
 *
 * Один на все экраны: профиль, карточка сотрудника, строка этапа в редакторе.
 * Разойдись они — один и тот же значок выглядел бы в трёх местах по-разному,
 * а узнают его именно по виду.
 *
 * **Ни рамки, ни подложки, ни обрезки.** Значки рисуют с прозрачным фоном и
 * произвольной формой — щит, лента, звезда, — и кружок вокруг срезал бы им
 * углы. Задана только клетка, в которую картинка вписывается целиком
 * (`contain`): ряд значков должен стоять ровно, даже когда один вытянут
 * вширь, а другой ввысь.
 *
 * Без картинки рисуется заглушка с первой буквой названия — вот у неё
 * подложка есть: пустое место иначе не отличить от не загрузившегося файла.
 */
const props = withDefaults(defineProps<{
  achievement: AchievementBadge
  size?: 'sm' | 'md' | 'lg'
  /** Приглушить: значок, который ещё не получен, — обещание, а не заслуга. */
  muted?: boolean
}>(), {
  size: 'md',
  muted: false,
})

const letter = computed(() => props.achievement.title.trim().charAt(0).toUpperCase() || '?')
</script>

<template>
  <span
    class="medal"
    :class="[`medal--${size}`, { 'medal--muted': muted }]"
    :title="achievement.title"
  >
    <img
      v-if="achievement.image_url"
      class="medal__image"
      :src="achievement.image_url"
      :alt="achievement.title"
      loading="lazy"
    >
    <span v-else class="medal__blank" aria-hidden="true">{{ letter }}</span>
  </span>
</template>

<style scoped>
.medal {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: none;
  line-height: 1;
}

.medal--sm {
  width: 1.6rem;
  height: 1.6rem;
  font-size: 0.7rem;
}

.medal--md {
  width: 3rem;
  height: 3rem;
  font-size: 1.1rem;
}

.medal--lg {
  width: 4.5rem;
  height: 4.5rem;
  font-size: 1.6rem;
}

/* Приглушение — непрозрачностью и обесцвечиванием разом: одна прозрачность
   на светлой подложке читается как «картинка не догрузилась». */
.medal--muted {
  opacity: 0.45;
  filter: grayscale(1);
}

/* contain, а не cover: у значка своя форма, и обрезать её по клетке значит
   отрезать ему край. */
.medal__image {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
}

.medal__blank {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  border-radius: var(--radius-sm);
  background: var(--control-surface);
  color: var(--color-text-faint);
  font-weight: 700;
}
</style>
