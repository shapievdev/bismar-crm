/**
 * Адреса файла на Google Диске.
 *
 * Собираются из номера файла и его вида — присланному откуда бы то ни было
 * адресу в `src` рамки не место: так внутри нашей страницы показался бы чужой
 * сайт. Тот же приём, что у внешних видео (`utils/video.ts`), и то же правило,
 * по которому их собирает сервер для приложенных файлов — см.
 * `App\Support\Lms\GoogleDrive`. Двойник намеренный: вставленный в текст файл
 * рисуется в браузере, и спрашивать сервер об адресе на каждый абзац незачем.
 */

/** Номер файла у Google: длина за годы менялась, поэтому проверяется состав. */
const FILE_ID = /^[A-Za-z0-9_-]{10,200}$/

const DOCUMENT = 'application/vnd.google-apps.document'
const SPREADSHEET = 'application/vnd.google-apps.spreadsheet'
const PRESENTATION = 'application/vnd.google-apps.presentation'
const FOLDER = 'application/vnd.google-apps.folder'

export function isDriveFileId(id: string | null | undefined): id is string {
  return typeof id === 'string' && FILE_ID.test(id)
}

/** Адрес для рамки просмотра. Null, если номер не похож на номер файла. */
export function driveEmbedUrl(id: string | null | undefined, mimeType?: string | null): string | null {
  if (!isDriveFileId(id)) {
    return null
  }

  switch (mimeType) {
    case DOCUMENT: return `https://docs.google.com/document/d/${id}/preview`
    case SPREADSHEET: return `https://docs.google.com/spreadsheets/d/${id}/preview`
    case PRESENTATION: return `https://docs.google.com/presentation/d/${id}/embed`
    case FOLDER: return `https://drive.google.com/embeddedfolderview?id=${id}#grid`
    default: return `https://drive.google.com/file/d/${id}/preview`
  }
}

/** Сам файл на Диске: туда уходят за доступом и за полным окном. */
export function driveViewUrl(id: string | null | undefined, mimeType?: string | null): string | null {
  if (!isDriveFileId(id)) {
    return null
  }

  switch (mimeType) {
    case DOCUMENT: return `https://docs.google.com/document/d/${id}/edit`
    case SPREADSHEET: return `https://docs.google.com/spreadsheets/d/${id}/edit`
    case PRESENTATION: return `https://docs.google.com/presentation/d/${id}/edit`
    case FOLDER: return `https://drive.google.com/drive/folders/${id}`
    default: return `https://drive.google.com/file/d/${id}/view`
  }
}
