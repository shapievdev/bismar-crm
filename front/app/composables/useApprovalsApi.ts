import type { ResourceResponse } from '~/types/auth'
import type { CoursePerson, MaterialReview, MaterialSection } from '~/types/lms'

/** Что показывает значок раздела «Согласование». */
export interface ApprovalCounts {
  /** Ждут вашего ответа. */
  pending: number
  /** Вернулись к вам на исправление. */
  returned: number
  /** Звали согласовать или сами отправляли — иначе раздела нет. */
  is_approver: boolean
}

/**
 * Материал, который отправляют на согласование: документ, справочник или курс.
 *
 * Адреса у них разные, а согласование одно и то же — поэтому панель знает не про
 * разделы, а про эту цель. Тот же приём, что у версий (см. VersionsTarget).
 */
export type ApprovalTarget =
  | { kind: MaterialSection, slug: string }
  | { kind: 'course', slug: string }

/**
 * Согласование материала: отправить, отозвать, решить.
 *
 * Отправка живёт при материале (право на неё — право на правку), а решения — в
 * своей очереди: доступ к ним даёт не роль, а назначение, см. ApprovalController.
 */
export function useApprovalsApi() {
  const { $api } = useNuxtApp()

  /** Куда обращаться за согласованием этого материала. */
  function base(target: ApprovalTarget): string {
    return target.kind === 'course'
      ? `/api/lms/courses/${target.slug}/approval`
      : `/api/lms/${target.kind}/${target.slug}/approval`
  }

  function submit(target: ApprovalTarget, approvers: number[]): Promise<ResourceResponse<MaterialReview>> {
    return $api<ResourceResponse<MaterialReview>>(base(target), {
      method: 'POST',
      body: { approvers },
    }).catch(toValidationError)
  }

  function withdraw(target: ApprovalTarget): Promise<void> {
    return $api(base(target), { method: 'DELETE' })
  }

  /** Кого можно позвать: любой работающий сотрудник. */
  function candidates(search: string): Promise<ResourceResponse<CoursePerson[]>> {
    return $api<ResourceResponse<CoursePerson[]>>('/api/lms/approvals/candidates', {
      query: { search: search || undefined },
    })
  }

  /* ---------- Очередь согласующего ---------- */

  function queue(): Promise<ResourceResponse<MaterialReview[]>> {
    return $api<ResourceResponse<MaterialReview[]>>('/api/lms/approvals')
  }

  /**
   * Что стало с тем, что отправлял я: кого ещё ждём и что просили исправить.
   *
   * Вторая половина раздела — про ответ проверяющего. Уведомление на телефон
   * могло не дойти или замениться следующим, а вернувшаяся работа должна
   * числиться за автором.
   */
  function mine(): Promise<ResourceResponse<MaterialReview[]>> {
    return $api<ResourceResponse<MaterialReview[]>>('/api/lms/approvals/mine')
  }

  /**
   * Для значка в полосе разделов: сколько ждёт вашего ответа, сколько вернулось
   * к вам на исправление и стоит ли вообще показывать раздел.
   */
  function pendingCount(): Promise<ResourceResponse<ApprovalCounts>> {
    return $api<ResourceResponse<ApprovalCounts>>('/api/lms/approvals/pending-count')
  }

  function approve(reviewId: number): Promise<ResourceResponse<MaterialReview>> {
    return $api<ResourceResponse<MaterialReview>>(`/api/lms/approvals/${reviewId}/approve`, { method: 'POST' })
  }

  /** Вернуть автору: причина обязательна — без неё он не знает, что исправить. */
  function returnForRevision(reviewId: number, comment: string): Promise<ResourceResponse<MaterialReview>> {
    return $api<ResourceResponse<MaterialReview>>(`/api/lms/approvals/${reviewId}/return`, {
      method: 'POST',
      body: { comment },
    }).catch(toValidationError)
  }

  return { submit, withdraw, candidates, queue, mine, pendingCount, approve, returnForRevision }
}
