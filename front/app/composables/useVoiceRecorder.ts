import type { VoiceNumbers } from '~/types/chat'
import { VOICE_BARS, VOICE_MAX_SECONDS } from '~/utils/voice'

/**
 * Запись голосового сообщения.
 *
 * Волна рисуется прямо во время записи, и это не украшение: без неё человек не
 * видит, слышно ли его вообще, и узнаёт о выключенном микрофоне, только отправив
 * тишину. Те же столбики уезжают на сервер вместе с записью — считать их там
 * значило бы распаковывать звук средствами PHP.
 *
 * Формат выбирает браузер: Chrome и Firefox пишут webm с opus, Safari — mp4.
 * Навязывать один значило бы не записывать вовсе там, где он не поддержан.
 */

/** Что предпочитаем, в порядке предпочтения. */
const FORMATS = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg']

export interface Recording {
  file: File
  numbers: VoiceNumbers
}

export function useVoiceRecorder() {
  /** Идёт ли запись прямо сейчас. */
  const isRecording = ref(false)

  /** Сколько записано — в миллисекундах, для таймера над кнопкой. */
  const elapsed = ref(0)

  /** Живая волна: столбики нарастают слева направо по мере записи. */
  const levels = ref<number[]>([])

  /** Чем запись не задалась: отказали в микрофоне, нет устройства. */
  const failure = ref<string | null>(null)

  let recorder: MediaRecorder | null = null
  let stream: MediaStream | null = null
  let audio: AudioContext | null = null
  let meter: AnalyserNode | null = null
  let chunks: Blob[] = []
  let ticking = 0
  let startedAt = 0

  /**
   * Просит микрофон и начинает писать.
   *
   * @returns удалось ли начать: отказавшему в микрофоне показывают, почему
   *          кнопка ничего не сделала, а не молчат.
   */
  async function start(): Promise<boolean> {
    if (isRecording.value) {
      return true
    }

    failure.value = null
    chunks = []
    levels.value = []
    elapsed.value = 0

    if (!import.meta.client || typeof MediaRecorder === 'undefined' || !navigator.mediaDevices) {
      failure.value = 'Этот браузер не умеет записывать голос.'

      return false
    }

    try {
      stream = await navigator.mediaDevices.getUserMedia({
        audio: {
          // Запись голоса, а не музыки: эхо и шум убираем на входе — так
          // разборчивее и легче.
          echoCancellation: true,
          noiseSuppression: true,
          autoGainControl: true,
        },
      })
    }
    catch {
      failure.value = 'Микрофон недоступен — разрешите доступ в настройках браузера.'

      return false
    }

    recorder = new MediaRecorder(stream, { mimeType: pickFormat() })
    recorder.ondataavailable = (event) => {
      if (event.data.size > 0) {
        chunks.push(event.data)
      }
    }

    listenLevels(stream)

    startedAt = Date.now()
    recorder.start()
    isRecording.value = true

    ticking = window.setInterval(() => {
      elapsed.value = Date.now() - startedAt

      // Длиннее пяти минут сервер всё равно не примет, да и не реплика это уже.
      if (elapsed.value >= VOICE_MAX_SECONDS * 1000) {
        void stop()
      }
    }, 100)

    return true
  }

  /**
   * Останавливает запись и отдаёт её.
   *
   * @returns null, если записывать было нечего, — нажали и сразу отпустили.
   */
  async function stop(): Promise<Recording | null> {
    if (!recorder || !isRecording.value) {
      return null
    }

    const current = recorder
    const duration = Date.now() - startedAt

    const blob = await new Promise<Blob>((resolve) => {
      current.onstop = () => resolve(new Blob(chunks, { type: current.mimeType }))
      current.stop()
    })

    const bars = squeeze(levels.value)

    teardown()

    // Меньше полусекунды — это не сообщение, а случайное касание кнопки.
    if (duration < 500 || blob.size === 0) {
      return null
    }

    return {
      file: new File([blob], `voice.${extensionOf(current.mimeType)}`, { type: blob.type }),
      numbers: { duration_ms: duration, waveform: bars },
    }
  }

  /** Бросает запись: ничего не отдаёт и ничего не оставляет. */
  function cancel(): void {
    if (recorder && isRecording.value) {
      recorder.onstop = null
      recorder.stop()
    }

    chunks = []
    teardown()
  }

  /**
   * Слушает громкость и копит столбики.
   *
   * Берётся среднее по кадру, а не пиковое: пиковое дёргается от каждого
   * щелчка и рисует частокол, в котором не видно речи.
   */
  function listenLevels(source: MediaStream): void {
    try {
      audio = new AudioContext()
      meter = audio.createAnalyser()
      meter.fftSize = 1024

      audio.createMediaStreamSource(source).connect(meter)

      const frame = new Uint8Array(meter.frequencyBinCount)

      const read = (): void => {
        if (!meter || !isRecording.value) {
          return
        }

        meter.getByteTimeDomainData(frame)

        let sum = 0

        for (const point of frame) {
          // 128 — тишина: значения идут вокруг середины байта.
          sum += Math.abs(point - 128)
        }

        const loudness = Math.min(100, Math.round((sum / frame.length) * 2.8))

        levels.value = [...levels.value, loudness]

        requestAnimationFrame(read)
      }

      requestAnimationFrame(read)
    }
    catch {
      // Без измерителя запись всё равно идёт — просто волна останется ровной.
      meter = null
    }
  }

  /**
   * Сжимает накопленное к числу столбиков, которое рисуют.
   *
   * За минуту записи кадров набегают тысячи, а в пузырь их помещается полсотни:
   * сжимаем средним по окну, чтобы громкие места остались громкими.
   */
  function squeeze(raw: number[]): number[] {
    if (raw.length === 0) {
      return []
    }

    if (raw.length <= VOICE_BARS) {
      return raw
    }

    const window = raw.length / VOICE_BARS

    return Array.from({ length: VOICE_BARS }, (_, at) => {
      const slice = raw.slice(Math.floor(at * window), Math.floor((at + 1) * window))
      const sum = slice.reduce((total, one) => total + one, 0)

      return slice.length > 0 ? Math.round(sum / slice.length) : 0
    })
  }

  function teardown(): void {
    window.clearInterval(ticking)
    ticking = 0

    isRecording.value = false

    stream?.getTracks().forEach(track => track.stop())
    stream = null

    void audio?.close()
    audio = null
    meter = null
    recorder = null
  }

  function pickFormat(): string {
    return FORMATS.find(format => MediaRecorder.isTypeSupported(format)) ?? ''
  }

  function extensionOf(mime: string): string {
    if (mime.includes('mp4')) {
      return 'm4a'
    }

    return mime.includes('ogg') ? 'ogg' : 'webm'
  }

  // Уйти со страницы посреди записи — значит оставить микрофон включённым.
  onScopeDispose(() => cancel())

  return { isRecording, elapsed, levels, failure, start, stop, cancel }
}
