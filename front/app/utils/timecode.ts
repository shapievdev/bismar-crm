/**
 * Время в записи: человек пишет «12:35», сервер хранит секунды.
 *
 * Перевод живёт в одном месте, потому что обе стороны обязаны понимать под
 * «12:35» одно и то же — иначе ссылка на источник промахнётся мимо ответа, и
 * заметит это только читатель.
 */

/** Секунды из «мм:сс», «ч:мм:сс» или просто числа секунд. Иначе null. */
export function parseTimecode(value: string): number | null {
  const trimmed = value.trim()

  if (trimmed === '') {
    return null
  }

  const parts = trimmed.split(':')

  if (parts.length > 3 || parts.some(part => !/^\d+$/.test(part))) {
    return null
  }

  // Разряды читаются справа: последний — всегда секунды, что бы ни ввели.
  const seconds = parts
    .map(Number)
    .reduce((total, part) => total * 60 + part, 0)

  // Минуты и секунды за пределами шестидесяти — почти наверняка опечатка, а не
  // намеренное «90 секунд»: принять её значит увести ссылку не туда молча.
  const overflowed = parts.length > 1 && parts.slice(1).some(part => Number(part) > 59)

  return overflowed || seconds > 86400 ? null : seconds
}

/** Секунды как «мм:сс», а на записях длиннее часа — «ч:мм:сс». */
export function toTimecode(seconds: number): string {
  const hours = Math.floor(seconds / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)
  const rest = seconds % 60

  const padded = `${String(minutes).padStart(hours > 0 ? 2 : 1, '0')}:${String(rest).padStart(2, '0')}`

  return hours > 0 ? `${hours}:${padded}` : padded
}
