import type { ChatPerson } from '~/types/chat'

/**
 * Разбор написанного в дерево кусков — жирное, ссылки, упоминания, спойлер.
 *
 * Разбирается на экране, а не на сервере, и хранится обычным текстом. Так
 * сказанное остаётся читаемым везде, где его показывают без разбора: в
 * уведомлении на устройстве, в строчке списка, в выдаче поиска. Разметка —
 * способ показать текст, а не сам текст.
 *
 * Отсюда же и дерево вместо готовой строки HTML: в строку пришлось бы
 * подставлять сказанное человеком, и тогда единственное, что отделяло бы
 * переписку от чужого скрипта, — правильность экранирования. Здесь подставлять
 * некуда: показывает дерево обычный компонент, и всякий кусок текста для него
 * остаётся текстом.
 */

/** Кусок сказанного — то, из чего собирается пузырь. */
export type TextNode =
  | { kind: 'text', text: string }
  | { kind: 'mark', mark: Mark, children: TextNode[] }
  | { kind: 'pre', text: string }
  | { kind: 'link', href: string, text: string, internal: boolean }
  | { kind: 'mention', text: string, id: number | null }

/** Начертания, которые переживают вложение друг в друга. */
export type Mark = 'bold' | 'italic' | 'strike' | 'code' | 'spoiler'

/**
 * Парные обёртки, как их набирают.
 *
 * Те же, что в телеграме и почти те же, что в markdown: руки у людей уже
 * научены. Двойные знаки, а не одиночные, — одиночная звёздочка в «2*2» не
 * должна превращать половину реплики в курсив.
 */
const WRAPS: Array<{ open: string, mark: Mark, raw?: boolean }> = [
  { open: '```', mark: 'code', raw: true },
  { open: '`', mark: 'code', raw: true },
  { open: '**', mark: 'bold' },
  { open: '__', mark: 'italic' },
  { open: '~~', mark: 'strike' },
  { open: '||', mark: 'spoiler' },
]

/**
 * Ссылка внутри текста.
 *
 * Хвостовая пунктуация в адрес не входит: «смотри тут https://crm.bismar.pro/lms.»
 * — точка здесь принадлежит предложению, а не ссылке, и уведённый ею адрес
 * открывается несуществующей страницей.
 */
const LINK = /https?:\/\/[^\s<>«»]+[^\s<>«».,;:!?)\]}'"]/giu

/** Свой адрес ведёт внутрь приложения, а не в новую вкладку. */
const OWN_HOSTS = ['crm.bismar.pro', 'localhost']

/**
 * Разбирает сказанное.
 *
 * @param people Участники разговора — по ним узнаются упоминания. Без них
 *               «@Иванов» остаётся просто текстом: позвать несуществующего
 *               нельзя, и подсвечивать это как вызов было бы обманом.
 */
export function parseMessageText(body: string, people: ChatPerson[] = []): TextNode[] {
  return splitBlocks(tidyEdges(body), people)
}

/**
 * Убирает пустоту по краям сказанного.
 *
 * Скопированное с сайта приходит с переводами строк спереди и сзади, и пузырь
 * из-за них раздувается ни на чём.
 */
function tidyEdges(body: string): string {
  // Возврат каретки — от Windows и от копирования из браузера. Сам по себе он
  // ничего не значит, а в разметке с `pre-wrap` даёт лишний перенос.
  return body.replace(/\r\n?/g, '\n').replace(/^\n+|\s+$/g, '')
}

/**
 * Схлопывает вереницы пустых строк.
 *
 * Текст, скопированный с веб-страницы, приходит с четырьмя-шестью пустыми
 * строками между пунктами списка: так на той странице были расставлены отступы,
 * и в переносы строк они превратились при копировании. Показанные как есть, они
 * рвут пузырь провалами в пол-экрана — ровно то, на что смотреть невозможно.
 *
 * Одна пустая строка остаётся: абзацы отделять надо, и это человек как раз
 * имел в виду. Само сказанное при этом не трогается — в базе лежит то, что
 * отправили, и поиск ищет по нему же. Здесь решается только, как это показать.
 */
function collapseBlanks(text: string): string {
  return text.replace(/\n{3,}/g, '\n\n')
}

/**
 * Блоки кода вынимаются первыми и целиком.
 *
 * Внутри тройных кавычек не действует ничего: в куске кода звёздочки и
 * подчёркивания — это код, а не разметка, и ради этого его туда и кладут.
 */
function splitBlocks(body: string, people: ChatPerson[]): TextNode[] {
  const nodes: TextNode[] = []
  let rest = body

  for (;;) {
    const opened = rest.indexOf('```')

    if (opened === -1) {
      break
    }

    const closed = rest.indexOf('```', opened + 3)

    if (closed === -1) {
      break
    }

    if (opened > 0) {
      nodes.push(...inline(collapseBlanks(rest.slice(0, opened)), people))
    }

    // Перевод строки сразу за открывающими кавычками съедается: его набирают,
    // чтобы код начался с новой строки, а не чтобы он начался с пустой.
    nodes.push({ kind: 'pre', text: rest.slice(opened + 3, closed).replace(/^\n/, '').replace(/\n$/, '') })

    rest = rest.slice(closed + 3)
  }

  if (rest !== '') {
    // Пустые строки схлопываются только снаружи блоков кода: внутри они —
    // часть того, что показывают, и трогать их нельзя.
    nodes.push(...inline(collapseBlanks(rest), people))
  }

  return nodes
}

/**
 * Строчная разметка: ищем ближайшую открытую обёртку и разбираем её содержимое.
 *
 * Рекурсией, а не проходом по списку правил: «**очень _важно_**» — это жирное,
 * внутри которого курсив, и собрать такое одним проходом значит либо потерять
 * вложенность, либо склеивать строки HTML.
 */
function inline(text: string, people: ChatPerson[]): TextNode[] {
  if (text === '') {
    return []
  }

  const found = firstWrap(text)

  if (found === null) {
    return plain(text, people)
  }

  const { at, wrap } = found
  const from = at + wrap.open.length
  const closed = text.indexOf(wrap.open, from)

  if (closed === -1) {
    // Открыли и не закрыли — значит, это не разметка, а просто знаки. Ищем
    // дальше по строке, чтобы «2**2 и **правда**» всё-таки нашло второе.
    const head = text.slice(0, from)
    const tail = text.slice(from)

    return [...plain(head, people), ...inline(tail, people)]
  }

  const inner = text.slice(from, closed)

  // Пустая пара — это те же знаки: «||» посреди таблицы не спойлер.
  if (inner === '') {
    return [...plain(text.slice(0, closed + wrap.open.length), people), ...inline(text.slice(closed + wrap.open.length), people)]
  }

  return [
    ...plain(text.slice(0, at), people),
    {
      kind: 'mark',
      mark: wrap.mark,
      // Внутри кода разметки нет: там всякий знак — это знак.
      children: wrap.raw ? [{ kind: 'text', text: inner }] : inline(inner, people),
    },
    ...inline(text.slice(closed + wrap.open.length), people),
  ]
}

/** Ближайшая по строке обёртка — и какая именно. */
function firstWrap(text: string): { at: number, wrap: (typeof WRAPS)[number] } | null {
  let best: { at: number, wrap: (typeof WRAPS)[number] } | null = null

  for (const wrap of WRAPS) {
    const at = text.indexOf(wrap.open)

    if (at !== -1 && (best === null || at < best.at)) {
      best = { at, wrap }
    }
  }

  return best
}

/** Кусок без обёрток: в нём остаётся найти ссылки и упоминания. */
function plain(text: string, people: ChatPerson[]): TextNode[] {
  return links(text).flatMap(node => (node.kind === 'text' ? mentions(node.text, people) : [node]))
}

function links(text: string): TextNode[] {
  const nodes: TextNode[] = []
  let at = 0

  for (const match of text.matchAll(LINK)) {
    const start = match.index ?? 0

    if (start > at) {
      nodes.push({ kind: 'text', text: text.slice(at, start) })
    }

    nodes.push({
      kind: 'link',
      href: match[0],
      text: shorten(match[0]),
      internal: isOwn(match[0]),
    })

    at = start + match[0].length
  }

  if (at < text.length) {
    nodes.push({ kind: 'text', text: text.slice(at) })
  }

  return nodes
}

/**
 * Упоминания узнаются по именам участников, а не по одной собаке.
 *
 * Разбирать «@» до пробела нельзя: фамилия, имя и отчество разделены пробелами,
 * и «@Курабанов Давлет» оборвалось бы на фамилии. Имена берутся длинные вперёд
 * — иначе «@Иванов» съел бы начало «@Иванов Иван».
 */
function mentions(text: string, people: ChatPerson[]): TextNode[] {
  if (people.length === 0 || !text.includes('@')) {
    return text === '' ? [] : [{ kind: 'text', text }]
  }

  const names = people
    .flatMap(person => [
      { name: person.name, id: person.id },
      { name: person.short_name, id: person.id },
    ])
    .sort((left, right) => right.name.length - left.name.length)

  const nodes: TextNode[] = []
  let rest = text

  for (;;) {
    const at = rest.indexOf('@')

    if (at === -1) {
      break
    }

    const after = rest.slice(at + 1)
    const hit = names.find(one => after.startsWith(one.name))

    if (!hit) {
      // Собака не от упоминания — почта, например. Отдаём её текстом и ищем
      // следующую.
      nodes.push({ kind: 'text', text: rest.slice(0, at + 1) })
      rest = after

      continue
    }

    if (at > 0) {
      nodes.push({ kind: 'text', text: rest.slice(0, at) })
    }

    nodes.push({ kind: 'mention', text: `@${hit.name}`, id: hit.id })
    rest = after.slice(hit.name.length)
  }

  if (rest !== '') {
    nodes.push({ kind: 'text', text: rest })
  }

  return nodes
}

function isOwn(href: string): boolean {
  try {
    return OWN_HOSTS.includes(new URL(href).hostname)
  }
  catch {
    return false
  }
}

/** Путь внутри своего адреса — чтобы ссылка вела по приложению, а не наружу. */
export function ownPath(href: string): string | null {
  try {
    const url = new URL(href)

    return OWN_HOSTS.includes(url.hostname) ? url.pathname + url.search + url.hash : null
  }
  catch {
    return null
  }
}

/** Длинный адрес в пузыре обрезается: он ломает перенос строк и читается плохо. */
function shorten(href: string): string {
  return href.length > 60 ? `${href.slice(0, 57)}…` : href
}

/**
 * Первая ссылка наружу — та, под которой встанет карточка.
 *
 * Первая, а не все: реплика со списком из десяти ссылок превратилась бы в
 * простыню карточек, а показывают их затем, чтобы не открывать ссылку ради
 * вопроса «что там». Свои адреса пропускаются — карточка из своей же страницы
 * ничего не добавит к тому, что и так на экране.
 *
 * Внутри блока кода ссылки не ищутся: там их приводят как текст, а не как
 * приглашение перейти.
 */
export function firstLinkIn(body: string): string | null {
  const outside = body.replace(/```[\s\S]*?```/g, ' ')

  for (const match of outside.matchAll(LINK)) {
    if (ownPath(match[0]) === null) {
      return match[0]
    }
  }

  return null
}

/**
 * Сказанное без разметки — для строчки списка, подсказки и поиска.
 *
 * Там показывают одну строку и без начертаний, и звёздочки в ней выглядят
 * опечаткой.
 */
export function plainMessageText(body: string): string {
  return body
    .replace(/```([\s\S]*?)```/g, '$1')
    .replace(/(\*\*|__|~~|\|\||`)/g, '')
}
