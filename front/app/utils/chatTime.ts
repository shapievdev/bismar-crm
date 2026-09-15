/**
 * Время в переписке.
 *
 * Разговор читают не по календарю: вчерашнее называют «вчера», позавчерашнее —
 * днём недели, и только прошлогоднее — числом с годом. Точная дата в ленте
 * сообщает меньше, чем отвечает на вопрос «давно ли это было».
 */

const DAY = 24 * 60 * 60 * 1000

export function chatTime(iso: string | null | undefined): string {
  if (!iso) {
    return ''
  }

  return new Date(iso).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
}

/** Отбивка над первым сообщением дня. */
export function chatDay(iso: string | null | undefined): string {
  if (!iso) {
    return ''
  }

  const date = new Date(iso)
  const days = daysApart(date)

  if (days === 0) {
    return 'Сегодня'
  }

  if (days === 1) {
    return 'Вчера'
  }

  // Внутри недели день недели говорит больше числа: «в среду» вспоминается, «11
  // сентября» — нет.
  if (days < 7) {
    return capitalise(date.toLocaleDateString('ru-RU', { weekday: 'long' }))
  }

  return date.toLocaleDateString('ru-RU', {
    day: 'numeric',
    month: 'long',
    ...(date.getFullYear() === new Date().getFullYear() ? {} : { year: 'numeric' }),
  })
}

/**
 * Подпись времени в строчке списка переписок.
 *
 * Не время, а «когда»: сегодняшнее называют часами, вчерашнее — словом, всё
 * остальное — числом. Часы у прошлогоднего разговора ничего не значат.
 */
export function chatStamp(iso: string | null | undefined): string {
  if (!iso) {
    return ''
  }

  const date = new Date(iso)
  const days = daysApart(date)

  if (days === 0) {
    return chatTime(iso)
  }

  if (days === 1) {
    return 'вчера'
  }

  if (days < 7) {
    return date.toLocaleDateString('ru-RU', { weekday: 'short' })
  }

  return date.toLocaleDateString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    ...(date.getFullYear() === new Date().getFullYear() ? {} : { year: '2-digit' }),
  })
}

/** «0:53», «12:07», «1:04:30» — как на плеере. */
export function clockOf(seconds: number): string {
  const total = Math.max(0, Math.round(seconds))
  const parts = [Math.floor(total / 60) % 60, total % 60]

  if (total >= 3600) {
    parts.unshift(Math.floor(total / 3600))
  }

  return parts
    .map((part, at) => (at === 0 ? String(part) : String(part).padStart(2, '0')))
    .join(':')
}

/**
 * Сколько целых суток назад это было — по календарю, а не по часам.
 *
 * Разница в часах врёт на границе суток: сказанное в половине первого ночи было
 * «два часа назад», но называть его «вчера» уже неправильно — это сегодня.
 */
function daysApart(date: Date): number {
  const midnight = (one: Date): number => new Date(one.getFullYear(), one.getMonth(), one.getDate()).getTime()

  return Math.round((midnight(new Date()) - midnight(date)) / DAY)
}

function capitalise(text: string): string {
  return text.charAt(0).toUpperCase() + text.slice(1)
}
