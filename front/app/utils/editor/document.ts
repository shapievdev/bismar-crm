import type { JSONContent } from '@tiptap/core'

/**
 * Одинаковы ли два документа статьи.
 *
 * Сравнение нарочно нестрогое, и обе поблажки — про то, как статья доезжает до
 * базы и обратно. На этом сравнении держится и решение, чью статью оставить при
 * перечитывании записи, и надпись «есть несохранённые правки»: если оно
 * расходится всегда, редактор никогда не заберёт с сервера имена блоков, а
 * надпись загорится сразу после сохранения и не погаснет.
 *
 * **Порядок ключей не в счёт.** Статья лежит в `jsonb`, а Postgres
 * раскладывает ключи объекта по-своему — посимвольное сравнение расходилось бы
 * на любой статье.
 *
 * **Пустая строка и `null` — одно и то же.** Тело запроса проходит через
 * `ConvertEmptyStringsToNull`, и отправленный пустой атрибут возвращается
 * нулём: снятый адрес вложения (`src`), разметка пустого HTML-блока. Для
 * документа это одна и та же пустота, и различать её значило бы считать
 * статью изменённой ровно с того мгновения, как её сохранили.
 */
export function isSameDocument(one: JSONContent | null, other: JSONContent | null): boolean {
  return canonical(one) === canonical(other)
}

function canonical(value: JSONContent | null): string {
  return JSON.stringify(withSortedKeys(value))
}

/** То же значение, но ключи каждого объекта — по алфавиту, а пустота — одна. */
function withSortedKeys(value: unknown): unknown {
  if (Array.isArray(value)) {
    return value.map(withSortedKeys)
  }

  if (value === '') {
    return null
  }

  if (value === null || typeof value !== 'object') {
    return value
  }

  return Object.fromEntries(
    Object.entries(value as Record<string, unknown>)
      .sort(([one], [other]) => one.localeCompare(other))
      .map(([key, nested]) => [key, withSortedKeys(nested)]),
  )
}
