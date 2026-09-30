import { toValidationError } from '~/composables/useAuth'
import type { ResourceResponse } from '~/types/auth'
import type { MaterialSection } from '~/types/lms'
import type {
  Survey,
  SurveyAnswer,
  SurveyOutcome,
  SurveyPayload,
  SurveySummary,
} from '~/types/survey'

/**
 * При чём стоит опрос.
 *
 * Адреса у материалов свои — у курсов, документов, справочников и новостей свои
 * права, и стоят они на маршрутах, — а сам опрос один на всех. Поэтому владелец
 * приходит сюда описанием, а не готовым адресом: собрать адрес один раз здесь
 * надёжнее, чем в пяти экранах.
 */
export type SurveyOwner =
  // Строкой — потому что урок адресуют и параметром маршрута, как версию ниже:
  // дальше это число только подставляется в адрес.
  | { kind: 'lesson', id: number | string }
  | { kind: 'material', section: MaterialSection, slug: string }
  | { kind: 'version', section: MaterialSection, slug: string, versionId: number }
  | { kind: 'lesson-version', lessonId: number | string, versionId: number }
  | { kind: 'news', slug: string }

function baseOf(owner: SurveyOwner): string {
  switch (owner.kind) {
    case 'lesson':
      return `/api/lms/lessons/${owner.id}/survey`
    case 'material':
      return `/api/lms/${owner.section}/${owner.slug}/survey`
    case 'version':
      return `/api/lms/${owner.section}/${owner.slug}/versions/${owner.versionId}/survey`
    // Версия урока (2026-09-25): опрос у неё свой, как и у версии документа.
    case 'lesson-version':
      return `/api/lms/lessons/${owner.lessonId}/versions/${owner.versionId}/survey`
    case 'news':
      return `/api/news/${owner.slug}/survey`
  }
}

/**
 * Опрос при материале: завести, снять, пройти и прочитать сводку.
 *
 * Четыре обращения на все пять владельцев — в отличие от тестов, где у новости
 * своя половина API. Опрос полиморфен от самой схемы, и повторять ту развилку
 * незачем.
 */
export function useSurveyApi() {
  const { $api } = useNuxtApp()

  return {
    /** Завести или переписать целиком: редактор присылает опрос весь. */
    saveSurvey: (owner: SurveyOwner, payload: SurveyPayload): Promise<ResourceResponse<Survey>> =>
      $api<ResourceResponse<Survey>>(baseOf(owner), { method: 'PUT', body: payload })
        .catch(toValidationError),

    /** Снять. Ответы уходят вместе с опросом — предупредить обязан экран. */
    deleteSurvey: (owner: SurveyOwner) =>
      $api(baseOf(owner), { method: 'DELETE' }),

    /**
     * Пройти — один раз. Второй раз сервер ответит отказом, и это не ошибка
     * экрана, а правило опроса.
     */
    submitSurvey: (
      owner: SurveyOwner,
      answers: Record<number, SurveyAnswer>,
    ): Promise<ResourceResponse<SurveyOutcome>> =>
      $api<ResourceResponse<SurveyOutcome>>(`${baseOf(owner)}/submit`, {
        method: 'POST',
        body: { answers },
      }).catch(toValidationError),

    /** Сводка ответов — тому, кто ведёт материал. */
    fetchSurveySummary: (owner: SurveyOwner): Promise<ResourceResponse<SurveySummary>> =>
      $api<ResourceResponse<SurveySummary>>(`${baseOf(owner)}/summary`),
  }
}
