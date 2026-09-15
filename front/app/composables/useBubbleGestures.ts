/**
 * Жесты над сообщением: свайп — ответить, долгое нажатие — меню.
 *
 * Оба нужны на телефоне, где кнопки «⋯» у края пузыря не ухватить пальцем, и
 * оба обязаны уживаться с тем, что у этих же жестов уже есть хозяин: горизонталь
 * может означать прокрутку страницы, а долгое нажатие — выделение текста, чтобы
 * сообщение скопировать.
 *
 * Отсюда правила. Свайп начинает работать только после того, как палец ушёл в
 * сторону заметно дальше, чем вниз: пока направление не определилось, мы ничего
 * не перехватываем. Долгое нажатие отменяется первым же движением пальца —
 * сдвинулся, значит, это прокрутка или выделение, а не вызов меню.
 */

export interface BubbleGestureOptions {
  /** Ответить на это сообщение — свайпом вправо. */
  onReply: () => void
  /** Открыть меню — долгим нажатием или правой кнопкой. */
  onMenu: (at: { x: number, y: number }) => void
  /** Можно ли сейчас отвечать: в режиме выделения жест занят другим. */
  enabled?: () => boolean
}

/** Насколько надо утянуть пузырь, чтобы это считалось ответом. */
const REPLY_AT = 56

/** Дальше этого пузырь не едет: жест показывает намерение, а не двигает ленту. */
const MAX_PULL = 76

/** Сколько держать до меню. Меньше — срабатывает при обычном нажатии. */
const HOLD_MS = 450

/** Дрожание пальца — не движение: столько прощаем. */
const JITTER = 8

export function useBubbleGestures(options: BubbleGestureOptions) {
  /** На сколько пузырь утянут прямо сейчас — им же и смещается разметка. */
  const pull = ref(0)

  /** Дотянули ли до порога: на этом месте меняется знак и отзывается телефон. */
  const willReply = ref(false)

  let startX = 0
  let startY = 0
  let decided: 'none' | 'swipe' | 'scroll' = 'none'
  let holding = 0
  let buzzed = false

  function allowed(): boolean {
    return options.enabled?.() ?? true
  }

  function onTouchStart(event: TouchEvent): void {
    const touch = event.touches[0]

    if (!touch || event.touches.length > 1) {
      return
    }

    startX = touch.clientX
    startY = touch.clientY
    decided = 'none'
    buzzed = false

    holding = window.setTimeout(() => {
      holding = 0

      if (decided === 'none' && allowed()) {
        buzz()
        options.onMenu({ x: startX, y: startY })
      }
    }, HOLD_MS)
  }

  function onTouchMove(event: TouchEvent): void {
    const touch = event.touches[0]

    if (!touch) {
      return
    }

    const dx = touch.clientX - startX
    const dy = touch.clientY - startY

    if (decided === 'none') {
      if (Math.abs(dx) < JITTER && Math.abs(dy) < JITTER) {
        return
      }

      // Первое же осмысленное движение отменяет долгое нажатие: человек
      // прокручивает или выделяет, а не вызывает меню.
      stopHolding()

      // Горизонталь считается жестом, только если она заметно больше вертикали:
      // иначе диагональная прокрутка утаскивала бы пузыри вбок.
      decided = Math.abs(dx) > Math.abs(dy) * 1.5 ? 'swipe' : 'scroll'
    }

    if (decided !== 'swipe' || !allowed()) {
      return
    }

    // Только вправо: влево в лентах занято другим, и тянуть в обе стороны ради
    // одного действия незачем.
    const drawn = Math.max(0, Math.min(dx, MAX_PULL))

    // Сопротивление у края: последние двадцать точек идут вдвое туже, и палец
    // чувствует, что дальше некуда.
    pull.value = drawn > REPLY_AT ? REPLY_AT + (drawn - REPLY_AT) / 2 : drawn

    const reached = drawn >= REPLY_AT

    if (reached && !buzzed) {
      buzz()
      buzzed = true
    }

    willReply.value = reached
  }

  function onTouchEnd(): void {
    stopHolding()

    if (willReply.value) {
      options.onReply()
    }

    decided = 'none'
    pull.value = 0
    willReply.value = false
  }

  /** Правая кнопка — то же меню, что и долгое нажатие. На столе так привычнее. */
  function onContextMenu(event: MouseEvent): void {
    if (!allowed()) {
      return
    }

    event.preventDefault()
    options.onMenu({ x: event.clientX, y: event.clientY })
  }

  function stopHolding(): void {
    if (holding) {
      window.clearTimeout(holding)
      holding = 0
    }
  }

  /**
   * Короткий отклик телефона на пройденный порог.
   *
   * Это единственный способ сказать «дотянул», не отнимая у человека взгляд от
   * того места, куда он смотрит. Там, где вибрации нет, ничего и не произойдёт.
   */
  function buzz(): void {
    if (import.meta.client && typeof navigator.vibrate === 'function') {
      navigator.vibrate(8)
    }
  }

  onScopeDispose(stopHolding)

  /*
   * Ключи — чистые имена событий, без приставки `on`.
   *
   * `v-on="handlers"` сам приставляет её каждому ключу: назови их `onTouchstart`
   * — и в разметку уедет `onOnTouchstart`, обработчик не подпишется, а ошибки не
   * будет. Именно так меню по правой кнопке однажды и перестало открываться.
   */
  return {
    pull,
    willReply,
    handlers: {
      touchstart: onTouchStart,
      touchmove: onTouchMove,
      touchend: onTouchEnd,
      touchcancel: onTouchEnd,
      contextmenu: onContextMenu,
    },
  }
}
