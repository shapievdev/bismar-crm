import StarterKit from '@tiptap/starter-kit'
import Link from '@tiptap/extension-link'
import Image from '@tiptap/extension-image'
import Underline from '@tiptap/extension-underline'
import TextAlign from '@tiptap/extension-text-align'
import Placeholder from '@tiptap/extension-placeholder'
import { Table, TableCell, TableHeader, TableRow } from '@tiptap/extension-table'
import { attachmentIdAttribute } from '~/utils/editor/attachments'
import { BlockId } from '~/utils/editor/blockId'
import { HtmlBlock } from '~/utils/editor/htmlBlock'
import { VideoEmbed } from '~/utils/editor/videoEmbed'

/**
 * The one place the document schema is defined.
 *
 * Editor and reader share it, which is what makes the stored document safe:
 * only node types listed here can exist, so the schema is the allow-list and
 * nothing else can be smuggled into an article.
 */
export function useRichTextExtensions(options: { placeholder?: string } = {}) {
  return [
    StarterKit.configure({
      heading: { levels: [2, 3, 4] },
      // Both are configured below with our own options; StarterKit already
      // bundles them, and registering twice makes Tiptap warn about duplicates.
      link: false,
      underline: false,
    }),

    Underline,

    Link.configure({
      openOnClick: false,
      autolink: true,
      // Only these schemes survive; javascript: and data: are dropped, so a
      // pasted link cannot execute when a reader clicks it.
      protocols: ['http', 'https', 'mailto', 'tel'],
      HTMLAttributes: { rel: 'noopener noreferrer', target: '_blank' },
    }),

    // Carries which attachment the picture is, so its address can be resolved
    // fresh on every read instead of being frozen into the stored document.
    Image.extend({
      addAttributes() {
        return {
          ...this.parent?.(),
          attachmentId: attachmentIdAttribute(),
        }
      },
    }).configure({
      inline: false,
      HTMLAttributes: { loading: 'lazy' },
    }),

    TextAlign.configure({ types: ['heading', 'paragraph'] }),

    Table.configure({ resizable: true }),
    TableRow,
    TableHeader,
    TableCell,

    HtmlBlock,
    VideoEmbed,

    // Имена блоков, на которые ссылается таблица урока. Без этого редактор
    // выбросил бы их при первой правке, и ссылки на абзацы оборвались бы.
    BlockId,

    Placeholder.configure({
      placeholder: options.placeholder ?? 'Начните писать…',
    }),
  ]
}