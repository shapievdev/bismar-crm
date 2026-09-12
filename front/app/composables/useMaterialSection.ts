import type { MaterialSection } from '~/types/lms'

/**
 * Раздел базы знаний, устроенный как документ: документы или справочники.
 *
 * Экраны у них общие до последней кнопки — разное только назначение, и оно
 * живёт в словах. Держать эти слова в самих экранах значит писать по два
 * ветвления на каждую надпись; здесь же видно оба раздела разом, и различие
 * читается списком, а не поиском по шаблону.
 *
 * **Документ** отвечает на вопрос «по какому правилу мы работаем»: его читают
 * целиком и отмечаются. **Справочник** — «что делать прямо сейчас»: в него
 * заходят посреди разговора с клиентом, за одним ответом.
 */
export interface MaterialCopy {
  /** Раздел в адресах — и приложения, и API. */
  section: MaterialSection
  /** Что человеку позволено в этом разделе. */
  rights: MaterialRights
  /** Как материал зовётся в единственном числе там, где вид важнее раздела:
      в карточке над репликой мессенджера. */
  kind: 'document' | 'handbook'
  /** Заголовок раздела и подпись под ним. */
  title: string
  /** Единственное число: «Документ», «Справочник». */
  materialLabel: string
  subtitle: string
  /** «Новый документ» — кнопка и заголовок страницы создания. */
  createLabel: string
  createHint: string
  createFailed: string
  /** Подсказка в пустом редакторе статьи. */
  articlePlaceholder: string
  /** Родительный падеж после числа: «5 документов». */
  counted: [one: string, few: string, many: string]
  notFound: string
  editorTitle: string
  saveFailed: string
  removeLabel: string
  categoriesTitle: string
  categoriesSubtitle: string
  /** Отметка об ознакомлении — она у обоих видов есть, но зовётся по-своему. */
  acknowledged: string
  acknowledgeHint: string
  quizPassedNote: string
  quizFailedNote: string
  quizRule: (examiner: string | null) => string
  emptyCatalogue: string
}

/**
 * Права раздела — по одному на действие.
 *
 * У документов и справочников они свои (2026-09-11): правила компании и
 * справочник для зала ведут разные люди. Экраны у разделов общие, поэтому имя
 * права приходит оттуда же, откуда и надписи, — иначе каждая кнопка спрашивала
 * бы «а в каком мы разделе» сама.
 */
export interface MaterialRights {
  view: string
  create: string
  update: string
  delete: string
}

const COPY: Record<MaterialSection, MaterialCopy> = {
  documents: {
    section: 'documents',
    rights: {
      view: 'documents.view',
      create: 'documents.create',
      update: 'documents.update',
      delete: 'documents.delete',
    },
    kind: 'document',
    title: 'Документы',
    materialLabel: 'Документ',
    subtitle: 'Правила, по которым работают. Каждое — на одну страницу, с отметкой об ознакомлении.',
    createLabel: 'Новый документ',
    createHint: 'Здесь только название и место: статью и файлы пишут на экране правки.',
    createFailed: 'Не удалось создать документ.',
    articlePlaceholder: 'Текст правила. Можно вставить картинку или видео.',
    counted: ['документ', 'документа', 'документов'],
    notFound: 'Документ не найден',
    editorTitle: 'Правка документа',
    saveFailed: 'Не удалось сохранить документ.',
    removeLabel: 'Удалить документ',
    categoriesTitle: 'Категории документов',
    categoriesSubtitle: 'Разделы, по которым разложены правила. У документов своё дерево — не то, что у курсов.',
    acknowledged: 'Вы ознакомились с этим документом',
    acknowledgeHint: 'Отметьтесь, когда прочтёте: по этой отметке видно, что правило до вас дошло.',
    quizPassedNote: 'Документ отмечен как прочитанный.',
    quizFailedNote: 'Перечитайте документ и попробуйте снова.',
    quizRule: examiner => examiner === null
      ? 'Документ зачтётся, когда все ответы будут верными.'
      : `Работу читает ${examiner}: документ зачтётся после его ответа.`,
    emptyCatalogue: 'Документов пока нет.',
  },
  handbooks: {
    section: 'handbooks',
    rights: {
      view: 'handbooks.view',
      create: 'handbooks.create',
      update: 'handbooks.update',
      delete: 'handbooks.delete',
    },
    kind: 'handbook',
    title: 'Справочники',
    materialLabel: 'Справочник',
    subtitle: 'Ответ на ситуацию за полминуты. Карточка на один вопрос — открывают её посреди разговора с клиентом.',
    createLabel: 'Новый справочник',
    createHint: 'Здесь только название и место: ответ и файлы пишут на экране правки.',
    createFailed: 'Не удалось создать справочник.',
    articlePlaceholder: 'Ответ на ситуацию: что делать, кто решает, что нельзя говорить.',
    counted: ['справочник', 'справочника', 'справочников'],
    notFound: 'Справочник не найден',
    editorTitle: 'Правка справочника',
    saveFailed: 'Не удалось сохранить справочник.',
    removeLabel: 'Удалить справочник',
    categoriesTitle: 'Категории справочников',
    categoriesSubtitle: 'Разделы, по которым разложены ответы. У справочников своё дерево — не то, что у документов.',
    acknowledged: 'Вы ознакомились с этим справочником',
    acknowledgeHint: 'Отметьтесь, когда прочтёте: по этой отметке видно, что справка до вас дошла.',
    quizPassedNote: 'Справочник отмечен как прочитанный.',
    quizFailedNote: 'Перечитайте справочник и попробуйте снова.',
    quizRule: examiner => examiner === null
      ? 'Справочник зачтётся, когда все ответы будут верными.'
      : `Работу читает ${examiner}: справочник зачтётся после его ответа.`,
    emptyCatalogue: 'Справочников пока нет.',
  },
}

export function useMaterialSection(section: MaterialSection): MaterialCopy {
  return COPY[section]
}

/**
 * «1 документ», «2 документа», «5 документов».
 *
 * Правило русского счёта, а не «шт.»: число в заголовке раздела читают как
 * фразу, и «5 документ» спотыкает на ровном месте.
 */
export function counted(amount: number, forms: MaterialCopy['counted']): string {
  const tens = amount % 100
  const ones = amount % 10

  if (tens >= 11 && tens <= 14) {
    return `${amount} ${forms[2]}`
  }

  if (ones === 1) {
    return `${amount} ${forms[0]}`
  }

  if (ones >= 2 && ones <= 4) {
    return `${amount} ${forms[1]}`
  }

  return `${amount} ${forms[2]}`
}
