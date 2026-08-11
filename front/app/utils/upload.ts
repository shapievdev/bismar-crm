/** How much of the request body has left the browser. */
export interface UploadProgress {
  /** 0–100. */
  percent: number
  bytesSent: number
  bytesTotal: number
}

export interface UploadOptions {
  /**
   * Called as the bytes go out.
   *
   * It reaches 100 when the last byte is sent, which is well before the server
   * has finished storing the file — callers usually switch to an indeterminate
   * state at that point rather than claiming the upload is done.
   */
  onProgress?: (progress: UploadProgress) => void

  /** Aborts the transfer. The promise then rejects with UploadAbortedError. */
  signal?: AbortSignal
}

/** The body Laravel sends back when it refuses a request. */
interface ErrorBody {
  message?: string
  errors?: Record<string, string[]>
}

/**
 * A response the server refused.
 *
 * Deliberately shaped like ofetch's error — `status` plus a parsed `data` — so
 * callers that already read `error.data.errors.video[0]` keep working whether
 * the request went through `$api` or `$upload`.
 */
export class UploadError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly data: ErrorBody | null,
  ) {
    super(message)
    this.name = 'UploadError'
  }
}

/** The caller cancelled. Not a failure, so it carries nothing to display. */
export class UploadAbortedError extends Error {
  constructor() {
    super('Загрузка отменена')
    this.name = 'UploadAbortedError'
  }
}

interface SendUploadOptions extends UploadOptions {
  url: string
  body: FormData
  xsrfToken: string | null
}

/**
 * POSTs a multipart body and reports how far it has got.
 *
 * XMLHttpRequest rather than fetch, for one reason: fetch cannot say how much
 * of a request body it has sent. Without that a 500 MB video sits behind a
 * spinner with nothing to report, which is the whole problem this solves.
 */
export function sendUpload<T>({
  url,
  body,
  xsrfToken,
  onProgress,
  signal,
}: SendUploadOptions): Promise<T> {
  return new Promise<T>((resolve, reject) => {
    if (signal?.aborted) {
      reject(new UploadAbortedError())

      return
    }

    const xhr = new XMLHttpRequest()

    xhr.open('POST', url)
    // Carries the Sanctum session cookie across the origin boundary, the same
    // thing `credentials: 'include'` does for $fetch.
    xhr.withCredentials = true
    xhr.setRequestHeader('Accept', 'application/json')

    if (xsrfToken) {
      xhr.setRequestHeader('X-XSRF-TOKEN', xsrfToken)
    }

    xhr.upload.addEventListener('progress', (event) => {
      // Without a computable length there is no fraction to report; the caller
      // keeps whatever it was showing rather than jumping to a made-up number.
      if (event.lengthComputable) {
        onProgress?.({
          percent: (event.loaded / event.total) * 100,
          bytesSent: event.loaded,
          bytesTotal: event.total,
        })
      }
    })

    xhr.addEventListener('load', () => {
      const payload = parseJson(xhr.responseText)

      if (xhr.status >= 200 && xhr.status < 300) {
        resolve(payload as T)

        return
      }

      reject(new UploadError(
        payload?.message ?? `Запрос отклонён (${xhr.status})`,
        xhr.status,
        payload,
      ))
    })

    // A transport failure: no response at all, so there is no status to report.
    xhr.addEventListener('error', () => {
      reject(new UploadError('Не удалось связаться с сервером', 0, null))
    })

    xhr.addEventListener('abort', () => reject(new UploadAbortedError()))

    signal?.addEventListener('abort', () => xhr.abort(), { once: true })

    xhr.send(body)
  })
}

function parseJson(text: string): ErrorBody | null {
  if (!text) {
    return null
  }

  try {
    return JSON.parse(text) as ErrorBody
  }
  catch {
    // A proxy or PHP itself can answer with HTML — a 413 from an upload larger
    // than post_max_size is the usual one. Nothing to read, so say nothing.
    return null
  }
}