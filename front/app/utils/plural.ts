/**
 * Picks the Russian plural form for a count.
 *
 * Russian has three: one (1 урок), few (2–4 урока) and many (5+ уроков), with
 * the teens all taking the many form.
 */
export function pluralise(count: number, one: string, few: string, many: string): string {
  const absolute = Math.abs(count) % 100
  const lastDigit = absolute % 10

  if (absolute > 10 && absolute < 20) {
    return many
  }

  if (lastDigit > 1 && lastDigit < 5) {
    return few
  }

  return lastDigit === 1 ? one : many
}