import { Node, mergeAttributes } from '@tiptap/core'
import { VueNodeViewRenderer } from '@tiptap/vue-3'
import VideoEmbedView from '~/components/editor/VideoEmbedView.vue'
import { attachmentIdAttribute } from '~/utils/editor/attachments'

declare module '@tiptap/core' {
  interface Commands<ReturnType> {
    videoEmbed: {
      setVideoEmbed: (attrs: {
        src: string
        provider?: 'file' | 'embed'
        attachmentId?: number | null
      }) => ReturnType
    }
  }
}

/**
 * A video in the body of an article: either an uploaded file played with
 * <video>, or a YouTube/Vimeo embed.
 *
 * The distinction is stored rather than guessed at render time, so an uploaded
 * file can never be turned into an iframe source by a crafted URL.
 */
export const VideoEmbed = Node.create({
  name: 'videoEmbed',
  group: 'block',
  atom: true,
  draggable: true,

  addAttributes() {
    return {
      src: { default: '' },
      provider: { default: 'embed' },
      title: { default: null },
      // Set for uploaded files, null for a YouTube or Vimeo link, which owns
      // its address and needs nothing resolved.
      attachmentId: attachmentIdAttribute(),
    }
  },

  parseHTML() {
    return [{ tag: 'div[data-video-embed]' }]
  },

  renderHTML({ HTMLAttributes }) {
    return ['div', mergeAttributes(HTMLAttributes, { 'data-video-embed': '' })]
  },

  addNodeView() {
    return VueNodeViewRenderer(VideoEmbedView)
  },

  addCommands() {
    return {
      setVideoEmbed: attrs => ({ commands }) =>
        commands.insertContent({ type: this.name, attrs: { provider: 'embed', ...attrs } }),
    }
  },
})
