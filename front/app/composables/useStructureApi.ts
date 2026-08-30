import type { ResourceResponse } from '~/types/auth'
import type { Department, DepartmentPerson, DepartmentRoleKind } from '~/types/structure'

/**
 * Структура компании: дерево отделов и люди в них.
 *
 * Читают её все, кто вошёл; правит администратор — сервер проверяет это сам
 * (EnsureAdministrator), а экран лишь не предлагает того, чего нельзя.
 */
export function useStructureApi() {
  const { $api } = useNuxtApp()

  return {
    /** Всё дерево разом: отделов десятки, и по уровням его тянуть незачем. */
    fetchStructure: (): Promise<ResourceResponse<Department[]>> =>
      $api<ResourceResponse<Department[]>>('/api/structure'),

    createDepartment: (body: { name: string, parent_id: number }): Promise<ResourceResponse<Department>> =>
      $api<ResourceResponse<Department>>('/api/structure/departments', { method: 'POST', body })
        .catch(toValidationError),

    renameDepartment: (id: number, name: string): Promise<ResourceResponse<Department>> =>
      $api<ResourceResponse<Department>>(`/api/structure/departments/${id}`, {
        method: 'PUT',
        body: { name },
      }).catch(toValidationError),

    /**
     * Перенос карточки. В ответ приходит всё дерево: перенос переставляет
     * номера у соседей и меняет счётчики у обоих родителей.
     */
    moveDepartment: (
      id: number,
      body: { parent_id: number, position: number },
    ): Promise<ResourceResponse<Department[]>> =>
      $api<ResourceResponse<Department[]>>(`/api/structure/departments/${id}/position`, {
        method: 'PUT',
        body,
      }),

    deleteDepartment: (id: number): Promise<void> =>
      $api(`/api/structure/departments/${id}`, { method: 'DELETE' }),

    /** Состав отдела — с поиском по имени и должности. */
    fetchDepartmentPeople: (id: number, search = ''): Promise<ResourceResponse<DepartmentPerson[]>> =>
      $api<ResourceResponse<DepartmentPerson[]>>(`/api/structure/departments/${id}/people`, {
        query: { search },
      }),

    /**
     * Добавляет людей в отдел. `from_department_id` превращает добавление в
     * перенос: человека забирают из прежнего отдела той же операцией, чтобы он
     * не потерялся между ними.
     */
    addDepartmentPeople: (
      id: number,
      body: { user_ids: number[], role: DepartmentRoleKind, from_department_id?: number },
    ): Promise<ResourceResponse<DepartmentPerson[]>> =>
      $api<ResourceResponse<DepartmentPerson[]>>(`/api/structure/departments/${id}/people`, {
        method: 'POST',
        body,
      }).catch(toValidationError),

    changeDepartmentRole: (
      id: number,
      userId: number,
      role: DepartmentRoleKind,
    ): Promise<ResourceResponse<DepartmentPerson[]>> =>
      $api<ResourceResponse<DepartmentPerson[]>>(`/api/structure/departments/${id}/people/${userId}`, {
        method: 'PUT',
        body: { role },
      }),

    removeDepartmentPerson: (id: number, userId: number): Promise<ResourceResponse<DepartmentPerson[]>> =>
      $api<ResourceResponse<DepartmentPerson[]>>(`/api/structure/departments/${id}/people/${userId}`, {
        method: 'DELETE',
      }),

    /** Кого можно добавить: работающие сотрудники, поиском. */
    searchDepartmentCandidates: (search: string): Promise<ResourceResponse<DepartmentPerson[]>> =>
      $api<ResourceResponse<DepartmentPerson[]>>('/api/structure/people', { query: { search } }),
  }
}
