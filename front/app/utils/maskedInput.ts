/**
 * Ввод под маской: показать набранное в нужном виде и вернуть курсор на место.
 *
 * Живёт отдельно от полей, потому что полей два — общий FormField и телефон на
 * экране профиля, набранный своей разметкой, — а правило возврата курсора
 * должно быть одно: разойдясь, они вели бы себя по-разному на одном и том же
 * нажатии.
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
 * Приводит содержимое поля к виду маски и возвращает получившееся значение.
 *
 * Курсор считается по числу цифр СПРАВА от него, а не слева. Считать слева
 * нельзя: маска дописывает начало — «9» превращается в «+7 (9», — и цифры,
 * которых человек не набирал, сдвинули бы курсор на них. Справа маска ничего
 * не добавляет, поэтому хвост — единственная надёжная примета места.
 *
 * Значение ставится и в само поле: браузер перерисует его тем же, ничего не
 * тронет — и курсор, поставленный следом, останется там, куда его вернули.
 */
export function applyMask(input: HTMLInputElement, format: (value: string) => string): string {
  const caret = input.selectionStart ?? input.value.length
  const digitsAfter = countDigits(input.value.slice(caret))
  const formatted = format(input.value)

  input.value = formatted

  const restored = positionLeavingDigits(formatted, digitsAfter)
  input.setSelectionRange(restored, restored)

  return formatted
}
