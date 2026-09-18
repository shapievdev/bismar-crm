/**
 * Ввод под маской: показать набранное в нужном виде и вернуть курсор на место.
 *
 * Живёт отдельно от полей, потому что полей два — общий FormField и телефон на
 * экране профиля, набранный своей разметкой, — а правило возврата курсора
 * должно быть одно: разойдясь, они вели бы себя по-разному на одном и том же
 * нажатии.
 *
 * Здесь же собраны оговорки старых движков: сочинение слова клавиатурой, поле
 * без выделения, подстановка сохранённого номера. Ни одна из них не меняет
 * правила — каждая лишь говорит, когда маску накладывать нельзя, и делает это
 * так, чтобы отказ касался одного нажатия, а не всего поля.
 */

function countDigits(value: string): number {
  return (value.match(/\d/g) ?? []).length
}

/** Место, после которого в строке остаётся ровно столько цифр. */
function positionLeavingDigits(value: string, digits: number): number {
  if (digits === 0) {
    return value.length
  }

  let seen = 0

  for (let index = value.length; index > 0; index -= 1) {
    if (/\d/.test(value[index - 1]!)) {
      seen += 1

      if (seen === digits) {
        return index - 1
      }
    }
  }

  return 0
}

/**
 * Набор ещё не закончен: клавиатура сочиняет слово.
 *
 * Пока идёт сочинение — подсказка Gboard, диктовка, рукописный ввод, — поле
 * принадлежит клавиатуре: подменённое значение она либо потеряет, либо допишет
 * вторым разом, и вместо номера выходит каша. Такой `input` пропускается, а
 * маску накладывает `compositionend`, которым сочинение заканчивается.
 *
 * Отдельным случаем он назван потому, что старые Android-браузеры присылают его
 * ПОСЛЕ `input`, а не до: не дождись мы его — набранное подсказкой осталось бы
 * без маски до следующего нажатия.
 */
function isComposing(event: Event): boolean {
  if (event.type === 'compositionend') {
    return false
  }

  return (event as InputEvent).isComposing === true
}

/**
 * Курсор, если браузер о нём рассказывает.
 *
 * У полей вроде `email` и `number` выделения нет вовсе — там `selectionStart`
 * отвечает пустотой, а старые движки роняют обращение ошибкой. Спрашивается он
 * поэтому под защитой: промолчал — значит курсор вернуть не выйдет, но сама
 * маска на этом держаться не должна.
 */
function readCaret(input: HTMLInputElement): number | null {
  try {
    return input.selectionStart
  }
  catch {
    return null
  }
}

/**
 * Вернуть курсор — когда есть куда и чем.
 *
 * Поле не в руках у человека — возвращать нечего: курсора в нём нет, а старый
 * Android на такой попытке поднимает клавиатуру ни с того ни с сего.
 */
function restoreCaret(input: HTMLInputElement, position: number): void {
  if (document.activeElement !== input || typeof input.setSelectionRange !== 'function') {
    return
  }

  try {
    input.setSelectionRange(position, position)
  }
  catch {
    // Поле без выделения: курсор встанет в конец сам, и набору это не мешает.
  }
}

/**
 * Приводит содержимое поля к виду маски и возвращает получившееся значение.
 *
 * Курсор считается по числу цифр СПРАВА от него, а не слева. Считать слева
 * нельзя: маска дописывает начало — «9» превращается в «+7 (9», — и цифры,
 * которых человек не набирал, сдвинули бы курсор на них. Справа маска ничего
 * не добавляет, поэтому хвост — единственная надёжная примета места.
 *
 * Значение ставится и в само поле, но только когда маска его правда изменила:
 * присвоение тем же самым старый iOS Safari считает новым вводом и уводит
 * курсор в конец, прокручивая поле. Не изменила — курсор и так стоит там, куда
 * его поставил человек, и трогать его незачем.
 *
 * Берётся событие, а не поле: по нему видно, чем ввод вызван, — а вызвать его
 * могут три разных повода (набор, конец сочинения, подстановка сохранённого),
 * и различать их больше негде.
 */
export function applyMask(event: Event, format: (value: string) => string): string {
  const input = event.target as HTMLInputElement

  if (isComposing(event)) {
    return input.value
  }

  const caret = readCaret(input)
  const digitsAfter = countDigits(input.value.slice(caret ?? input.value.length))
  const formatted = format(input.value)

  if (formatted === input.value) {
    return formatted
  }

  input.value = formatted

  if (caret !== null) {
    restoreCaret(input, positionLeavingDigits(formatted, digitsAfter))
  }

  return formatted
}
