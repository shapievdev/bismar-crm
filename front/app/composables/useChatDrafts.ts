/**
 * Черновик на каждую переписку.
 *
 * Начатое и брошенное — обычное дело: человек пишет ответ, его дёргают, он
 * уходит в другой разговор и возвращается. Без этого набранное пропадало при
 * первом же переключении, и второй раз то же самое уже не пишут.
 *
 * Хранится в самом браузере, а не на сервере. Черновик — это то, что человек
 * ещё не сказал; отправлять его на сервер на каждое нажатие клавиши значило бы
 * и поток запросов, и хранение несказанного там, где лежит сказанное. Ценой
 * тому — черновик не переезжает с телефона на стол; начатое там и дописывают.
 */

const STORAGE_KEY = 'chat.drafts'

/** Дольше двух недель черновик не живёт: это уже не «допишу», а мусор. */
const KEEP_DAYS = 14

interface StoredDraft {
  text: string
  /** Когда трогали: по этому полю протухшие и подметаются. */
  at: number
}

export function useChatDrafts() {
  /**
   * Достаёт всё сразу и пишет всё сразу.
   *
   * Черновиков единицы, и разбор строки на них стоит меньше, чем отдельный ключ
   * на переписку: подметать протухшее по отдельным ключам пришлось бы обходом
   * всего хранилища.
   */
  function all(): Record<string, StoredDraft> {
    if (!import.meta.client) {
      return {}
    }

    try {
      const raw = window.localStorage.getItem(STORAGE_KEY)

      return raw ? JSON.parse(raw) as Record<string, StoredDraft> : {}
    }
    catch {
      // Испорченное хранилище — не повод ронять мессенджер: черновиков просто
      // не будет.
      return {}
    }
  }

  function save(drafts: Record<string, StoredDraft>): void {
    if (!import.meta.client) {
      return
    }

    try {
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(drafts))
    }
    catch {
      // Хранилище переполнено или закрыто настройками. Черновик — удобство, а
      // не обязательство: молча обходимся без него.
    }
  }

  function read(conversationId: number): string {
    return all()[String(conversationId)]?.text ?? ''
  }

  function write(conversationId: number, text: string): void {
    const drafts = sweep(all())
    const key = String(conversationId)

    if (text.trim() === '') {
      delete drafts[key]
    }
    else {
      drafts[key] = { text, at: Date.now() }
    }

    save(drafts)
  }

  function drop(conversationId: number): void {
    write(conversationId, '')
  }

  /** Убирает залежавшееся: через две недели это уже не черновик. */
  function sweep(drafts: Record<string, StoredDraft>): Record<string, StoredDraft> {
    const edge = Date.now() - KEEP_DAYS * 24 * 60 * 60 * 1000

    return Object.fromEntries(Object.entries(drafts).filter(([, draft]) => draft.at > edge))
  }

  return { read, write, drop }
}
