import type { DriveFile } from '~/composables/useGoogleDrive'
import type { SurveyOwner } from '~/composables/useSurveyApi'
import type { ResourceResponse } from '~/types/auth'
import type {
  LessonAttachment,
  MaterialSection,
  MaterialVersion,
  MaterialVersionPayload,
  MaterialVersionSummary,
  Quiz,
  QuizPayload,
} from '~/types/lms'
import type { UploadOptions } from '~/utils/upload'

/**
 * Материал, у которого ведут версии: документ, справочник или урок курса.
 *
 * У них разные адреса (документ адресуется слагом, урок — номером внутри
 * курса) и разные слова на экране, а правила версий одни и те же. Поэтому
 * панель версий знает не про разделы, а про эту цель.
 */
export type VersionsTarget =
  | { kind: MaterialSection, slug: string }
  | { kind: 'lesson', lessonId: number | string, courseSlug: string }

/**
 * Ведение версий одним набором действий, каким бы материал ни был.
 *
 * Заведён ради урока (2026-09-25): панель версий, написанная для документов,
 * годилась для него целиком — кроме того, куда ходить и как назвать материал
 * словом. Второй такой панели заводить не стали.
 */
export function useVersionsApi(target: VersionsTarget) {
  const documents = target.kind === 'lesson' ? null : useMaterialsApi(target.kind)
  const lessons = target.kind === 'lesson' ? useLmsApi() : null

  /** Как материал называется в тексте: «урок», «документ», «справочник». */
  const materialLabel = target.kind === 'lesson'
    ? 'урок'
    : useMaterialSection(target.kind).materialLabel.toLowerCase()

  function list(): Promise<ResourceResponse<MaterialVersionSummary[]>> {
    return target.kind === 'lesson'
      ? lessons!.fetchVersions(target.lessonId)
      : documents!.fetchVersions(target.slug)
  }

  function read(versionId: number): Promise<ResourceResponse<MaterialVersion>> {
    return target.kind === 'lesson'
      ? lessons!.fetchVersion(target.lessonId, versionId)
      : documents!.fetchVersion(target.slug, versionId)
  }

  function create(payload: MaterialVersionPayload): Promise<ResourceResponse<MaterialVersionSummary>> {
    return target.kind === 'lesson'
      ? lessons!.createVersion(target.lessonId, payload)
      : documents!.createVersion(target.slug, payload)
  }

  function update(versionId: number, payload: MaterialVersionPayload): Promise<ResourceResponse<MaterialVersionSummary>> {
    return target.kind === 'lesson'
      ? lessons!.updateVersion(target.lessonId, versionId, payload)
      : documents!.updateVersion(target.slug, versionId, payload)
  }

  function reorder(versions: number[]): Promise<ResourceResponse<MaterialVersionSummary[]>> {
    return target.kind === 'lesson'
      ? lessons!.reorderVersions(target.lessonId, versions)
      : documents!.reorderVersions(target.slug, versions)
  }

  function remove(versionId: number): Promise<void> {
    return target.kind === 'lesson'
      ? lessons!.deleteVersion(target.lessonId, versionId)
      : documents!.deleteVersion(target.slug, versionId)
  }

  /** Куда ведёт кнопка «Править» — на экран тела версии. */
  function editPath(versionId: number): string {
    return target.kind === 'lesson'
      ? `/lms/${target.courseSlug}/lessons/${target.lessonId}/versions/${versionId}`
      : `/lms/${target.kind}/${target.slug}/versions/${versionId}`
  }

  /** Куда вернуться, сохранив версию, — на правку самого материала. */
  function materialPath(): string {
    return target.kind === 'lesson'
      ? `/lms/${target.courseSlug}/lessons/${target.lessonId}/edit`
      : `/lms/${target.kind}/${target.slug}/edit`
  }

  /* ---------- Тело версии: проверка, опрос, файлы ---------- */

  function saveQuiz(versionId: number, payload: QuizPayload): Promise<ResourceResponse<Quiz>> {
    return target.kind === 'lesson'
      ? lessons!.saveVersionQuiz(target.lessonId, versionId, payload)
      : documents!.saveVersionQuiz(target.slug, versionId, payload)
  }

  function deleteQuiz(versionId: number): Promise<void> {
    return target.kind === 'lesson'
      ? lessons!.deleteVersionQuiz(target.lessonId, versionId)
      : documents!.deleteVersionQuiz(target.slug, versionId)
  }

  function uploadAttachment(
    versionId: number,
    file: File,
    description: string | null,
    options: UploadOptions = {},
  ): Promise<ResourceResponse<LessonAttachment>> {
    return target.kind === 'lesson'
      ? lessons!.uploadVersionAttachment(target.lessonId, versionId, file, description, options)
      : documents!.uploadVersionAttachment(target.slug, versionId, file, description, options)
  }

  function attachDriveFile(versionId: number, file: DriveFile): Promise<ResourceResponse<LessonAttachment>> {
    return target.kind === 'lesson'
      ? lessons!.attachVersionDriveFile(target.lessonId, versionId, file)
      : documents!.attachVersionDriveFile(target.slug, versionId, file)
  }

  /**
   * Подпись и удаление файла идут общими адресами материала — файл при нём и
   * лежит, — а вот адреса эти у урока и документа разные.
   */
  function renameAttachment(attachmentId: number, description: string | null): Promise<unknown> {
    return target.kind === 'lesson'
      ? lessons!.updateAttachment(attachmentId, description)
      : documents!.updateAttachment(target.slug, attachmentId, description)
  }

  function removeAttachment(attachmentId: number): Promise<unknown> {
    return target.kind === 'lesson'
      ? lessons!.deleteAttachment(attachmentId)
      : documents!.deleteAttachment(target.slug, attachmentId)
  }

  /** Запись версии урока: загрузить файлом или снять. У документа её нет. */
  function uploadVideo(versionId: number, file: File, options: UploadOptions = {}): Promise<unknown> {
    if (target.kind !== 'lesson') {
      throw new Error('Запись бывает только у версии урока.')
    }

    const body = new FormData()

    body.append('video', file)

    return useNuxtApp().$upload(
      `/api/lms/lessons/${target.lessonId}/versions/${versionId}/video`,
      body,
      options,
    )
  }

  function removeVideo(versionId: number): Promise<unknown> {
    if (target.kind !== 'lesson') {
      throw new Error('Запись бывает только у версии урока.')
    }

    return useNuxtApp().$api(`/api/lms/lessons/${target.lessonId}/versions/${versionId}/video`, {
      method: 'DELETE',
    })
  }

  /**
   * Кто владеет опросом этой версии — описанием, а не адресом: адреса собирает
   * useSurveyApi, и второй их сборки заводить незачем.
   */
  function surveyOwner(versionId: number): SurveyOwner {
    return target.kind === 'lesson'
      ? { kind: 'lesson-version', lessonId: target.lessonId, versionId }
      : { kind: 'version', section: target.kind, slug: target.slug, versionId }
  }

  return {
    materialLabel,
    list,
    read,
    create,
    update,
    reorder,
    remove,
    editPath,
    materialPath,
    saveQuiz,
    deleteQuiz,
    uploadAttachment,
    attachDriveFile,
    renameAttachment,
    removeAttachment,
    uploadVideo,
    removeVideo,
    surveyOwner,
  }
}
