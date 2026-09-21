/**
 * Поиск по всей платформе — то, что возвращает шапка.
 *
 * Разделы приходят готовыми и в готовом порядке: что показать этому человеку и
 * в какой последовательности, решает сервер (App\Support\Search\Everywhere) —
 * он же и единственный, кто знает его права.
 */

/** Раздел выдачи. Совпадает с `kind` на сервере. */
export type SearchKind = 'course' | 'lesson' | 'document' | 'handbook' | 'news' | 'person'

export interface SearchHit {
  title: string
  /** Вторая строка: категория, курс, должность, дата — смотря что нашлось. */
  subtitle: string | null
  /** Адрес страницы самой находки. */
  url: string
}

export interface SearchSection {
  kind: SearchKind
  label: string
  /** Каталог раздела с тем же словом. Null — искать дальше негде. */
  more_url: string | null
  items: SearchHit[]
}
