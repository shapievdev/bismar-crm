<script setup lang="ts">
import type { CoursePerson } from '~/types/lms'

const props = defineProps<{
  slug: string
  /** Открыт курс или закрыт: у открытого список ни на что не влияет. */
  isPrivate: boolean
  authorName: string | null
}>()

const { fetchCourseAccess, updateCourseAccess, searchAccessCandidates } = useLmsApi()

/**
 * Кто допущен к курсу.
 *
 * Правки применяются сразу, без отдельного «сохранить». Доступ — не черновик:
 * человек, которого убрали из списка на экране, должен потерять курс тогда же,
 * а не когда о кнопке вспомнят. Цена — что каждая правка это запрос; их здесь
 * единицы за сеанс.
 */
const members = ref<CoursePerson[]>([])
const isLoading = ref(true)
const isSaving = ref(false)
const errorMessage = ref<string | null>(null)

onMounted(async () => {
  try {
    members.value = (await fetchCourseAccess(props.slug)).data
  }
  catch {
    errorMessage.value = 'Не удалось загрузить список доступа.'
  }
  finally {
    isLoading.value = false
  }
})

async function save(next: CoursePerson[]) {
  const previous = members.value

  // Показываем сразу, откатываем при отказе: список короткий, и ожидание
  // ответа на каждое нажатие читалось бы как «не сработало».
  members.value = next
  isSaving.value = true
  errorMessage.value = null

  try {
    members.value = (await updateCourseAccess(props.slug, next.map(person => person.id))).data
  }
  catch {
    members.value = previous
    errorMessage.value = 'Не удалось изменить доступ.'
  }
  finally {
    isSaving.value = false
  }
}
</script>

<template>
  <CoursePeoplePanel
    title="Доступ к курсу"
    :note="isPrivate
      ? null
      : 'Курс открыт всем, кто может читать базу знаний. Список пригодится, когда вы сделаете его приватным.'"
    :people="members"
    :is-loading="isLoading"
    :is-saving="isSaving"
    :error-message="errorMessage"
    :fixed-name="authorName"
    fixed-badge="Автор"
    empty-note="Кроме автора — никого."
    add-label="Добавить сотрудника"
    not-found-note="Никого не нашли. Возможно, доступ у этого человека уже есть."
    :search="async (term: string) => (await searchAccessCandidates(slug, term)).data"
    @add="person => save([...members, person])"
    @remove="person => save(members.filter(one => one.id !== person.id))"
  />
</template>
