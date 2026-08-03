import { Node, mergeAttributes } from '@tiptap/core'
import { VueNodeViewRenderer } from '@tiptap/vue-3'
import HtmlBlockView from '~/components/editor/HtmlBlockView.vue'

declare module '@tiptap/core' {
  interface Commands<ReturnType> {
    htmlBlock: {
      setHtmlBlock: (html: string) => ReturnType
    }
  }
}

/**
 * A block of raw HTML embedded in an article.
 *
 * The markup is kept in an attribute rather than parsed into the document, so
 * it never becomes part of the page the editor renders. Both the editor and
 * the reader show it inside a sandboxed iframe — see HtmlBlockView.
 */
export const HtmlBlock = Node.create({
  name: 'htmlBlock',
  group: 'block',
  atom: true,
  draggable: true,

  addAttributes() {
    return {
      html: {
        default: '',
        parseHTML: element => element.getAttribute('data-html') ?? '',
        renderHTML: attributes => ({ 'data-html': attributes.html }),
      },
      height: {
        default: 320,
        parseHTML: element => Number(element.getAttribute('data-height')) || 320,
        renderHTML: attributes => ({ 'data-height': attributes.height }),
      },
    }
  },

  parseHTML() {
    return [{ tag: 'div[data-html-block]' }]
  },

  renderHTML({ HTMLAttributes }) {
    return ['div', mergeAttributes(HTMLAttributes, { 'data-html-block': '' })]
  },

  addNodeView() {
    return VueNodeViewRenderer(HtmlBlockView)
  },

  addCommands() {
    return {
      setHtmlBlock: (html: string) => ({ commands }) =>
        commands.insertContent({ type: this.name, attrs: { html } }),
    }
  },
})
