/**
 * Окно выбора файла на Google Диске.
 *
 * Файл не загружается к нам: инструкции компании годами живут на Диске, и
 * вторая копия здесь означала бы две правды. Отсюда уходит только номер файла —
 * адрес просмотра по нему собирает сервер (см. App\Support\Lms\GoogleDrive).
 *
 * Доступ выдаёт сам сотрудник, входя в свой Google, и выдаёт его только тем
 * файлам, которые сам выбрал: `drive.file` — самое узкое разрешение, какое
 * бывает у выбора файла. Читать весь Диск приложение не просит и не может.
 *
 * Скрипты Google грузятся при первом нажатии, а не при загрузке страницы:
 * прикрепление файла — редкое действие, и платить за него запросом к чужому
 * домену на каждом открытии урока незачем.
 *
 * Ключи приезжают из настроек приложения — их заводит администратор на
 * `/settings/integrations`, и до перезапуска сервера дело больше не доходит.
 */
import type { GoogleSettings } from '~/composables/useIntegrationsApi'

/** Файл, выбранный на Диске, — ровно в том виде, в каком его ждёт сервер. */
export interface DriveFile {
  external_id: string
  name: string
  mime_type: string | null
}

const GIS_SCRIPT = 'https://accounts.google.com/gsi/client'
const API_SCRIPT = 'https://apis.google.com/js/api.js'

/** Только выбранные файлы, и ничего больше. */
const SCOPE = 'https://www.googleapis.com/auth/drive.file'

/*
 * Ровно то, чем мы пользуемся из двух библиотек Google. Полных описаний типов у
 * них нет, а выписывать их целиком незачем: неописанное всё равно не вызвать.
 */
interface TokenResponse { access_token?: string, error?: string }
interface TokenClient { requestAccessToken: (options?: { prompt?: string }) => void }
interface PickedDocument { id: string, name?: string, mimeType?: string }
interface PickerResponse { action: string, docs?: PickedDocument[] }

interface DocsViewApi {
  setIncludeFolders: (value: boolean) => DocsViewApi
  setSelectFolderEnabled: (value: boolean) => DocsViewApi
  setEnableDrives: (value: boolean) => DocsViewApi
  setOwnedByMe: (value: boolean) => DocsViewApi
}

interface PickerBuilderApi {
  addView: (view: DocsViewApi) => PickerBuilderApi
  setOAuthToken: (token: string) => PickerBuilderApi
  setDeveloperKey: (key: string) => PickerBuilderApi
  setAppId: (projectNumber: string) => PickerBuilderApi
  setLocale: (locale: string) => PickerBuilderApi
  setTitle: (title: string) => PickerBuilderApi
  enableFeature: (feature: string) => PickerBuilderApi
  setCallback: (callback: (response: PickerResponse) => void) => PickerBuilderApi
  build: () => { setVisible: (visible: boolean) => void }
}

declare global {
  interface Window {
    google?: {
      accounts?: {
        oauth2: {
          initTokenClient: (options: {
            client_id: string
            scope: string
            callback: (response: TokenResponse) => void
            error_callback?: (error: { type?: string }) => void
          }) => TokenClient
        }
      }
      picker?: {
        DocsView: new (viewId?: string) => DocsViewApi
        PickerBuilder: new () => PickerBuilderApi
        Action: { PICKED: string, CANCEL: string }
        Feature: { MULTISELECT_ENABLED: string }
        ViewId: { DOCS: string }
      }
    }
    gapi?: { load: (name: string, callback: () => void) => void }
  }
}

/** Загруженные скрипты помнятся между вызовами: второй раз грузить незачем. */
const loaded = new Map<string, Promise<void>>()

function loadScript(src: string): Promise<void> {
  const already = loaded.get(src)

  if (already) {
    return already
  }

  const loading = new Promise<void>((resolve, reject) => {
    const element = document.createElement('script')

    element.src = src
    element.async = true
    element.onload = () => resolve()
    element.onerror = () => {
      // Неудачу не запоминаем: со связью могло просто не повезти, и следующее
      // нажатие вправе попробовать снова.
      loaded.delete(src)
      reject(new Error(`Не удалось загрузить ${src}`))
    }

    document.head.appendChild(element)
  })

  loaded.set(src, loading)

  return loading
}

/** Разрешение, уже выданное в этой вкладке: спрашивать его дважды незачем. */
let accessToken: string | null = null

/** Запрос настроек, уже отправленный: страниц с кнопкой на экране бывает две. */
let loading: Promise<unknown> | null = null

export function useGoogleDrive() {
  const { fetchGoogleSettings } = useIntegrationsApi()

  /*
   * Ключи заводит администратор в настройках, и сюда они приезжают ответом
   * сервера — на всё приложение один раз. Спрашиваются они сразу, а не по
   * нажатию: по ним решается, показывать ли кнопку вообще.
   */
  const settings = useState<GoogleSettings | null>('integrations.google', () => null)

  if (import.meta.client && settings.value === null && loading === null) {
    loading = fetchGoogleSettings()
      .then((response) => {
        settings.value = response.data
      })
      // Молча: не спросить настройки — значит остаться без кнопки, а не без
      // урока. Ругаться на весь экран из-за этого не за что.
      .catch(() => {
        loading = null
      })
  }

  const clientId = computed(() => settings.value?.effective.client_id ?? '')
  const apiKey = computed(() => settings.value?.effective.api_key ?? '')

  /**
   * Настроен ли Диск. Пока ключей нет, кнопки выбора нет вовсе: неработающая
   * кнопка хуже отсутствующей — по ней нажимают и получают ошибку.
   */
  const isConfigured = computed(() => settings.value?.is_configured ?? false)

  async function requestToken(): Promise<string> {
    if (accessToken !== null) {
      return accessToken
    }

    await loadScript(GIS_SCRIPT)

    const oauth = window.google?.accounts?.oauth2

    if (!oauth) {
      throw new Error('Библиотека Google не загрузилась.')
    }

    return new Promise<string>((resolve, reject) => {
      const client = oauth.initTokenClient({
        client_id: clientId.value,
        scope: SCOPE,
        callback: (response) => {
          if (!response.access_token) {
            reject(new Error(response.error ?? 'Google не выдал разрешение.'))

            return
          }

          accessToken = response.access_token
          resolve(response.access_token)
        },
        // Закрытое окно входа — решение человека, а не поломка, но ждать
        // обещание вечно всё равно нельзя.
        error_callback: error => reject(new Error(error.type ?? 'Окно Google закрыто.')),
      })

      client.requestAccessToken()
    })
  }

  async function loadPicker(): Promise<NonNullable<NonNullable<Window['google']>['picker']>> {
    await loadScript(API_SCRIPT)

    await new Promise<void>((resolve) => {
      window.gapi?.load('picker', () => resolve())
    })

    const picker = window.google?.picker

    if (!picker) {
      throw new Error('Окно выбора файла не загрузилось.')
    }

    return picker
  }

  /**
   * Открывает окно Google и возвращает выбранное.
   *
   * Пустой список — не ошибка: окно закрыли, ничего не выбрав. Вызывающий
   * просто ничего не делает.
   */
  async function pick(): Promise<DriveFile[]> {
    if (!import.meta.client || !isConfigured.value) {
      return []
    }

    const [token, picker] = await Promise.all([requestToken(), loadPicker()])

    return new Promise<DriveFile[]>((resolve) => {
      /**
       * Папки видны и выбираются во всех вкладках: инструкции на Диске чаще
       * лежат папкой, а не одним файлом, и прикреплять их по одной — работа на
       * полдня.
       */
      const folders = (view: DocsViewApi): DocsViewApi =>
        view.setIncludeFolders(true).setSelectFolderEnabled(true)

      /*
       * Три вкладки, а не одна. Файл, который человек ищет, лежит в одном из
       * трёх мест, и какое из них его — знает он, а не мы: свой Диск, отданное
       * ему в доступ, общие диски компании. Одна вкладка «общих дисков» здесь и
       * стояла — и показывала пустоту тому, у кого файлы свои.
       */
      const myDrive = folders(new picker.DocsView(picker.ViewId.DOCS))
      const sharedWithMe = folders(new picker.DocsView(picker.ViewId.DOCS)).setOwnedByMe(false)
      const sharedDrives = folders(new picker.DocsView(picker.ViewId.DOCS)).setEnableDrives(true)

      new picker.PickerBuilder()
        .addView(myDrive)
        .addView(sharedWithMe)
        .addView(sharedDrives)
        .setOAuthToken(token)
        .setDeveloperKey(apiKey.value)
        // Номер проекта — первая часть номера клиента. При разрешении
        // `drive.file` Google требует его, чтобы понимать, какому приложению
        // открывать выбранный файл.
        .setAppId(clientId.value.split('-')[0] ?? '')
        .setLocale('ru')
        .setTitle('Выберите файл на Google Диске')
        .enableFeature(picker.Feature.MULTISELECT_ENABLED)
        .setCallback((response) => {
          if (response.action !== picker.Action.PICKED) {
            if (response.action === picker.Action.CANCEL) {
              resolve([])
            }

            return
          }

          resolve((response.docs ?? []).map(document => ({
            external_id: document.id,
            name: document.name || 'Файл с Google Диска',
            mime_type: document.mimeType ?? null,
          })))
        })
        .build()
        .setVisible(true)
    })
  }

  return { isConfigured, pick }
}
