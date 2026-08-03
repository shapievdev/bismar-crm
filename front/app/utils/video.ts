/**
 * Turns a YouTube or Vimeo watch URL into its embed URL.
 *
 * Only these two hosts are recognised and the embed URL is rebuilt from the
 * extracted id rather than passed through, so an arbitrary URL can never end up
 * as an iframe source. Anything else returns null and is shown as a plain link.
 */
export function toEmbedUrl(url: string | null | undefined): string | null {
  if (!url) {
    return null
  }

  let parsed: URL

  try {
    parsed = new URL(url)
  }
  catch {
    return null
  }

  if (parsed.protocol !== 'https:' && parsed.protocol !== 'http:') {
    return null
  }

  const host = parsed.hostname.replace(/^www\./, '')

  if (host === 'youtu.be') {
    return embedYouTube(parsed.pathname.slice(1))
  }

  if (host === 'youtube.com' || host === 'm.youtube.com') {
    if (parsed.pathname === '/watch') {
      return embedYouTube(parsed.searchParams.get('v') ?? '')
    }

    if (parsed.pathname.startsWith('/embed/')) {
      return embedYouTube(parsed.pathname.slice('/embed/'.length))
    }
  }

  if (host === 'vimeo.com') {
    const id = parsed.pathname.split('/').filter(Boolean)[0] ?? ''

    return /^\d+$/.test(id) ? `https://player.vimeo.com/video/${id}` : null
  }

  return null
}

function embedYouTube(id: string): string | null {
  return /^[\w-]{6,20}$/.test(id) ? `https://www.youtube-nocookie.com/embed/${id}` : null
}