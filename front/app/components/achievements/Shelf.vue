<script setup lang="ts">
import type { AwardedAchievement } from '~/types/achievements'
import { formatDate } from '~/utils/numbers'

/**
 * Полка достижений в профиле: что человек заработал и за что.
 *
 * Свежее слева — порядок задаёт сервер. Причина стоит подписью: через полгода
 * «Знаток кассы» сам по себе не напомнит, откуда он взялся.
 *
 * Пустая полка показывается тоже — коротким объяснением, а не пропадает:
 * сотрудник должен видеть, что значки в системе есть и как они достаются.
 */
withDefaults(defineProps<{
  achievements: AwardedAchievement[]
  /** Чей профиль: своему пишем «вы», чужому — «сотрудник». */
  own?: boolean
}>(), {
  own: false,
})
</script>

<template>
  <div class="shelf">
    <ul v-if="achievements.length" class="shelf__grid">
      <li v-for="item in achievements" :key="item.id" class="shelf__item">
        <AchievementsMedal :achievement="item" size="lg" />

        <span class="shelf__title">{{ item.title }}</span>

        <span v-if="item.reason" class="shelf__reason">{{ item.reason }}</span>

        <span v-if="item.awarded_at" class="shelf__date">{{ formatDate(item.awarded_at) }}</span>
      </li>
    </ul>

    <p v-else class="shelf__empty">
      {{ own
        ? 'Пока ни одного. Достижения приходят сами, когда вы закрываете этапы маршрута и дела внутри них.'
        : 'Пока ни одного: сотрудник ещё не закрыл ни одного шага, за который дают значок.' }}
    </p>
  </div>
</template>

<style scoped>
.shelf {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.shelf__grid {
  display: grid;
  /* Ширина колонки задана, а не поделена поровну: значки читаются рядом, и
     на широком экране растянутые на треть страницы они выглядели бы плакатом. */
  grid-template-columns: repeat(auto-fill, minmax(8.5rem, 1fr));
  gap: 1rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.shelf__item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.35rem;
  padding: 1rem 0.6rem;
  text-align: center;
  background: var(--control-surface);
  border-radius: var(--radius);
}

.shelf__title {
  font-weight: 600;
  font-size: 0.9rem;
  line-height: 1.25;
}

.shelf__reason {
  font-size: 0.78rem;
  color: var(--color-text-muted);
  line-height: 1.3;
}

.shelf__date {
  font-size: 0.75rem;
  color: var(--color-text-faint);
}

.shelf__empty {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.9rem;
}
</style>
