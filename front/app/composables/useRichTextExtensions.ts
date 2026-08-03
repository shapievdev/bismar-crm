import StarterKit from '@tiptap/starter-kit'
import Link from '@tiptap/extension-link'
import Image from '@tiptap/extension-image'
import Underline from '@tiptap/extension-underline'
import TextAlign from '@tiptap/extension-text-align'
import Placeholder from '@tiptap/extension-placeholder'
import { Table, TableCell, TableHeader, TableRow } from '@tiptap/extension-table'
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
      link: false,
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

    Image.configure({
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

    Placeholder.configure({
      placeholder: options.placeholder ?? 'Начните писать…',
    }),
  ]
}