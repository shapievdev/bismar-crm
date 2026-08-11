/**
 * State for one upload at a time: how far it has got, and how to stop it.
 *
 * Wraps the call rather than being driven by it, so a component cannot forget
 * to clear the state on failure — `track` always releases it.
 */
export function useUploadProgress() {
  const isUploading = ref(false)
  const fileName = ref('')
  const percent = ref(0)
  const bytesSent = ref(0)
  const bytesTotal = ref(0)

  let controller: AbortController | null = null

  /**
   * The bytes are all out and the server is still storing them — for S3 that
   * is a wait of its own, and it is why the bar goes indeterminate instead of
   * parking at 100%.
   */
  const isStoring = computed(() => isUploading.value && percent.value >= 100)

  const label = computed(() => isStoring.value
    ? 'Сохраняем на сервере…'
    : `${Math.round(percent.value)} %`)

  /** "12,4 из 340 МБ", or empty once the transfer itself is over. */
  const transferred = computed(() => isStoring.value
    ? ''
    : `${formatBytes(bytesSent.value)} из ${formatBytes(bytesTotal.value)}`)

  /**
   * Runs an upload with progress reporting attached.
   *
   * The file is only read for its name and size — `run` decides which endpoint
   * it goes to and what comes back.
   */
  async function track<T>(file: File, run: (options: UploadOptions) => Promise<T>): Promise<T> {
    controller = new AbortController()

    fileName.value = file.name
    percent.value = 0
    bytesSent.value = 0
    bytesTotal.value = file.size
    isUploading.value = true

    try {
      return await run({
        signal: controller.signal,
        onProgress: (progress) => {
          percent.value = progress.percent
          bytesSent.value = progress.bytesSent
          bytesTotal.value = progress.bytesTotal
        },
      })
    }
    finally {
      isUploading.value = false
      controller = null
    }
  }

  /** Stops the transfer. `track` then rejects with UploadAbortedError. */
  function cancel(): void {
    controller?.abort()
  }

  return { isUploading, isStoring, fileName, percent, label, transferred, track, cancel }
}