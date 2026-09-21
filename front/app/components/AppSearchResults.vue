<script setup lang="ts">
import type { SearchHit, SearchSection } from '~/types/search'

/**
 * Находки поиска по платформе — один список на две рамки.
 *
 * На широком экране он висит подсказкой под полем в шапке, на телефоне занимает
 * весь экран. Разметка у них одна: два списка разошлись бы в первый же день,
 * когда у находки появится третья строка или у раздела — счётчик.
 *
 * Своей выдачи компонент не знает: что нашлось, кто из находок подсвечен и куда
 * ведёт нажатие, решает поиск в шапке (AppSearch). Здесь только показ.
 */
defineProps<{
  sections: SearchSection[]
  /** Место в общем списке находок, на котором стоят стрелками. */
  activeIndex: number
  isSearching: boolean
  /** Ищут ли вообще: короче двух букв поиск молчит, и говорить ему нечего. */
  isAsking: boolean
}>()

const emit = defineEmits<{
  /** Выбрали находку или «показать все» — адрес один и тот же по смыслу. */
  pick: [url: string]
  /** Навели мышью: подсветка одна на мышь и на клавиатуру. */
  hover: [hit: SearchHit]
}>()

/**
 * Находки одним списком — тем же, по которому ходят стрелками.
 *
 * Считается здесь, а не приходит готовым: порядок задан разделами, и второй
 * список, собранный снаружи, мог бы разойтись с тем, что человек видит.
 */
function flatIndexOf(sections: SearchSection[], hit: SearchHit): number {
  return sections.flatMap(section => section.items).indexOf(hit)
}
</script>

<template>
  <div class="hits" role="listbox">
    <p v-if="isSearching && sections.length === 0" class="hits__note faint">
      Ищем…
    </p>

    <p v-else-if="isAsking && sections.length === 0" class="hits__note faint">
      Ничего не нашлось
    </p>

    <section v-for="section in sections" :key="section.kind" class="hits__section">
      <header class="hits__head">
        <h2 class="hits__label">{{ section.label }}</h2>

        <!-- Каталог раздела с тем же словом: в подсказке пять находок, а дальше
             смотрят там, где умеют листать и отбирать по категории. -->
        <button
          v-if="section.more_url"
          type="button"
          class="hits__more"
          @click="emit('pick', section.more_url)"
        >
          Показать все
        </button>
      </header>

      <button
        v-for="hit in section.items"
        :key="hit.url"
        type="button"
        class="hits__hit"
        :class="{ 'hits__hit--active': flatIndexOf(sections, hit) === activeIndex }"
        role="option"
        :aria-selected="flatIndexOf(sections, hit) === activeIndex"
        @click="emit('pick', hit.url)"
        @mousemove="emit('hover', hit)"
      >
        <span class="hits__title">{{ hit.title }}</span>
        <span v-if="hit.subtitle" class="hits__subtitle faint">{{ hit.subtitle }}</span>
      </button>
    </section>
  </div>
</template>

<style scoped>
.hits__note {
  margin: 0;
  padding: 0.85rem 0.7rem;
  font-size: 0.88rem;
}

.hits__section + .hits__section {
  margin-top: 0.3rem;
  padding-top: 0.3rem;
  border-top: 1px solid var(--color-border);
}

.hits__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.45rem 0.7rem 0.25rem;
}

/*
 * Раздел назван словом, а не значком: значков понадобилось бы шесть, и три из
 * них — «документ», «справочник», «новость» — рисуются одним и тем же листком
 * бумаги. Слово короче любого такого объяснения.
 */
.hits__label {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.72rem;
  font-weight: 500;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.hits__more {
  flex-shrink: 0;
  padding: 0;
  border: 0;
  background: none;
  color: var(--color-text-muted);
  font: inherit;
  font-size: 0.78rem;
  cursor: pointer;
}

.hits__more:hover {
  color: var(--color-text);
}

.hits__hit {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  width: 100%;
  padding: 0.5rem 0.7rem;
  border: 0;
  border-radius: var(--radius-sm);
  background: none;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

/*
 * Подсветка одна на мышь и на клавиатуру: две разные — «под курсором» и «на
 * которой стоим» — спорили бы друг с другом, и человек видел бы две выбранные
 * строки сразу.
 */
.hits__hit--active {
  background: var(--control-surface);
}

.hits__title {
  font-size: 0.92rem;
  /* Название в одну строку: подсказку просматривают, а не читают. */
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.hits__subtitle {
  font-size: 0.78rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* На своём экране место есть: длинное название переносится, а не обрезается. */
@media (max-width: 60rem) {
  .hits--screen .hits__title,
  .hits--screen .hits__subtitle {
    white-space: normal;
  }
}
</style>
