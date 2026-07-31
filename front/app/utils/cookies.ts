/**
 * Reads a cookie from the browser.
 *
 * Laravel URL-encodes the XSRF-TOKEN cookie value, so it is decoded here before
 * being echoed back in the X-XSRF-TOKEN header.
 */
export function readCookie(name: string): string | null {
  if (import.meta.server) {
    return null
  }

  const match = document.cookie.match(new RegExp(`(?:^|;\\s*)${name}=([^;]*)`))

  return match?.[1] ? decodeURIComponent(match[1]) : null
}