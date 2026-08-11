/**
 * A file size a person can read at a glance.
 *
 * Megabytes once there is at least one, kilobytes below that — bytes are never
 * shown, because nothing in the interface is small enough for them to matter.
 */
export function formatBytes(bytes: number | null | undefined): string {
  if (bytes === null || bytes === undefined) {
    return ''
  }

  if (bytes === 0) {
    return '0 КБ'
  }

  const megabytes = bytes / 1024 / 1024

  return megabytes >= 1
    ? `${megabytes.toFixed(1)} МБ`
    // Anything under a kilobyte still reads as one: "0 КБ" for a real file
    // looks like a bug.
    : `${Math.max(1, Math.round(bytes / 1024))} КБ`
}