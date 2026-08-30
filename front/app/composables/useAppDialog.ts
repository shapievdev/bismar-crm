/**
 * Вопросы к человеку — своим окном, а не браузерным.
 *
 * `window.confirm` и `window.prompt` рисует браузер: чужие шрифты, чужие
 * кнопки, заголовок «Подтвердите действие на localhost:3100» и никакого места
 * для объяснения, что именно произойдёт. Здесь то же самое, но нашим окном.
 *
 * Состояние одно на приложение: два вопроса разом не задают, а окно висит в
 * оболочке (AppDialog в layouts/default.vue), и его не приходится заводить на
 * каждом экране.
 */
export interface DialogRequest {
  kind: 'confirm' | 'prompt'
  title: string
  /** Пояснение под заголовком: чем это кончится, если согласиться. */
  message?: string
  /** Подпись поля — только у вопроса с вводом. */
  label?: string
  value: string
  placeholder?: string
  confirmLabel: string
  cancelLabel: string
  /** Красная кнопка: то, что не отменить. */
  danger: boolean
  resolve: (answer: string | null) => void
}

export interface ConfirmOptions {
  title: string
  message?: string
  confirmLabel?: string
  cancelLabel?: string
  danger?: boolean
}

export interface PromptOptions {
  title: string
  message?: string
  label?: string
  value?: string
  placeholder?: string
  confirmLabel?: string
  cancelLabel?: string
}

export function useAppDialog() {
  const request = useState<DialogRequest | null>('app.dialog', () => null)

  function ask(dialog: Omit<DialogRequest, 'resolve'>): Promise<string | null> {
    return new Promise((resolve) => {
      request.value = { ...dialog, resolve }
    })
  }

  return {
    request,

    /** «Да» или «нет». Отмена — это «нет», как и закрытие окна. */
    async confirm(options: ConfirmOptions): Promise<boolean> {
      const answer = await ask({
        kind: 'confirm',
        title: options.title,
        message: options.message,
        value: '',
        confirmLabel: options.confirmLabel ?? 'Продолжить',
        cancelLabel: options.cancelLabel ?? 'Отмена',
        danger: options.danger ?? false,
      })

      return answer !== null
    },

    /**
     * Строка или ничего. Пустая строка — это тоже «ничего»: назвать отдел
     * пустотой нельзя, и отличать одно от другого на месте вызова незачем.
     */
    async prompt(options: PromptOptions): Promise<string | null> {
      const answer = await ask({
        kind: 'prompt',
        title: options.title,
        message: options.message,
        label: options.label,
        value: options.value ?? '',
        placeholder: options.placeholder,
        confirmLabel: options.confirmLabel ?? 'Сохранить',
        cancelLabel: options.cancelLabel ?? 'Отмена',
        danger: false,
      })

      const trimmed = answer?.trim() ?? ''

      return trimmed === '' ? null : trimmed
    },

    /** Ответ окна: строка для ввода, пустая строка для согласия, null — отказ. */
    settle(answer: string | null) {
      const pending = request.value

      request.value = null
      pending?.resolve(answer)
    },
  }
}
