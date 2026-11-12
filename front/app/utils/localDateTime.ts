/**
 * Поле «дата и время» и то, что ждёт сервер, — две разные записи.
 *
 * `input[type=datetime-local]` работает без пояса: в нём «26.10 в 11:00»,
 * и браузер понимает это как время на часах того, кто набирает. Сервер,
 * наоборот, хранит момент — со смещением. Перевод туда и обратно живёт здесь,
 * чтобы экраны не повторяли его каждый своим способом и не разъезжались на час.
 */

/** ISO сервера → значение поля, в поясе браузера. */
export function toLocalInput(value: string | null | undefined): string {
  if (!value) {
    return ''
  }

  const date = new Date(value)
  const pad = (part: number) => String(part).padStart(2, '0')

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

/** Обратно: набранное в поясе браузера → ISO со смещением, как ждёт сервер. */
export function fromLocalInput(value: string): string | null {
  return value === '' ? null : new Date(value).toISOString()
}
