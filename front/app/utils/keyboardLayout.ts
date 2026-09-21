/**
 * Забытая раскладка: «ljrevtyn» — это «документ».
 *
 * Сотрудник печатает, не глядя на строку поиска, и половину запросов набирает
 * латиницей по русским клавишам. Списки, которые отбираются на месте — разговоры,
 * варианты в выпадающем списке, упоминания, — обязаны это прощать так же, как
 * прощает сервер: там ровно та же поблажка (App\Support\Search\KeyboardLayout),
 * и разойтись им нельзя, иначе одно и то же слово находит в одном списке и не
 * находит в другом.
 *
 * Переводятся не буквы, а клавиши: «б» живёт на запятой, «ж» — на точке с
 * запятой. Без знаков препинания перевод терял бы каждое слово с «б», «ю», «ж»,
 * «э», «х» и «ъ» — «банк» превратился бы в «анк».
 */

/**
 * Клавиши в латинской раскладке и те же клавиши в русской.
 *
 * Две строки одной длины, символ против символа: ошибка в одной сразу видна по
 * сдвигу остальных. Ряды идут как на клавиатуре, сперва без Shift, потом с ним.
 */
const QWERTY = 'qwertyuiop[]asdfghjkl;\'zxcvbnm,./`QWERTYUIOP{}ASDFGHJKL:"ZXCVBNM<>?~'
const JCUKEN = 'йцукенгшщзхъфывапролджэячсмитьбю.ёЙЦУКЕНГШЩЗХЪФЫВАПРОЛДЖЭЯЧСМИТЬБЮ,Ё'

const TO_CYRILLIC = new Map([...QWERTY].map((key, index) => [key, JCUKEN[index]!]))
const TO_LATIN = new Map([...JCUKEN].map((key, index) => [key, QWERTY[index]!]))

/**
 * Короче двух букв не перечитываем: одна буква в другой раскладке — это другая
 * одна буква, и найдёт она всё что угодно.
 */
const MIN_LETTERS = 2

const LATIN = /[a-z]/gi
const CYRILLIC = /[\u0400-\u04FF]/g

function translate(term: string, keys: Map<string, string>): string {
  return [...term].map(sign => keys.get(sign) ?? sign).join('')
}

function count(term: string, letters: RegExp): number {
  return term.match(letters)?.length ?? 0
}

/**
 * То же, прочитанное второй раскладкой, — или null, если читать незачем.
 *
 * Незачем в трёх случаях: букв нет вовсе, алфавиты смешаны (раскладку
 * переключали осознанно) или перевод ничего не изменил.
 */
function otherLayoutReading(term: string): string | null {
  const latin = count(term, LATIN)
  const cyrillic = count(term, CYRILLIC)

  if (latin > 0 && cyrillic > 0) {
    return null
  }

  let translated: string | null = null

  if (latin >= MIN_LETTERS) {
    translated = translate(term, TO_CYRILLIC)
  }
  else if (cyrillic >= MIN_LETTERS) {
    translated = translate(term, TO_LATIN)
  }

  return translated === term ? null : translated
}

/**
 * Как запрос стоит прочесть — своими словами и второй раскладкой.
 *
 * Первым всегда идёт то, что набрано. Второго чтения может не быть вовсе.
 */
function layoutReadings(term: string): string[] {
  const trimmed = term.trim()

  if (trimmed === '') {
    return []
  }

  const other = otherLayoutReading(trimmed)

  return other === null ? [trimmed] : [trimmed, other]
}

/**
 * Встречается ли набранное в тексте — хоть в одном из двух чтений.
 *
 * Регистр складывается здесь же: звать это из отбора списка приходится на каждый
 * вариант, и заставлять каждое место помнить про `toLowerCase()` значит однажды
 * забыть.
 */
export function matchesTyped(text: string, term: string): boolean {
  const haystack = text.toLowerCase()

  return layoutReadings(term).some(reading => haystack.includes(reading.toLowerCase()))
}
