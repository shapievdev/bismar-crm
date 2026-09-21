/**
 * Знаки для подсказки над полем ввода.
 *
 * Своим списком, а не библиотекой: полный набор Unicode — это сотни килобайт
 * данных и картинок ради того, чем в рабочей переписке пользуются десятком
 * знаков. Здесь — то, что действительно набирают, с русским поиском: ищут
 * «палец», а не «thumbs up».
 *
 * Показываются они шрифтом самой системы. Свои картинки означали бы, что знак в
 * ленте и знак в уведомлении на устройстве выглядят по-разному.
 */

export interface EmojiGroup {
  /** Название вкладки в подсказке. */
  title: string
  /** Знак на самой вкладке. */
  sign: string
  items: EmojiItem[]
}

export interface EmojiItem {
  emoji: string
  /** По чему его ищут — через пробел, только в нижнем регистре. */
  keywords: string
}

export const EMOJI_GROUPS: EmojiGroup[] = [
  {
    title: 'Лица',
    sign: '🙂',
    items: [
      { emoji: '😀', keywords: 'улыбка радость смех' },
      { emoji: '😁', keywords: 'улыбка зубы радость' },
      { emoji: '😂', keywords: 'смех слёзы ржу' },
      { emoji: '🤣', keywords: 'смех ржу катаюсь' },
      { emoji: '🙂', keywords: 'улыбка спокойствие' },
      { emoji: '😉', keywords: 'подмигивание шутка' },
      { emoji: '😊', keywords: 'улыбка смущение доволен' },
      { emoji: '😇', keywords: 'ангел невинность' },
      { emoji: '🥰', keywords: 'любовь сердечки нежность' },
      { emoji: '😍', keywords: 'любовь восторг глаза' },
      { emoji: '😘', keywords: 'поцелуй' },
      { emoji: '😎', keywords: 'очки круто' },
      { emoji: '🤩', keywords: 'восторг звёзды вау' },
      { emoji: '🤔', keywords: 'думаю сомнение вопрос' },
      { emoji: '🤨', keywords: 'бровь сомнение недоверие' },
      { emoji: '😐', keywords: 'нейтрально молчу' },
      { emoji: '😑', keywords: 'без эмоций устал' },
      { emoji: '🙄', keywords: 'закатил глаза ну да' },
      { emoji: '😏', keywords: 'ухмылка хитрость' },
      { emoji: '😴', keywords: 'сон устал сплю' },
      { emoji: '🥱', keywords: 'зевок скучно устал' },
      { emoji: '😔', keywords: 'грусть печаль' },
      { emoji: '😢', keywords: 'плачу грусть слеза' },
      { emoji: '😭', keywords: 'рыдаю слёзы грусть' },
      { emoji: '😤', keywords: 'злость решимость пар' },
      { emoji: '😡', keywords: 'злость гнев' },
      { emoji: '🤬', keywords: 'ругань злость мат' },
      { emoji: '😱', keywords: 'ужас страх крик' },
      { emoji: '😨', keywords: 'страх испуг' },
      { emoji: '😥', keywords: 'огорчение волнение' },
      { emoji: '😬', keywords: 'неловко зубы' },
      { emoji: '🤯', keywords: 'взрыв мозга шок' },
      { emoji: '😮', keywords: 'удивление ох' },
      { emoji: '😳', keywords: 'смущение шок' },
      { emoji: '🥲', keywords: 'улыбка сквозь слёзы' },
      { emoji: '🤗', keywords: 'обнимаю объятия' },
      { emoji: '🤝', keywords: 'рукопожатие договорились сделка' },
      { emoji: '🫠', keywords: 'плавлюсь жара всё' },
      { emoji: '😷', keywords: 'маска болезнь' },
      { emoji: '🤒', keywords: 'болею температура' },
      { emoji: '🤕', keywords: 'травма болит' },
      { emoji: '🥳', keywords: 'праздник вечеринка' },
      { emoji: '🤫', keywords: 'тише секрет молчи' },
      { emoji: '🤐', keywords: 'молчу рот на замок' },
      { emoji: '😶', keywords: 'без слов молчу' },
      { emoji: '🫡', keywords: 'есть принял честь' },
    ],
  },
  {
    title: 'Жесты',
    sign: '👍',
    items: [
      { emoji: '👍', keywords: 'палец вверх хорошо согласен лайк' },
      { emoji: '👎', keywords: 'палец вниз плохо против' },
      { emoji: '👌', keywords: 'окей отлично хорошо' },
      { emoji: '✌️', keywords: 'мир победа' },
      { emoji: '🤞', keywords: 'удача скрестил пальцы' },
      { emoji: '🙏', keywords: 'спасибо прошу пожалуйста молитва' },
      { emoji: '👏', keywords: 'аплодисменты браво молодец' },
      { emoji: '🙌', keywords: 'ура руки вверх' },
      { emoji: '💪', keywords: 'сила молодец держись' },
      { emoji: '👋', keywords: 'привет пока' },
      { emoji: '✋', keywords: 'стоп рука' },
      { emoji: '👉', keywords: 'указываю вот сюда' },
      { emoji: '👈', keywords: 'указываю влево' },
      { emoji: '☝️', keywords: 'внимание важно' },
      { emoji: '🤙', keywords: 'звони на связи' },
      { emoji: '✍️', keywords: 'пишу запись' },
    ],
  },
  {
    title: 'Знаки',
    sign: '✅',
    items: [
      { emoji: '✅', keywords: 'готово сделано галочка да' },
      { emoji: '☑️', keywords: 'отметка галочка' },
      { emoji: '❌', keywords: 'нет отмена крест ошибка' },
      { emoji: '⛔', keywords: 'нельзя запрет стоп' },
      { emoji: '⚠️', keywords: 'внимание осторожно предупреждение' },
      { emoji: '❗', keywords: 'важно восклицание' },
      { emoji: '❓', keywords: 'вопрос' },
      { emoji: '💯', keywords: 'сто процентов точно' },
      { emoji: '🔥', keywords: 'огонь круто горит срочно' },
      { emoji: '⭐', keywords: 'звезда избранное' },
      { emoji: '❤️', keywords: 'сердце любовь' },
      { emoji: '💔', keywords: 'разбитое сердце' },
      { emoji: '✨', keywords: 'блеск новое красиво' },
      { emoji: '🎉', keywords: 'праздник поздравляю ура' },
      { emoji: '🎁', keywords: 'подарок' },
      { emoji: '🚀', keywords: 'запуск быстро ракета' },
      { emoji: '⏰', keywords: 'будильник срок время' },
      { emoji: '⌛', keywords: 'время ждём песочные часы' },
      { emoji: '📌', keywords: 'закрепить кнопка важное' },
      { emoji: '🔒', keywords: 'закрыто замок доступ' },
      { emoji: '🔑', keywords: 'ключ доступ' },
      { emoji: '♻️', keywords: 'повтор возврат переработка' },
    ],
  },
  {
    title: 'Работа',
    sign: '📦',
    items: [
      { emoji: '📦', keywords: 'коробка товар отгрузка склад' },
      { emoji: '🚚', keywords: 'доставка машина отгрузка' },
      { emoji: '🏬', keywords: 'магазин точка' },
      { emoji: '🧾', keywords: 'чек накладная документ' },
      { emoji: '💰', keywords: 'деньги выручка касса' },
      { emoji: '💳', keywords: 'карта оплата' },
      { emoji: '📈', keywords: 'рост график вверх' },
      { emoji: '📉', keywords: 'падение график вниз' },
      { emoji: '📊', keywords: 'отчёт статистика диаграмма' },
      { emoji: '📋', keywords: 'список задачи планшет' },
      { emoji: '📄', keywords: 'документ лист бланк' },
      { emoji: '📁', keywords: 'папка файлы' },
      { emoji: '📎', keywords: 'скрепка вложение' },
      { emoji: '🗓️', keywords: 'календарь дата план' },
      { emoji: '📞', keywords: 'звонок телефон' },
      { emoji: '📱', keywords: 'телефон мобильный' },
      { emoji: '💻', keywords: 'компьютер ноутбук' },
      { emoji: '🖨️', keywords: 'печать принтер' },
      { emoji: '🔧', keywords: 'ремонт инструмент' },
      { emoji: '🛠️', keywords: 'работы инструменты' },
      { emoji: '📚', keywords: 'обучение книги база знаний' },
      { emoji: '🎓', keywords: 'обучение курс аттестация' },
      { emoji: '💡', keywords: 'идея предложение' },
      { emoji: '🧠', keywords: 'мозг думать идея' },
      { emoji: '🏆', keywords: 'победа кубок лучший' },
      { emoji: '🎯', keywords: 'цель план точно' },
    ],
  },
  {
    title: 'Прочее',
    sign: '☕',
    items: [
      { emoji: '☕', keywords: 'кофе перерыв' },
      { emoji: '🍽️', keywords: 'обед еда' },
      { emoji: '🍕', keywords: 'пицца еда' },
      { emoji: '🎂', keywords: 'торт день рождения' },
      { emoji: '🚗', keywords: 'машина еду' },
      { emoji: '🏠', keywords: 'дом' },
      { emoji: '☀️', keywords: 'солнце погода' },
      { emoji: '🌧️', keywords: 'дождь погода' },
      { emoji: '❄️', keywords: 'снег холод' },
      { emoji: '🌙', keywords: 'ночь луна' },
      { emoji: '🐱', keywords: 'кот кошка' },
      { emoji: '🐶', keywords: 'собака пёс' },
      { emoji: '🌳', keywords: 'дерево природа' },
      { emoji: '🎵', keywords: 'музыка' },
      { emoji: '🎬', keywords: 'видео кино запись' },
      { emoji: '📷', keywords: 'фото снимок' },
    ],
  },
]

/**
 * Чем можно откликнуться на реплику — тот же набор, что у сервера.
 *
 * Закрытый и короткий: открытый список означает, что под сообщением
 * руководителя однажды окажется знак, которого там быть не должно. Порядок —
 * тот, в каком они стоят в подсказке над сообщением: первым то, чем
 * откликаются чаще всего.
 */
export const REACTION_SET = ['👍', '❤️', '🔥', '👏', '✅', '🙏', '😁', '🤔', '😢', '😮', '👎', '🤝']

/** Последние выбранные — их держит сам браузер, по человеку на устройство. */
export const RECENT_EMOJI_KEY = 'chat.emoji.recent'

/** Сколько недавних помнить: ряд на ширину подсказки. */
export const RECENT_EMOJI_LIMIT = 24

/** Все знаки одним списком — по нему идёт поиск. */
export const ALL_EMOJI: EmojiItem[] = EMOJI_GROUPS.flatMap(group => group.items)

export function searchEmoji(query: string): EmojiItem[] {
  const needle = query.trim()

  if (needle === '') {
    return []
  }

  // Слова здесь русские, а раскладку в переписке забывают чаще, чем где-либо:
  // «eks,rf» ищет улыбку, а не ничего.
  return ALL_EMOJI.filter(item => matchesTyped(item.keywords, needle))
}
