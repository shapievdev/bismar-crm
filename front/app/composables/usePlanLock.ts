import type { LearningPlanItem } from '~/types/lms'

/**
 * Почему материал закрыт — одним ответом на все экраны, где по нему нажимают.
 *
 * Курс или документ, до которого не дошла очередь плана обучения, выглядит в
 * списках как все остальные, и молчание в ответ на нажатие читается как
 * поломка. Окно называет шаг, на котором человек стоит, и предлагает открыть
 * план — иначе «завершите план обучения» отправляет искать его самому.
 *
 * Шаг спрашивается при первом нажатии и запоминается на время экрана: у того,
 * кто план прошёл, замков нет вовсе, и грузить план всем подряд ради подсказки
 * незачем.
 */
export function usePlanLock() {
  const { myPlan } = useLmsApi()
  const { confirm } = useAppDialog()

  /** `undefined` — ещё не спрашивали, `null` — спросили, и шага нет. */
  const step = ref<LearningPlanItem | null | undefined>(undefined)

  async function currentStep(): Promise<LearningPlanItem | null> {
    if (step.value === undefined) {
      try {
        step.value = (await myPlan()).data.find(item => !item.is_completed) ?? null
      }
      catch {
        // План не загрузился — объясним без имени шага, оно здесь не главное.
        step.value = null
      }
    }

    return step.value
  }

  return {
    /**
     * @param label как называется закрытое: «Курс», «Документ», «Справочник».
     *   Разделы разные, и «курс закрыт» на карточке справочника читалось бы
     *   ошибкой.
     */
    async explain(label = 'Курс') {
      const next = await currentStep()

      const message = next === null
        ? `${label} откроется, когда вы завершите план обучения.`
        : `Сначала ${next.kind === 'course' ? `пройдите курс «${next.title}»` : `прочитайте «${next.title}»`} `
          + '— это ваш шаг в плане обучения. Остальное откроется, когда план будет завершён.'

      const toPlan = await confirm({
        title: `${label} пока закрыт`,
        message,
        confirmLabel: 'Мой план',
        cancelLabel: 'Понятно',
      })

      if (toPlan) {
        await navigateTo('/lms/plan')
      }
    },
  }
}
