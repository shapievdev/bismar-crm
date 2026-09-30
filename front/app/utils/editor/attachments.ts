import type { JSONContent } from '@tiptap/core'
import type { LessonAttachment } from '~/types/lms'

/** What an upload gives back to the editor: which attachment, and where it is now. */
export interface UploadedMedia {
  id: number
  url: string
}

/** Node types whose media is a lesson attachment rather than an outside link. */
const MEDIA_NODES = new Set(['image', 'videoEmbed'])

/**
 * The shared definition of the attribute that ties a node to its attachment.
 *
 * Round-tripped through `data-attachment-id` so the link survives a document
 * that passes through HTML — a copy-paste between two articles, say.
 */
export function attachmentIdAttribute() {
  return {
    default: null as number | null,
    parseHTML: (element: HTMLElement): number | null => {
      const raw = element.getAttribute('data-attachment-id')

      return raw && Number.isFinite(Number(raw)) ? Number(raw) : null
    },
    renderHTML: (attributes: Record<string, unknown>): Record<string, string> =>
      typeof attributes.attachmentId === 'number'
        ? { 'data-attachment-id': String(attributes.attachmentId) }
        : {},
  }
}

/**
 * Fills in a current URL for every attachment the document points at.
 *
 * Signed URLs live about fifteen minutes, so one stored inside a document is
 * broken by the time anybody reads the article. The document therefore stores
 * only `attachmentId`, and the address is resolved on the way to the screen
 * from the attachment list the lesson already ships with every request.
 */
export function withResolvedMedia(
  document: JSONContent | null,
  attachments: LessonAttachment[],
): JSONContent | null {
  if (!document) {
    return null
  }

  const urlById = new Map(attachments.map(attachment => [attachment.id, attachment.url]))

  // For documents written before the id was stored: their stale URL still names
  // the object in the bucket, and the path survives the expired signature — so
  // it identifies the attachment even though the URL no longer opens.
  const idByPath = new Map(
    attachments
      .map(attachment => [objectPath(attachment.url), attachment.id] as const)
      .filter(([path]) => path !== null),
  )

  return mapNodes(document, (node) => {
    if (!isAttachmentMedia(node)) {
      return node
    }

    const id = typeof node.attrs?.attachmentId === 'number'
      ? node.attrs.attachmentId
      : idByPath.get(objectPath(String(node.attrs?.src ?? '')))

    const url = id === undefined ? undefined : urlById.get(id)

    // An attachment that was deleted resolves to nothing; the node is left as
    // it is rather than being silently dropped out of the author's document.
    if (id === undefined || url === undefined) {
      return node
    }

    return { ...node, attrs: { ...node.attrs, attachmentId: id, src: url } }
  })
}

/**
 * Файлы, которые перечисляют списком рядом со статьёй.
 *
 * Списком показывают приложенное, а не всё загруженное: картинка, вставленная в
 * текст, уже видна в тексте, и второй раз строкой «Без названия (10).png» она
 * не нужна.
 *
 * **Что из них что, знает сама статья**, а не догадка о файле. Прежде список
 * отбирался по паре «открывается в браузере» и «есть подпись»: картинка без
 * подписи считалась вставленной в текст, — и приложенный руками PNG не
 * показывался вовсе, хотя загружали его затем, чтобы его скачали. Причём
 * вставленные редактором картинки подпись как раз получают («Изображение в
 * версии»), то есть догадка ошибалась в обе стороны.
 *
 * Спрашивать надо у **статьи с подставленными адресами**: номер вложения в ней
 * проставлен у каждого узла, в том числе у написанных до того, как номера стали
 * храниться, — см. `withResolvedMedia`.
 *
 * Файл с Диска в списке всегда: в текст его не вставляют, его прикладывают.
 */
export function filesBesideArticle<T extends { id: number, source?: string }>(
  article: JSONContent | null,
  attachments: T[],
): T[] {
  const inline = mediaIdsIn(article)

  return attachments.filter(file => file.source === 'google_drive' || !inline.has(file.id))
}

/** Номера вложений, которые статья показывает у себя внутри. */
function mediaIdsIn(document: JSONContent | null): Set<number> {
  const found = new Set<number>()

  if (document) {
    mapNodes(document, (node) => {
      if (isAttachmentMedia(node) && typeof node.attrs?.attachmentId === 'number') {
        found.add(node.attrs.attachmentId)
      }

      return node
    })
  }

  return found
}

/**
 * Drops the resolved address again, just before the document is stored.
 *
 * Keeping it would write an address that expires in fifteen minutes into a
 * record meant to outlive it — the id is the durable half, and the only half
 * worth saving.
 */
export function withoutResolvedMedia(document: JSONContent | null): JSONContent | null {
  if (!document) {
    return null
  }

  return mapNodes(document, (node) => {
    if (!isAttachmentMedia(node) || typeof node.attrs?.attachmentId !== 'number') {
      return node
    }

    return { ...node, attrs: { ...node.attrs, src: '' } }
  })
}

/**
 * A YouTube or Vimeo embed owns its address and must keep it; only uploaded
 * files are resolved from the attachment list.
 */
function isAttachmentMedia(node: JSONContent): boolean {
  if (!node.type || !MEDIA_NODES.has(node.type)) {
    return false
  }

  return node.type !== 'videoEmbed' || node.attrs?.provider === 'file'
}

/** The object's key in the bucket, with the signature and host discarded. */
function objectPath(url: string): string | null {
  if (!url) {
    return null
  }

  try {
    return new URL(url).pathname || null
  }
  catch {
    return null
  }
}

function mapNodes(node: JSONContent, transform: (node: JSONContent) => JSONContent): JSONContent {
  const mapped = transform(node)

  if (!Array.isArray(mapped.content)) {
    return mapped
  }

  return {
    ...mapped,
    content: mapped.content.map(child => mapNodes(child, transform)),
  }
}