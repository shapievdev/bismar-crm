import type { LinkCard } from '~/types/chat'

/**
 * Карточки ссылок — один запрос на адрес, сколько бы реплик на него ни ссылалось.
 *
 * Держится на вкладку, а не на компонент: одну и ту же ссылку скидывают в
 * несколько переписок, и каждая её реплика спросила бы карточку заново. Здесь
 * запрос заводится один, а все ждут его же.
 *
 * Пустой ответ запоминается наравне с непустым: половина ссылок в переписке
 * ведёт туда, где ни заголовка, ни картинки нет, и спрашивать о них повторно
 * при каждой прокрутке ленты незачем.
 */
const cards = new Map<string, LinkCard | null>()
const asking = new Map<string, Promise<LinkCard | null>>()

export function useLinkPreviews() {
  const api = useChatApi()

  /** Уже известное — сразу, чтобы карточка не мигала при перерисовке. */
  function known(url: string): LinkCard | null | undefined {
    return cards.get(url)
  }

  async function load(url: string): Promise<LinkCard | null> {
    if (cards.has(url)) {
      return cards.get(url) ?? null
    }

    const already = asking.get(url)

    if (already) {
      return already
    }

    const request = api.fetchLinkPreview(url)
      .then(({ data }) => {
        cards.set(url, data)

        return data
      })
      .catch(() => {
        // Сорвалось — запоминаем как «нечего показать». Карточка тут украшение,
        // и сообщать о её неудаче человеку нечем и незачем.
        cards.set(url, null)

        return null
      })
      .finally(() => {
        asking.delete(url)
      })

    asking.set(url, request)

    return request
  }

  return { known, load }
}
