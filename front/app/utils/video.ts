/**
 * Ссылка на видео → то, чем её показать.
 *
 * Провайдеров пять: YouTube, Vimeo, **ВК Видео, RuTube и Google Диск**
 * (последние три заведены 2026-09-30 — до этого их ссылка оставалась просто
 * ссылкой). Плюс прямая ссылка на файл: её проигрываем сами, рамка тут не нужна.
 *
 * **Адрес рамки собирается заново из разобранного номера, а не пропускается
 * насквозь.** Это главное правило этого файла: так произвольная ссылка не может
 * оказаться источником iframe, что бы в неё ни вписали. Не узнали ссылку —
 * возвращаем null, и экран показывает её ссылкой, как показывал.
 */

/** Чем показывать: рамкой провайдера или своим проигрывателем. */
export interface ResolvedVideo {
  kind: 'embed' | 'file'
  src: string
}

/**
 * Разбирает ссылку и говорит, чем её показать.
 *
 * `startSeconds` перематывает запись к месту, на которое сослалась строка
 * таблицы урока или консультант. Обозначают его все по-разному, и дописываем мы
 * его только там, где знаем наверняка — у YouTube, Vimeo и своего файла.
 * Наугад дописанный параметр у остальных в лучшем случае игнорируется, в худшем
 * ломает адрес, а видео важнее перемотки.
 */
export function resolveVideo(url: string | null | undefined, startSeconds?: number | null): ResolvedVideo | null {
  const parsed = parse(url)

  if (parsed === null) {
    return null
  }

  const embed = toEmbed(parsed)

  if (embed !== null) {
    return { kind: 'embed', src: withStart(embed, startSeconds) }
  }

  const file = toFile(parsed)

  return file === null ? null : { kind: 'file', src: withFileStart(file, startSeconds) }
}

/**
 * Адрес для рамки — или null, если ссылку показывать рамкой нельзя.
 *
 * Отдельным именем, потому что спрашивают именно об этом: «встроится или нет».
 */
export function toEmbedUrl(url: string | null | undefined, startSeconds?: number | null): string | null {
  const resolved = resolveVideo(url, startSeconds)

  return resolved?.kind === 'embed' ? resolved.src : null
}

/** Прямая ссылка на файл, который проиграем сами, — или null. */
export function toVideoFileUrl(url: string | null | undefined): string | null {
  const resolved = resolveVideo(url)

  return resolved?.kind === 'file' ? resolved.src : null
}

function parse(url: string | null | undefined): URL | null {
  if (!url) {
    return null
  }

  try {
    const parsed = new URL(url)

    return parsed.protocol === 'https:' || parsed.protocol === 'http:' ? parsed : null
  }
  catch {
    return null
  }
}

function toEmbed(url: URL): string | null {
  const host = url.hostname.replace(/^(www|m)\./, '')

  return youTube(url, host)
    ?? vimeo(url, host)
    ?? vkVideo(url, host)
    ?? rutube(url, host)
    ?? googleDrive(url, host)
}

/* ---------- YouTube ---------- */

function youTube(url: URL, host: string): string | null {
  if (host === 'youtu.be') {
    return youTubeEmbed(url.pathname.slice(1))
  }

  if (host !== 'youtube.com' && host !== 'youtube-nocookie.com') {
    return null
  }

  if (url.pathname === '/watch') {
    return youTubeEmbed(url.searchParams.get('v') ?? '')
  }

  if (url.pathname.startsWith('/embed/')) {
    return youTubeEmbed(url.pathname.slice('/embed/'.length))
  }

  // Короткие ролики лежат по своему адресу, а встраиваются тем же проигрывателем.
  if (url.pathname.startsWith('/shorts/')) {
    return youTubeEmbed(url.pathname.slice('/shorts/'.length))
  }

  return null
}

function youTubeEmbed(id: string): string | null {
  const clean = id.split('/')[0] ?? ''

  return /^[\w-]{6,20}$/.test(clean) ? `https://www.youtube-nocookie.com/embed/${clean}` : null
}

/* ---------- Vimeo ---------- */

function vimeo(url: URL, host: string): string | null {
  if (host !== 'vimeo.com' && host !== 'player.vimeo.com') {
    return null
  }

  const id = url.pathname.split('/').filter(Boolean).find(part => /^\d+$/.test(part)) ?? ''

  return id === '' ? null : `https://player.vimeo.com/video/${id}`
}

/* ---------- ВК Видео ---------- */

/**
 * ВК: `vk.com/video-123_456`, новый домен `vkvideo.ru` и готовая ссылка для
 * встраивания (`video_ext.php`), которую даёт кнопка «Экспортировать».
 *
 * **Ключ `hash` переносим, если он есть.** У закрытого и «по ссылке» видео без
 * него рамка отвечает «видео недоступно» — а взять его, кроме как из ссылки,
 * неоткуда. Поэтому вставленная ссылка для встраивания работает всегда, а
 * обычная — у открытых видео.
 */
function vkVideo(url: URL, host: string): string | null {
  if (host !== 'vk.com' && host !== 'vkvideo.ru' && host !== 'vk.ru') {
    return null
  }

  if (url.pathname === '/video_ext.php') {
    return vkEmbed(
      url.searchParams.get('oid') ?? '',
      url.searchParams.get('id') ?? '',
      url.searchParams.get('hash'),
    )
  }

  // Ролик и клип адресуются одинаково: владелец и номер через подчёркивание.
  const match = /^\/(?:video|clip)(-?\d+)_(\d+)$/.exec(url.pathname);

  return match === null ? null : vkEmbed(match[1] ?? '', match[2] ?? '', null)
}

function vkEmbed(oid: string, id: string, hash: string | null): string | null {
  if (!/^-?\d{1,20}$/.test(oid) || !/^\d{1,20}$/.test(id)) {
    return null
  }

  const address = `https://vk.com/video_ext.php?oid=${oid}&id=${id}&hd=2`

  return hash !== null && /^[\w-]{1,64}$/.test(hash) ? `${address}&hash=${hash}` : address
}

/* ---------- RuTube ---------- */

/**
 * RuTube: `rutube.ru/video/<номер>/`, короткие ролики и готовый адрес рамки.
 *
 * У видео «по ссылке» в адресе стоит ключ `p` — переносим его по той же
 * причине, что и `hash` у ВК: без него рамка не откроется.
 */
function rutube(url: URL, host: string): string | null {
  if (host !== 'rutube.ru') {
    return null
  }

  const parts = url.pathname.split('/').filter(Boolean)
  const known = ['video', 'shorts', 'play']

  if (!known.includes(parts[0] ?? '')) {
    return null
  }

  // `/video/<id>/`, `/video/private/<id>/`, `/shorts/<id>/`, `/play/embed/<id>`.
  const id = parts.find(part => /^[a-f0-9]{32}$/i.test(part)) ?? ''

  if (id === '') {
    return null
  }

  const key = url.searchParams.get('p')

  return key !== null && /^[\w-]{1,64}$/.test(key)
    ? `https://rutube.ru/play/embed/${id}?p=${key}`
    : `https://rutube.ru/play/embed/${id}`
}

/* ---------- Google Диск ---------- */

/**
 * Диск: ссылка «Поделиться» ведёт на `/file/d/<id>/view`, а рамке нужен
 * `/preview`. Видео при этом остаётся на Диске и правами распоряжается он —
 * закрытый файл рамка не покажет и тому, кто открыл страницу.
 */
function googleDrive(url: URL, host: string): string | null {
  if (host !== 'drive.google.com') {
    return null
  }

  const fromPath = /^\/file\/d\/([\w-]{10,})/.exec(url.pathname)?.[1]
  const fromQuery = url.pathname === '/open' ? url.searchParams.get('id') : null
  const id = fromPath ?? fromQuery ?? ''

  return /^[\w-]{10,}$/.test(id) ? `https://drive.google.com/file/d/${id}/preview` : null
}

/* ---------- Прямая ссылка на файл ---------- */

/** Расширения, которые браузер играет сам. */
const PLAYABLE = ['.mp4', '.webm', '.ogv', '.ogg', '.mov', '.m4v']

/**
 * Ссылка на сам файл — её проигрываем своим проигрывателем.
 *
 * Рамка тут не нужна и вредна: у файла нет страницы провайдера, и iframe показал
 * бы его голым, без перемотки и громкости.
 */
function toFile(url: URL): string | null {
  const path = url.pathname.toLowerCase()

  return PLAYABLE.some(extension => path.endsWith(extension)) ? url.toString() : null
}

/* ---------- Перемотка ---------- */

function withStart(embed: string, startSeconds?: number | null): string {
  const seconds = normalise(startSeconds)

  if (seconds === null) {
    return embed
  }

  // YouTube ждёт ?start=, Vimeo — #t=Ns. Остальным не дописываем ничего: см.
  // докблок resolveVideo.
  if (embed.includes('youtube-nocookie.com')) {
    return `${embed}?start=${seconds}`
  }

  return embed.includes('player.vimeo.com') ? `${embed}#t=${seconds}s` : embed
}

/** Свой проигрыватель перематывается фрагментом адреса — как и у Vimeo. */
function withFileStart(file: string, startSeconds?: number | null): string {
  const seconds = normalise(startSeconds)

  return seconds === null ? file : `${file}#t=${seconds}`
}

function normalise(startSeconds?: number | null): number | null {
  return startSeconds && startSeconds > 0 ? Math.floor(startSeconds) : null
}
