import type { JSONContent } from '@tiptap/core'

/** Место в тексте, каким его выбирает автор. */
export interface OutlineEntry {
  id: string
  /** Начало абзаца — идентификатор человеку ничего не говорит. */
  preview: string
}

/** Сколько знаков начала блока показывать в списке мест. */
const PREVIEW_CHARS = 70

/** Как называются блоки, у которых нет собственного текста. */
const WORDLESS: Record<string, string> = {
  image: 'Изображение',
  videoEmbed: 'Видео',
  htmlBlock: 'Блок HTML',
  table: 'Таблица',
  horizontalRule: 'Разделитель',
}

/**
 * Блоки статьи, на которые может сослаться строка таблицы.
 *
 * Только верхний уровень — тот же перечень, что именует сервер: ссылка ведёт
 * читателя к месту на экране, и прокрутка к таблице целиком полезнее прокрутки
 * к её ячейке.
 *
 * Блоки без имени пропускаются: имя присваивает сервер при сохранении, поэтому
 * только что набранный абзац сослаться на себя ещё не даёт — до сохранения
 * урока его попросту нечем назвать.
 */
export function blockOutline(document: JSONContent | null): OutlineEntry[] {
  if (!document || !Array.isArray(document.content)) {
    return []
  }

  const entries: OutlineEntry[] = []

  for (const block of document.content) {
    const id = block?.attrs?.blockId

    if (typeof id !== 'string' || id === '') {
      continue
    }

    entries.push({ id, preview: previewOf(block) })
  }

  return entries
}

function previewOf(block: JSONContent): string {
  const text = collectText(block).replace(/\s+/g, ' ').trim()

  if (text === '') {
    return WORDLESS[block.type ?? ''] ?? 'Блок'
  }

  const label = text.length > PREVIEW_CHARS ? `${text.slice(0, PREVIEW_CHARS).trimEnd()}…` : text

  // Заголовок стоит отличать от абзаца: по нему автор и ориентируется в длинной
  // статье, где десяток абзацев начинается похоже.
  return block.type === 'heading' ? `§ ${label}` : label
}

function collectText(node: JSONContent): string {
  if (typeof node.text === 'string') {
    return node.text
  }

  if (!Array.isArray(node.content)) {
    return ''
  }

  return node.content.map(collectText).join(' ')
}
