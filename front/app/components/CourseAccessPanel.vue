<script setup lang="ts">
import type { CoursePerson, MaterialAccess } from '~/types/lms'
import type { Group } from '~/types/structure'

/**
 * Ставится только у приватного курса — решает это страница: у открытого список
 * ни на что не влияет, и панели там нет вовсе (2026-09-12).
 */
const props = defineProps<{
  slug: string
  authorName: string | null
}>()

const { fetchCourseAccess, updateCourseAccess, searchAccessCandidates } = useLmsApi()

/**
 * Кто допущен к курсу — поимённо и группами (2026-09-12).
 *
 * Правки применяются сразу, без отдельного «сохранить». Доступ — не черновик:
 * человек, которого убрали из списка на экране, должен потерять курс тогда же,
 * а не когда о кнопке вспомнят. Цена — что каждая правка это запрос; их здесь
 * единицы за сеанс.
 *
 * Оба списка уходят вместе: сервер задаёт доступ целиком, и прислать один без
 * другого значило бы стереть второй.
 */
const access = ref<MaterialAccess>({ people: [], groups: [] })
const isLoading = ref(true)
const isSaving = ref(false)
const errorMessage = ref<string | null>(null)

onMounted(async () => {
  try {
    access.value = (await fetchCourseAccess(props.slug)).data
  }
  catch {
    errorMessage.value = 'Не удалось загрузить список доступа.'
  }
  finally {
    isLoading.value = false
  }
})

async function save(next: MaterialAccess) {
  const previous = access.value

  // Показываем сразу, откатываем при отказе: список короткий, и ожидание
  // ответа на каждое нажатие читалось бы как «не сработало».
  access.value = next
  isSaving.value = true
  errorMessage.value = null

  try {
    access.value = (await updateCourseAccess(
      props.slug,
      next.people.map(person => person.id),
      next.groups.map(group => group.id),
    )).data
  }
  catch {
    access.value = previous
    errorMessage.value = 'Не удалось изменить доступ.'
  }
  finally {
    isSaving.value = false
  }
}

function withPeople(people: CoursePerson[]): MaterialAccess {
  return { people, groups: access.value.groups }
}

function withGroups(groups: Group[]): MaterialAccess {
  return { people: access.value.people, groups }
}

/**
 * Подсказка поиска: люди и группы приходят одним ответом.
 *
 * Панель спрашивает их порознь — ей о разделах ничего не известно, — но слово
 * одно, и сервер отвечает на него разом. Ответ на последнее слово держим при
 * себе, иначе на каждое нажатие клавиши уходило бы два одинаковых запроса.
 */
let lastTerm: string | null = null
let lastFound: Promise<MaterialAccess> | null = null

function candidates(term: string): Promise<MaterialAccess> {
  if (term !== lastTerm || lastFound === null) {
    lastTerm = term
    lastFound = searchAccessCandidates(props.slug, term).then(response => response.data)
  }

  return lastFound
}
</script>

<template>
  <CoursePeoplePanel
    title="Доступ к курсу"
    :people="access.people"
    :groups="access.groups"
    :is-loading="isLoading"
    :is-saving="isSaving"
    :error-message="errorMessage"
    :fixed-name="authorName"
    fixed-badge="Автор"
    empty-note="Кроме автора — никого."
    add-label="Добавить сотрудника или группу"
    search-placeholder="Фамилия, почта или название группы"
    not-found-note="Никого не нашли. Возможно, доступ уже есть."
    :search="async (term: string) => (await candidates(term)).people"
    :search-groups="async (term: string) => (await candidates(term)).groups"
    @add="person => save(withPeople([...access.people, person]))"
    @remove="person => save(withPeople(access.people.filter(one => one.id !== person.id)))"
    @add-group="group => save(withGroups([...access.groups, group]))"
    @remove-group="group => save(withGroups(access.groups.filter(one => one.id !== group.id)))"
  />
</template>
