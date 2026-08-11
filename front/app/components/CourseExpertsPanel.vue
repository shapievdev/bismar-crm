<script setup lang="ts">
import type { CoursePerson } from '~/types/lms'

const props = defineProps<{ slug: string }>()

const { fetchCourseExperts, updateCourseExperts, searchExpertCandidates } = useLmsApi()

/**
 * Кто отвечает за курс.
 *
 * Не доступ и не авторство: это те, к кому идут с вопросом, на который материал
 * не ответил. Их видит всякий, кто курс открыл, и их же называет консультант,
 * когда ответа в базе не нашлось.
 *
 * Правки применяются сразу, как и в списке доступа, и по той же причине.
 */
const experts = ref<CoursePerson[]>([])
const isLoading = ref(true)
const isSaving = ref(false)
const errorMessage = ref<string | null>(null)

onMounted(async () => {
  try {
    experts.value = (await fetchCourseExperts(props.slug)).data
  }
  catch {
    errorMessage.value = 'Не удалось загрузить список ответственных.'
  }
  finally {
    isLoading.value = false
  }
})

async function save(next: CoursePerson[]) {
  const previous = experts.value

  experts.value = next
  isSaving.value = true
  errorMessage.value = null

  try {
    experts.value = (await updateCourseExperts(props.slug, next.map(person => person.id))).data
  }
  catch {
    experts.value = previous
    errorMessage.value = 'Не удалось изменить список ответственных.'
  }
  finally {
    isSaving.value = false
  }
}
</script>

<template>
  <CoursePeoplePanel
    title="Ответственные за курс"
    note="Их видно в курсе, и на них ссылается консультант, когда ответа в материалах не нашлось."
    :people="experts"
    :is-loading="isLoading"
    :is-saving="isSaving"
    :error-message="errorMessage"
    empty-note="Никто не назначен — спросить о курсе будет некого."
    add-label="Назначить ответственного"
    not-found-note="Никого не нашли. Возможно, этот человек уже назначен."
    :search="async (term: string) => (await searchExpertCandidates(slug, term)).data"
    @add="person => save([...experts, person])"
    @remove="person => save(experts.filter(one => one.id !== person.id))"
  />
</template>
