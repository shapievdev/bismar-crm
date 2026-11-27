import type { JSONContent } from '@tiptap/core'

/** Old request middleware turned whitespace-only text nodes into null. */
export function normalizeDocument(document: JSONContent | null): JSONContent | null {
  if (!document) return null
  if (document.type === 'text' && (document.text == null || document.text === '')) return null
  if (!Array.isArray(document.content)) return document

  const content = document.content.flatMap(child => {
    const normalized = normalizeDocument(child)
    return normalized ? [normalized] : []
  })
  return content.length === document.content.length && content.every((child, index) => child === document.content![index])
    ? document
    : { ...document, content }
}

/** ProseMirror requires at least one block, including for a new empty article. */
export function documentForEditor(document: JSONContent | null): JSONContent {
  const normalized = normalizeDocument(document)
  if (!normalized || (normalized.type === 'doc' && (!normalized.content || normalized.content.length === 0))) {
    return { type: 'doc', content: [{ type: 'paragraph' }] }
  }
  return normalized
}

/** Legacy lessons keep plain text until the editor explicitly saves their block document. */
export function documentForEditing(content: JSONContent | null | undefined, fallbackText?: string | null): JSONContent | null {
  // A stored block document (even an intentionally empty one) always wins.
  if (content != null) return normalizeDocument(content)

  const paragraphs = (fallbackText ?? '').replace(/\r\n?/g, '\n')
    .split(/\n{2,}/).map(paragraph => paragraph.trim()).filter(Boolean)

  if (!paragraphs.length) return null

  return {
    type: 'doc',
    content: paragraphs.map(paragraph => ({
      type: 'paragraph',
      content: paragraph.split('\n').flatMap((line, index) => [
        ...(index > 0 ? [{ type: 'hardBreak' }] : []),
        ...(line ? [{ type: 'text', text: line }] : []),
      ]),
    })),
  }
}

/**
 * Одинаковы ли два документа статьи.
 *
 * Сравнение нарочно нестрогое, и обе поблажки — про то, как статья доезжает до
 * базы и обратно. На этом сравнении держится и решение, чью статью оставить при
 * перечитывании записи, и надпись «есть несохранённые правки»: если оно
 * расходится всегда, редактор никогда не заберёт с сервера имена блоков, а
 * надпись загорится сразу после сохранения и не погаснет.
 *
 * **Порядок ключей не в счёт.** Статья лежит в `jsonb`, а Postgres
 * раскладывает ключи объекта по-своему — посимвольное сравнение расходилось бы
 * на любой статье.
 *
 * **Пустая строка и `null` — одно и то же.** Тело запроса проходит через
 * `ConvertEmptyStringsToNull`, и отправленный пустой атрибут возвращается
 * нулём: снятый адрес вложения (`src`), разметка пустого HTML-блока. Для
 * документа это одна и та же пустота, и различать её значило бы считать
 * статью изменённой ровно с того мгновения, как её сохранили.
 */
export function isSameDocument(one: JSONContent | null, other: JSONContent | null): boolean {
  return canonical(one) === canonical(other)
}

function canonical(value: JSONContent | null): string {
  return JSON.stringify(withSortedKeys(normalizeDocument(value)))
}

/** То же значение, но ключи каждого объекта — по алфавиту, а пустота — одна. */
function withSortedKeys(value: unknown): unknown {
  if (Array.isArray(value)) {
    return value.map(withSortedKeys)
  }

  if (value === '') {
    return null
  }

  if (value === null || typeof value !== 'object') {
    return value
  }

  return Object.fromEntries(
    Object.entries(value as Record<string, unknown>)
      .sort(([one], [other]) => one.localeCompare(other))
      .map(([key, nested]) => [key, withSortedKeys(nested)]),
  )
}
