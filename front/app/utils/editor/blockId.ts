import { Extension } from '@tiptap/core'

/**
 * Node types a table row may point at.
 *
 * Только верхний уровень статьи: ссылка ведёт читателя к месту на экране, и
 * прокрутка к таблице целиком полезнее прокрутки к её ячейке. Список обязан
 * совпадать с тем, что именует сервер, — см. App\Support\Lms\BlockIdentifier.
 */
const BLOCKS = [
  'paragraph',
  'heading',
  'blockquote',
  'codeBlock',
  'bulletList',
  'orderedList',
  'table',
  'image',
  'htmlBlock',
  'videoEmbed',
  'horizontalRule',
]

/**
 * Carries the durable name of a block through the editor.
 *
 * Имя присваивает сервер при сохранении — клиенту его не выдумать, — но без
 * этого расширения редактор выбрасывал бы незнакомый атрибут при первой же
 * правке, и все ссылки на абзацы урока обрывались бы молча.
 *
 * Ходит через `data-block-id`, чтобы пережить и путь документа через HTML:
 * копирование абзаца из одной статьи в другую.
 */
export const BlockId = Extension.create({
  name: 'blockId',

  addGlobalAttributes() {
    return [{
      types: BLOCKS,
      attributes: {
        blockId: {
          default: null as string | null,
          keepOnSplit: false,
          parseHTML: (element: HTMLElement): string | null => element.getAttribute('data-block-id'),
          renderHTML: (attributes: Record<string, unknown>): Record<string, string> =>
            typeof attributes.blockId === 'string' && attributes.blockId !== ''
              ? { 'data-block-id': attributes.blockId }
              : {},
        },
      },
    }]
  },
})
