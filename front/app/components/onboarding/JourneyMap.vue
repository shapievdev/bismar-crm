<script setup lang="ts">
import type { JourneyStage } from '~/types/onboarding'

/**
 * Карта маршрута: змейка из этапов, соединённых линией.
 *
 * Этапы идут слева направо и переваливают на строку ниже — путь виден целиком,
 * а не списком. Статусы несут значок и подпись, а не только цвет: дальтонику
 * карта читается так же. Пройденный участок линии закрашен, следующая цель
 * подписана «Сейчас здесь».
 *
 * Выбранный этап раскрывается карточкой снаружи (слот страницы) — карта только
 * показывает и выбирает. Узкому экрану змейка не нужна: ниже 60rem колонка
 * одна, и все связки становятся вертикальными (см. конец файла).
 */
const props = defineProps<{
  stages: JourneyStage[]
  selectedId: number | null
}>()

const emit = defineEmits<{
  select: [stageId: number]
}>()

/** Сколько узлов в строке змейки. Меняется вместе с шириной в CSS. */
const PER_ROW = 3

/** Ближайшая цель: первый этап, над которым можно работать. */
const targetId = computed(() => props.stages.find(
  stage => stage.status === 'available' || stage.status === 'in_progress' || stage.status === 'quiz_failed',
)?.id ?? null)

/**
 * Раскладка: колонка узла и куда уходит связка к следующему.
 *
 * Чётные строки идут вправо, нечётные — влево; последний в строке уводит линию
 * вниз. Связка принадлежит узлу, из которого выходит, поэтому закрашена она
 * тогда же, когда пройден он сам.
 */
const cells = computed(() => props.stages.map((stage, index) => {
  const row = Math.floor(index / PER_ROW)
  const place = index % PER_ROW
  const isLast = index === props.stages.length - 1

  return {
    stage,
    number: stage.day_offset ?? stage.position,
    row: row + 1,
    column: row % 2 === 0 ? place + 1 : PER_ROW - place,
    link: isLast ? null : (place === PER_ROW - 1 ? 'down' : (row % 2 === 0 ? 'right' : 'left')),
    passed: stage.status === 'completed',
  }
}))

/** Дата открытия словами. Пусто — дат в этом маршруте нет, этап ждёт очереди. */
function opensLabel(stage: JourneyStage): string | null {
  return stage.opens_at
    ? new Date(stage.opens_at).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' })
    : null
}
</script>

<template>
  <div class="card map-card">
    <ol class="map" role="list">
      <li
        v-for="cell in cells"
        :key="cell.stage.id"
        class="map__cell"
        :style="{ '--col': cell.column, '--row': cell.row }"
      >
        <!-- Связка к следующему этапу: закрашена, пока путь пройден. -->
        <span
          v-if="cell.link"
          class="map__link"
          :class="[`map__link--${cell.link}`, { 'map__link--passed': cell.passed }]"
          aria-hidden="true"
        />

        <button
          type="button"
          class="map__node"
          :class="{ 'map__node--selected': cell.stage.id === selectedId }"
          :aria-current="cell.stage.id === selectedId ? 'step' : undefined"
          @click="emit('select', cell.stage.id)"
        >
          <span
            class="map__ring"
            :class="{ 'map__ring--progress': cell.stage.status === 'in_progress' }"
            :style="{ '--progress': `${cell.stage.progress}%` }"
          >
            <span class="map__circle" :class="`map__circle--${cell.stage.status}`">
              {{ cell.number }}

              <span
                v-if="['completed', 'locked', 'quiz_failed'].includes(cell.stage.status)"
                class="map__badge"
                :class="`map__badge--${cell.stage.status}`"
                aria-hidden="true"
              >
                <!-- Галочка: пройден. -->
                <svg v-if="cell.stage.status === 'completed'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                  <path d="m4.5 12.75 6 6 9-13.5" />
                </svg>
                <!-- Замок: этап ещё не открылся. -->
                <svg v-else-if="cell.stage.status === 'locked'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                </svg>
                <!-- Стрелка по кругу: тест не сдан, нужна пересдача. -->
                <svg v-else-if="cell.stage.status === 'quiz_failed'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                  <path d="M16.023 9.348h4.992M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                </svg>
              </span>
            </span>
          </span>

          <span class="map__label">
            <span class="map__day">
              <template v-if="cell.stage.day_offset">День {{ cell.stage.day_offset }}</template>
              <template v-else>Этап {{ cell.stage.position }}</template>
            </span>
            <span class="map__title" :title="cell.stage.title">{{ cell.stage.title }}</span>
            <span class="map__status" :class="`map__status--${cell.stage.status}`">
              {{ cell.stage.status_label }}<template v-if="cell.stage.status === 'in_progress'"> — {{ cell.stage.progress }}%</template>
              <template v-if="cell.stage.status === 'locked' && opensLabel(cell.stage)"> · {{ opensLabel(cell.stage) }}</template>
              <template v-else-if="cell.stage.status === 'locked'"> · ждёт предыдущего</template>
            </span>
            <span v-if="cell.stage.id === targetId" class="map__here">Сейчас здесь</span>
          </span>
        </button>
      </li>
    </ol>
  </div>
</template>

<style scoped>
.map-card {
  padding: 1.75rem 1.5rem 1.5rem;
}

.map {
  /* Размер кружка — на всей карте: от него считаются и связки, и значки. */
  --node: 3.5rem;

  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 2.25rem 1.25rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

/*
 * Место узла названо числами в разметке, а правило — здесь: иначе сетка
 * раскладывает змейку сама и, встретив колонку левее предыдущей, уводит узел
 * на новую строку. Через переменные, а не стилем целиком, — чтобы узкий экран
 * ниже вернул одну колонку без !important.
 */
.map__cell {
  position: relative;
  grid-column: var(--col);
  grid-row: var(--row);
}

/*
 * Связки. Все отсчитываются от центра кружка: он у каждого узла на одной
 * высоте, потому что стоит первым и имеет свой размер. Соседний центр — это
 * «ширина клетки + зазор», отсюда calc со 100% и 1.25rem.
 */
.map__link {
  position: absolute;
  z-index: 0;
  border-radius: 999px;
  background: var(--color-border-strong);
  opacity: 0.55;
}

.map__link--passed {
  background: var(--color-success);
  opacity: 1;
}

.map__link--right,
.map__link--left {
  top: calc(var(--node) / 2 - 1px);
  width: calc(100% + 1.25rem - var(--node) - 0.5rem);
  height: 2px;
}

.map__link--right { left: calc(50% + var(--node) / 2 + 0.25rem); }
.map__link--left { right: calc(50% + var(--node) / 2 + 0.25rem); }

.map__link--down {
  left: calc(50% - 1px);
  top: calc(var(--node) + 0.25rem);
  height: calc(100% + 2.25rem - var(--node) - 0.25rem);
  width: 2px;
}

.map__node {
  position: relative;
  z-index: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.6rem;
  width: 100%;
  padding: 0 0 0.5rem;
  background: none;
  border: 0;
  color: inherit;
  font: inherit;
  text-align: center;
  cursor: pointer;
}

/* Кольцо вокруг кружка: доля пройденного у идущего этапа. */
.map__ring {
  display: inline-flex;
  padding: 3px;
  border-radius: 999px;
  background: transparent;
}

.map__ring--progress {
  background: conic-gradient(var(--color-accent) var(--progress), var(--color-border) 0);
}

.map__circle {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: var(--node);
  height: var(--node);
  border-radius: 999px;
  background: var(--color-surface-sunken);
  color: var(--color-text-muted);
  font-size: 1.15rem;
  font-weight: 700;
  transition: transform 0.15s ease;
}

.map__node:hover .map__circle {
  transform: translateY(-2px);
}

.map__circle--completed {
  background: var(--color-success);
  color: #fff;
}

.map__circle--available,
.map__circle--in_progress {
  background: var(--color-accent);
  color: var(--color-accent-text);
}

.map__circle--quiz_failed {
  background: var(--color-danger);
  color: #fff;
}

/*
 * Выбранный этап обведён тонким кольцом с просветом: кольцо вплотную к
 * залитому кружку сливается с ним в пятно, особенно у красного.
 */
.map__node--selected .map__circle {
  box-shadow:
    0 0 0 3px var(--color-surface),
    0 0 0 4px var(--color-border-strong);
}

.map__badge {
  position: absolute;
  top: -2px;
  right: -4px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.25rem;
  height: 1.25rem;
  border-radius: 999px;
  border: 2px solid var(--color-surface);
  background: var(--color-border-strong);
  color: #fff;
}

.map__badge svg {
  width: 0.7rem;
  height: 0.7rem;
}

.map__badge--completed { background: var(--color-success); }
.map__badge--quiz_failed { background: var(--color-danger); }

/*
 * Подпись узла. Ширина и высота у всех одинаковые — иначе соседи по строке
 * разъезжаются и ряд выглядит рваным; название длиннее двух строк
 * подрезается, полное видно в карточке этапа.
 *
 * Подложка под подписью — цвета карточки, то есть невидимая: ею подпись
 * закрывает связку, уходящую к строке ниже. Без неё линия шла бы прямо
 * по буквам.
 */
.map__label {
  position: relative;
  z-index: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.1rem;
  width: 100%;
  max-width: 11rem;
  min-height: 4.5rem;
  padding: 0.1rem 0.3rem;
  background: var(--color-surface);
  border-radius: var(--radius-sm);
  min-width: 0;
}

.map__day {
  font-size: 0.72rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-text-faint);
}

.map__title {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  overflow: hidden;
  font-size: 0.92rem;
  font-weight: 600;
  line-height: 1.25;
}

.map__status {
  font-size: 0.78rem;
  color: var(--color-text-faint);
}

.map__status--completed { color: var(--color-success); }
.map__status--quiz_failed { color: var(--color-danger); }

.map__here {
  margin-top: 0.3rem;
  padding: 0.2rem 0.6rem;
  border-radius: 999px;
  background: var(--color-accent);
  color: var(--color-accent-text);
  font-size: 0.72rem;
  font-weight: 600;
}

/*
 * Узкому экрану змейка не нужна: колонка одна, связки — только вниз.
 * Селекторы тут длиннее, чем наверху, — иначе правило колонки не перебить.
 */
@media (max-width: 60rem) {
  .map {
    grid-template-columns: minmax(0, 1fr);
    gap: 1.75rem;
  }

  .map .map__cell {
    grid-column: 1;
    grid-row: auto;
  }

  .map .map__link {
    top: calc(var(--node) + 0.25rem);
    left: calc(50% - 1px);
    right: auto;
    width: 2px;
    height: calc(100% + 1.75rem - var(--node) - 0.25rem);
  }
}
</style>
