import type { ResourceResponse } from '~/types/auth'
import type { CoursePerson, MaterialSection } from '~/types/lms'
import { toValidationError } from '~/composables/useAuth'

/**
 * С какого материала пишут: курс, урок, документ или справочник.
 *
 * Урок опознаётся номером, остальное — адресом страницы: так материал и лежит
 * в маршрутах API, и переводить одно в другое на месте вызова незачем.
 */
export type AppealTarget =
  | { kind: 'course', slug: string }
  | { kind: 'lesson', id: number }
  | { kind: 'material', section: MaterialSection, slug: string }

export interface AppealPayload {
  recipient_id: number
  reason: 'missing' | 'incorrect'
  body: string
}

/**
 * «Ответа не хватило» и «здесь написано неверно».
 *
 * Замечание уходит личным сообщением тому, кто материал правит, — отдельного
 * ящика для них нет: у автора уже есть место, куда ему пишут коллеги.
 */
export function useAppealApi() {
  const { $api } = useNuxtApp()

  function base(target: AppealTarget): string {
    if (target.kind === 'course') {
      return `/api/lms/courses/${target.slug}/appeal`
    }

    if (target.kind === 'lesson') {
      return `/api/lms/lessons/${target.id}/appeal`
    }

    // Документы и справочники живут в своих разделах, и адрес API — тот же
    // раздел, что и адрес страницы (см. useMaterialsApi).
    return `/api/lms/${target.section}/${target.slug}/appeal`
  }

  return {
    /** Кому можно написать: автор материала и назначенные ответственные. */
    recipients: (target: AppealTarget): Promise<ResourceResponse<CoursePerson[]>> =>
      $api<ResourceResponse<CoursePerson[]>>(`${base(target)}/recipients`),

    send: (target: AppealTarget, payload: AppealPayload): Promise<ResourceResponse<{ conversation_id: number }>> =>
      $api<ResourceResponse<{ conversation_id: number }>>(base(target), { method: 'POST', body: payload })
        .catch(toValidationError),
  }
}
