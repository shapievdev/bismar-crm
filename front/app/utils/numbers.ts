/**
 * Числа так, как их читают люди.
 *
 * Выручка этой компании измеряется сотнями миллионов, и «488872126.44» в
 * плитке нечитаемо в принципе: глаз считает разряды вместо того, чтобы взять
 * величину. Поэтому крупные суммы сокращаются до «488,9 млн», а полное
 * значение остаётся в подсказке — точность нужна, но не в заголовке.
 *
 * Разделитель разрядов — неразрывный пробел, как принято в русской типографике,
 * и он же не даёт числу переноситься посреди суммы.
 */

const MILLION = 1_000_000
const THOUSAND = 1_000

/** Полное число со всеми разрядами: для подсказок и таблиц. */
export function formatNumber(value: number, fractionDigits = 0): string {
  return new Intl.NumberFormat('ru-RU', {
    minimumFractionDigits: fractionDigits,
    maximumFractionDigits: fractionDigits,
  }).format(value)
}

/** Полная сумма с рублём. */
export function formatMoney(value: number, fractionDigits = 0): string {
  return `${formatNumber(value, fractionDigits)} ₽`
}

/**
 * Сокращённая сумма для плиток и осей: миллиарды, миллионы, тысячи.
 *
 * Знак сохраняется — возврат и убыток обязаны отличаться от прихода.
 */
export function formatCompactMoney(value: number): string {
  return `${formatCompact(value)} ₽`
}

export function formatCompact(value: number): string {
  const absolute = Math.abs(value)

  if (absolute >= MILLION * THOUSAND) {
    return `${formatNumber(value / (MILLION * THOUSAND), 2)} млрд`
  }

  if (absolute >= MILLION) {
    return `${formatNumber(value / MILLION, 1)} млн`
  }

  if (absolute >= THOUSAND * 10) {
    return `${formatNumber(value / THOUSAND, 0)} тыс`
  }

  return formatNumber(value, absolute < 100 && !Number.isInteger(value) ? 1 : 0)
}

/** Доля в процентах — со знаком, когда это изменение. */
export function formatPercent(value: number, withSign = false): string {
  const sign = withSign && value > 0 ? '+' : ''

  return `${sign}${formatNumber(value, Math.abs(value) < 10 ? 1 : 0)} %`
}

/**
 * Насколько текущее значение отличается от прошлого, в процентах.
 *
 * Возвращает null, когда сравнивать не с чем: рост «с нуля» не бывает
 * стократным, он просто не определён, и рисовать «+∞ %» честнее отказом.
 */
export function changeAgainst(current: number, previous: number): number | null {
  if (previous === 0) {
    return null
  }

  return Number((((current - previous) / Math.abs(previous)) * 100).toFixed(1))
}

/** Дата в человеческом виде: «6 августа 2026». */
export function formatDate(value: string): string {
  if (!value) {
    return ''
  }

  return new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' })
    .format(new Date(value))
}

/**
 * Подпись точки графика под шаг периода.
 *
 * День показывается как «6 авг», месяц — как «авг 2026»: на оси, где точек
 * несколько десятков, год у каждой съедает место и ничего не добавляет.
 */
export function formatBucket(value: string, granularity: 'day' | 'week' | 'month'): string {
  const date = new Date(value)

  if (granularity === 'month') {
    return new Intl.DateTimeFormat('ru-RU', { month: 'short', year: 'numeric' }).format(date)
  }

  return new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short' }).format(date)
}

/** «12 дней назад» — возраст выгрузки словами. */
export function formatAge(days: number): string {
  if (days <= 0) {
    return 'сегодня'
  }

  if (days === 1) {
    return 'вчера'
  }

  return `${formatNumber(days)} ${pluralise(days, 'день', 'дня', 'дней')} назад`
}