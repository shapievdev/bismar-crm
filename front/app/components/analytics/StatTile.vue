<script setup lang="ts">
import { changeAgainst, formatCompactMoney, formatNumber, formatPercent } from '~/utils/numbers'

/**
 * Одна цифра с тем, как она изменилась.
 *
 * Плитка, а не столбик из одного столбца: у одиночного значения нет ни ряда,
 * ни шкалы, и рисовать под него график — это украшать число рамкой.
 *
 * Рост не всегда хорош: у возвратов и просрочки «+18%» — плохая новость.
 * Поэтому направление задаётся отдельно от знака, а не выводится из него.
 */
const props = withDefaults(defineProps<{
  label: string
  value: number
  previous?: number
  format?: 'money' | 'number' | 'percent' | 'decimal'
  /** Что означает рост: хорошо (выручка) или плохо (возвраты, просрочка). */
  growth?: 'good' | 'bad' | 'neutral'
  hint?: string
  /** Точное значение в подсказке — когда в плитке оно сокращено. */
  precise?: string
  /**
   * Число, за которым стоит невыполненная работа: непроверенные аттестации,
   * назначенное, к чему не приступали.
   *
   * Красится, только когда оно не ноль: ноль здесь — хорошая новость, и
   * подсвечивать в ней нечего. Отличается от `growth` тем, что говорит не об
   * изменении, а о самой величине: «ждут проверки трое» плохо само по себе,
   * независимо от того, было их вчера пять или один.
   */
  attention?: boolean
  /**
   * Считать не из чего.
   *
   * Показывает прочерк вместо числа. Нужен там, где ноль — не ответ: «средний
   * срок работы ушедших: 0 месяцев» читается как «уходят сразу», хотя на деле
   * за период не ушёл никто, и считать было некого.
   */
  unknown?: boolean
  /**
   * Ширина в колонках той же сетки, в которой лежат панели с графиками.
   *
   * Плитка живёт среди них, а не в отдельной полосе сверху: сводное число —
   * такой же элемент дашборда, как график, и собственная сетка над общей давала
   * бы второй шаг и второй ритм на одном экране.
   */
  span?: number
  /**
   * Куда ведёт цифра, за которой стоит не список людей, а раздел.
   *
   * «Курсов 3» отвечает каталогом, а не таблицей: разворачивать под плиткой то,
   * что уже есть целой страницей, значит заводить второй, худший каталог.
   */
  to?: string
  /**
   * Открывается ли за цифрой список тех, из кого она сложилась.
   *
   * Нажимается сама плитка, а не кнопка под ней: цифра и есть вопрос «кто это»,
   * и отвечать на него отдельным рядом кнопок значит просить прицелиться дважды.
   *
   * Список открывается окном поверх страницы (решение пользователя 2026-09-20).
   * Прежде он разворачивался под сеткой плиток, и ответ появлялся далеко от
   * вопроса: нажал цифру в верхнем ряду — таблица выехала под нижним, и, чтобы
   * её увидеть, приходилось прокручивать страницу, уводя саму цифру с экрана.
   */
  expandable?: boolean
}>(), {
  format: 'number',
  growth: 'good',
  span: 2,
})

const emit = defineEmits<{ open: [] }>()

/**
 * Чем плитка окажется в разметке.
 *
 * Ссылка ссылкой, а раскрывающая — кнопкой: и то и другое должно открываться
 * с клавиатуры и читаться скринридером тем, чем является. Неподвижная остаётся
 * `div`: кнопка, которая ничего не делает, обещает нажатие.
 */
const NuxtLink = resolveComponent('NuxtLink')

const as = computed(() => {
  if (props.to) {
    return NuxtLink
  }

  return props.expandable ? 'button' : 'div'
})

const pressable = computed(() => Boolean(props.to) || props.expandable === true)

const wide = computed(() => Math.min(12, Math.max(1, Math.round(props.span))))
const medium = computed(() => Math.min(6, Math.max(2, Math.ceil(wide.value / 2))))

const formatted = computed(() => {
  if (props.format === 'money') {
    return formatCompactMoney(props.value)
  }

  if (props.format === 'percent') {
    return formatPercent(props.value)
  }

  // Величины вроде «позиций в чеке» целыми не бывают: 2.93 и 3 — разные факты.
  if (props.format === 'decimal') {
    return formatNumber(props.value, 2)
  }

  return formatNumber(props.value)
})

const change = computed(() =>
  props.previous === undefined ? null : changeAgainst(props.value, props.previous),
)

/**
 * Цвет стрелки. Нейтральная метрика не красится вовсе: зелёная стрелка у
 * «числа складов» сообщала бы одобрение там, где его нет.
 */
const tone = computed(() => {
  const delta = change.value

  if (delta === null || delta === 0 || props.growth === 'neutral') {
    return 'flat'
  }

  const isGood = props.growth === 'good' ? delta > 0 : delta < 0

  return isGood ? 'good' : 'bad'
})
</script>

<template>
  <!--
    Строки внутри — `span`, а не `p`: плитка бывает кнопкой, а абзац внутри
    кнопки — недопустимая разметка, и браузер разбирает её по-своему.
  -->
  <component
    :is="as"
    class="tile"
    :class="{ 'tile--pressable': pressable }"
    :to="to"
    :type="!to && expandable ? 'button' : undefined"
    :aria-haspopup="!to && expandable ? 'dialog' : undefined"
    :title="precise"
    :style="{ '--span-wide': wide, '--span-medium': medium }"
    @click="!to && expandable ? emit('open') : undefined"
  >
    <span class="tile__label">
      <span class="tile__text">{{ label }}</span>

      <!-- Знак того, что плитка отвечает: стрелка у ссылки — она уводит на
           другую страницу, уголок у списка — он открывается здесь же, окном. -->
      <span v-if="pressable" class="tile__sign" aria-hidden="true">
        {{ to ? '→' : '›' }}
      </span>
    </span>

    <span
      class="tile__value"
      :class="{
        'tile__value--attention': attention && value > 0 && !unknown,
        'tile__value--unknown': unknown,
      }"
    >
      {{ unknown ? '—' : formatted }}
    </span>

    <span v-if="change !== null" class="tile__change" :class="`tile__change--${tone}`">
      <span aria-hidden="true">{{ change > 0 ? '↑' : change < 0 ? '↓' : '→' }}</span>
      {{ formatPercent(Math.abs(change)) }}
      <span class="tile__against">к прошлому периоду</span>
    </span>

    <span v-else-if="hint" class="tile__hint">
      {{ hint }}
    </span>
  </component>
</template>

<style scoped>
.tile {
  display: flex;
  flex-direction: column;
  /* Подпись сверху, число под ней, изменение прижато к низу: в ряду плиток
     глаз идёт по числам, и они обязаны стоять на одной высоте. */
  justify-content: center;
  gap: 0.35rem;
  padding: 1.1rem 1.25rem;
  background: var(--color-surface-raised);
  border-radius: var(--radius);
  box-shadow: var(--shadow-sm);
  min-width: 0;

  grid-column: span var(--span-wide, 2);
  grid-row: span 1;
}

@media (max-width: 68rem) {
  .tile {
    grid-column: span var(--span-medium, 2);
  }
}

/* Телефон: плитки парами. Числа коротки, и по одной в строке они растянули бы
   страницу до того, что до первого графика пришлось бы прокручивать экран. */
@media (max-width: 40rem) {
  .tile {
    grid-column: span 1;
  }
}

/*
 * Нажимаемая плитка.
 *
 * Отзывается тенью и подъёмом, а не рамкой: рамка у одной плитки в ряду
 * ломает ритм сетки даже под курсором. Курсор и знак в углу говорят, что она
 * отвечает, до всякого наведения — по одной тени об этом не догадаться.
 */
.tile--pressable {
  width: 100%;
  border: 0;
  font: inherit;
  color: inherit;
  /* Кнопке браузер центрирует и текст, и содержимое: без этого плитка,
     ставшая кнопкой, встала бы по центру, а соседняя — по левому краю. */
  align-items: stretch;
  text-align: left;
  text-decoration: none;
  cursor: pointer;
  transition: box-shadow 0.15s ease, transform 0.15s ease;
}

.tile--pressable:hover {
  box-shadow: var(--shadow-md);
  transform: translateY(-1px);
}

@media (prefers-reduced-motion: reduce) {
  .tile--pressable {
    transition: none;
  }

  .tile--pressable:hover {
    transform: none;
  }
}

.tile__label {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0;
  font-size: 0.78rem;
  color: var(--color-text-muted);
}

/* Подпись не переносится на две строки в узкой плитке — обрезается. */
.tile__text {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* Знак прижат к правому краю и не сжимается: он короче подписи, и отдавать
   ему место при обрезке значит обрезать её раньше времени. */
.tile__sign {
  margin-left: auto;
  flex: none;
  font-size: 0.85rem;
  line-height: 1;
  color: var(--color-text-faint);
}

.tile__value {
  display: block;
  margin: 0;
  font-size: 1.55rem;
  font-weight: 600;
  letter-spacing: -0.02em;
  line-height: 1.15;
  /* Цифры одной ширины: иначе соседние плитки пляшут по горизонтали. */
  font-variant-numeric: tabular-nums;
}

/* Не «плохо», а «есть чем заняться»: предупреждающий цвет, не тревожный. */
.tile__value--attention {
  color: var(--color-warning);
}

/* Прочерк — не цифра, и вес у него другой: он не должен спорить с соседями. */
.tile__value--unknown {
  color: var(--color-text-faint);
}

.tile__change,
.tile__hint {
  margin: 0;
  font-size: 0.78rem;
  display: flex;
  align-items: baseline;
  gap: 0.3rem;
  flex-wrap: wrap;
  /*
   * «Среднесписочной» длиннее половины телефонного экрана, и перенести его
   * между словами нельзя — слово одно. Без этого оно вылезает за край плитки
   * поверх соседней.
   *
   * Сначала пробуем перенос по слогам — страница объявлена русской, и браузер
   * умеет расставить дефис сам. Разрыв посреди слова остаётся запасным путём
   * на случай, если словарь переносов не подошёл.
   */
  hyphens: auto;
  overflow-wrap: break-word;
}

.tile__hint {
  color: var(--color-text-faint);
}

.tile__change--good {
  color: var(--color-success);
}

.tile__change--bad {
  color: var(--color-danger);
}

.tile__change--flat {
  color: var(--color-text-faint);
}

.tile__against {
  color: var(--color-text-faint);
}

@media (max-width: 40rem) {
  .tile__value {
    font-size: 1.35rem;
  }

  .tile__against {
    display: none;
  }

  /*
   * Подпись переносится, а не обрезается.
   *
   * В полную ширину многоточие бережёт ряд от разъезда, но в половине
   * телефонного экрана оно съедает саму подпись: «Численность на конец»
   * превращается в «Численность…», и плитка перестаёт сообщать, что за число
   * на ней стоит.
   */
  .tile__text {
    white-space: normal;
    line-height: 1.25;
  }
}
</style>