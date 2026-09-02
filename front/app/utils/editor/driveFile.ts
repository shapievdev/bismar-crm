import { Node, mergeAttributes } from '@tiptap/core'
import { VueNodeViewRenderer } from '@tiptap/vue-3'
import DriveFileView from '~/components/editor/DriveFileView.vue'

declare module '@tiptap/core' {
  interface Commands<ReturnType> {
    driveFile: {
      setDriveFile: (attrs: {
        fileId: string
        mimeType?: string | null
        name?: string | null
      }) => ReturnType
    }
  }
}

/**
 * Файл с Google Диска в теле статьи.
 *
 * Хранится номер файла, а не адрес: адрес собирается при показе (`utils/drive.ts`),
 * и подставить в рамку что-то помимо Google нельзя даже правкой сохранённого
 * документа. По той же причине здесь хранится и вид файла — у Документа,
 * Таблицы и Презентации свой просмотр.
 *
 * Копии у нас не остаётся: поправят файл на Диске — изменится и то, что видно в
 * уроке. Затем его туда и вставляют.
 */
export const DriveFile = Node.create({
  name: 'driveFile',
  group: 'block',
  atom: true,
  draggable: true,

  addAttributes() {
    return {
      fileId: {
        default: null as string | null,
        parseHTML: (element: HTMLElement): string | null => element.getAttribute('data-drive-file'),
        renderHTML: (attributes: Record<string, unknown>): Record<string, string> =>
          typeof attributes.fileId === 'string' ? { 'data-drive-file': attributes.fileId } : {},
      },
      mimeType: {
        default: null as string | null,
        parseHTML: (element: HTMLElement): string | null => element.getAttribute('data-drive-mime'),
        renderHTML: (attributes: Record<string, unknown>): Record<string, string> =>
          typeof attributes.mimeType === 'string' ? { 'data-drive-mime': attributes.mimeType } : {},
      },
      // Имя нужно только подписью: адрес от него не зависит.
      name: {
        default: null as string | null,
        parseHTML: (element: HTMLElement): string | null => element.getAttribute('data-drive-name'),
        renderHTML: (attributes: Record<string, unknown>): Record<string, string> =>
          typeof attributes.name === 'string' ? { 'data-drive-name': attributes.name } : {},
      },
    }
  },

  parseHTML() {
    return [{ tag: 'div[data-drive-file]' }]
  },

  renderHTML({ HTMLAttributes }) {
    return ['div', mergeAttributes(HTMLAttributes)]
  },

  addNodeView() {
    return VueNodeViewRenderer(DriveFileView)
  },

  addCommands() {
    return {
      setDriveFile: attrs => ({ commands }) => commands.insertContent({ type: this.name, attrs }),
    }
  },
})
