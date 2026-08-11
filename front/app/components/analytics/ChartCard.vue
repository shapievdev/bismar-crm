<script setup lang="ts">
/**
 * Панель дашборда: заголовок, пояснение и содержимое.
 *
 * Пояснение — не украшение. Почти каждая цифра здесь имеет оговорку («время
 * чека не выгружается», «срез от марта»), и место для неё должно быть рядом с
 * графиком, а не в документации, которую никто не откроет.
 */
const props = withDefaults(defineProps<{
  title: string
  hint?: string
  /**
   * Ширина в колонках сетки из двенадцати и высота в её рядах.
   *
   * Размер объявляет сама панель, потому что он относится к содержимому, а не к
   * месту в вёрстке: график на девяносто точек нечитаем в четверти ширины, а
   * кольцо из трёх долей в полной ширине — это круг посреди пустоты. Заданный
   * страницей, этот размер пришлось бы держать в голове дважды — в разметке и в
   * стилях под каждый экран.
   */
  span?: number
  rows?: number
  /**
   * Содержимое прокручивается внутри панели, а не растягивает её.
   *
   * Нужно там, где длина списка не в счёт: рейтинг на пятнадцать строк вдвое
   * выше графика, стоящего рядом, и без этого он растягивает весь ряд сетки —
   * соседняя панель вытягивается за ним и её кольцо повисает посреди пустоты.
   * Панели, стоящей в ряду одной, прокрутка не нужна: растягивать ей нечего.
   */
  scroll?: boolean
}>(), {
  span: 4,
  rows: 1,
})

/*
 * На узких экранах колонок меньше, чем просит панель, и просьбу приходится
 * пересчитывать: половина от двенадцати, но не уже трети — панель в одну
 * колонку из шести перестаёт быть панелью. Считается здесь, а не медиазапросом
 * с готовыми классами: ширин восемь, и классов под них было бы восемь.
 */
const wide = computed(() => Math.min(12, Math.max(1, Math.round(props.span))))
const medium = computed(() => Math.min(6, Math.max(2, Math.ceil(wide.value / 2))))

/** Высота занятых рядов вместе с зазорами между ними. */
const ceiling = computed(() => {
  const rows = Math.max(1, props.rows)

  return `calc(${rows} * var(--bento-row, 10.5rem) + ${rows - 1} * var(--bento-gap, 0.9rem))`
})
</script>

<template>
  <section
    class="card"
    :style="{
      '--span-wide': wide,
      '--span-medium': medium,
      '--rows': Math.max(1, rows),
      ...(scroll ? { '--card-ceiling': ceiling } : {}),
    }"
  >
    <header class="card__head">
      <div>
        <h2 class="card__title">
          {{ title }}
        </h2>
        <p v-if="hint" class="card__hint">
          {{ hint }}
        </p>
      </div>

      <slot name="actions" />
    </header>

    <div class="card__body" :class="{ 'card__body--scroll': scroll }">
      <slot />
    </div>
  </section>
</template>

<style scoped>
.card {
  display: flex;
  flex-direction: column;
  gap: 1.1rem;
  padding: 1.35rem 1.5rem 1.5rem;
  background: var(--color-surface-raised);
  border-radius: var(--radius);
  box-shadow: var(--shadow-sm);
  min-width: 0;
  /* Ряды сетки одной высоты — панель обязана занять свой целиком, иначе
     нижние края соседей разойдутся. */
  min-height: 0;

  grid-column: span var(--span-wide, 4);
  grid-row: span var(--rows, 1);

  /*
   * Панель с прокруткой обязана знать свой потолок в пикселях: `overflow`
   * молчит, пока высота не ограничена, а ряд сетки растёт под содержимое —
   * список просто вытягивал бы и ряд, и соседа. Потолок — ровно те ряды, что
   * панель заняла, вместе с зазорами между ними.
   */
  max-height: var(--card-ceiling, none);

  /* Внутри поднятой карточки контролы красятся тоном ниже — иначе они
     сливаются с ней. */
  --control-surface: var(--color-surface);
  --control-surface-hover: var(--color-surface-sunken);
}

@media (max-width: 68rem) {
  .card {
    grid-column: span var(--span-medium, 3);
  }
}

/*
 * Телефон: любая панель во всю ширину. График в половине телефонного экрана —
 * это не половина графика, а его отсутствие. Высота отпускается: столбцы и
 * рейтинги занимают ровно столько, сколько им нужно.
 */
@media (max-width: 40rem) {
  .card {
    grid-column: 1 / -1;
    grid-row: auto;
    padding: 1.1rem 1.15rem 1.25rem;
    /* Ряда, который нужно было бы держать, здесь нет — потолок снимается
       вместе с ним. */
    max-height: none;
  }
}

.card__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
}

.card__title {
  margin: 0;
  font-size: 1rem;
  font-weight: 600;
  letter-spacing: -0.01em;
}

.card__hint {
  margin: 0.3rem 0 0;
  font-size: 0.8rem;
  line-height: 1.45;
  color: var(--color-text-muted);
  max-width: 52ch;
}

/* Содержимое забирает то, что осталось от шапки: график должен доставать до
   низа своей панели, а не висеть в её верхней трети. */
.card__body {
  min-width: 0;
  min-height: 0;
  flex: 1;
  display: flex;
  flex-direction: column;
  justify-content: center;
}

/* Прокручиваемое содержимое прижато к верху: список читают сверху вниз, и
   центрировать его значит начинать чтение с середины. */
.card__body--scroll {
  overflow-y: auto;
  justify-content: flex-start;
}

/* Телефон: панели идут одна под другой, растягивать друг друга им нечем, и
   вложенная прокрутка внутри страничной только мешает. */
@media (max-width: 40rem) {
  .card__body--scroll {
    overflow-y: visible;
  }
}
</style>