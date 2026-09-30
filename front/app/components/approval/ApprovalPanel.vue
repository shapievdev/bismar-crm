<script setup lang="ts">
import type { ApprovalTarget } from '~/composables/useApprovalsApi'
import { messageFromError } from '~/composables/useAuth'
import type { CoursePerson, MaterialReview } from '~/types/lms'

/**
 * Согласование материала — со стороны того, кто его пишет.
 *
 * Автор либо выкладывает материал сам, либо показывает его сперва другим
 * (решение пользователя 2026-09-30). Здесь он выбирает, кого попросить, видит,
 * кто ответил, и читает причину возврата — то, за чем и приходит.
 *
 * Панель одна на документ, справочник и курс: согласование у них слово в слово
 * одно, а разное — адрес — знает useApprovalsApi.
 */
const props = defineProps<{
  target: ApprovalTarget
  /** Последний круг, каким его отдал сервер. Нет — материал не отправляли. */
  review?: MaterialReview | null
  /** Опубликован ли материал: от этого зависят слова, а не правила. */
  isPublished: boolean
}>()

const emit = defineEmits<{ changed: [review: MaterialReview | null] }>()

const api = useApprovalsApi()
const { confirm } = useAppDialog()

/**
 * Свой список: круг приезжает с материалом, а меняется здесь — и перезагружать
 * ради этого всю страницу незачем.
 */
const current = ref<MaterialReview | null>(props.review ?? null)

watch(() => props.review, value => (current.value = value ?? null))

const chosen = ref<CoursePerson[]>([])
const isSaving = ref(false)
const errorMessage = ref<string | null>(null)

const isOpen = computed(() => current.value?.is_open === true)
const wasReturned = computed(() => current.value?.status === 'returned')

/** Кто ещё не ответил — это и есть «чего ждём». */
const awaiting = computed(() =>
  (current.value?.decisions ?? []).filter(decision => decision.status === 'pending'),
)

const answered = computed(() =>
  (current.value?.decisions ?? []).filter(decision => decision.status !== 'pending'),
)

async function send() {
  if (chosen.value.length === 0) {
    errorMessage.value = 'Выберите, кто согласует материал.'

    return
  }

  isSaving.value = true
  errorMessage.value = null

  try {
    const { data } = await api.submit(props.target, chosen.value.map(person => person.id))

    current.value = data
    chosen.value = []
    emit('changed', data)
  }
  catch (caught) {
    errorMessage.value = messageFromError(caught, 'Не удалось отправить на согласование.')
  }
  finally {
    isSaving.value = false
  }
}

async function withdraw() {
  const agreed = await confirm({
    title: 'Отозвать отправку?',
    message: 'Согласующие получат уведомление, что их ответа больше не ждут.',
    confirmLabel: 'Отозвать',
    danger: true,
  })

  if (!agreed) {
    return
  }

  isSaving.value = true
  errorMessage.value = null

  try {
    await api.withdraw(props.target)

    // Круг закрыт: сервер отдал пустой ответ, и состояние проще собрать здесь,
    // чем просить страницу перечитаться целиком.
    current.value = current.value ? { ...current.value, status: 'cancelled', is_open: false, awaits_me: false } : null
    emit('changed', current.value)
  }
  catch (caught) {
    errorMessage.value = messageFromError(caught, 'Не удалось отозвать отправку.')
  }
  finally {
    isSaving.value = false
  }
}

function addPerson(person: CoursePerson) {
  if (!chosen.value.some(one => one.id === person.id)) {
    chosen.value = [...chosen.value, person]
  }
}

function removePerson(person: CoursePerson) {
  chosen.value = chosen.value.filter(one => one.id !== person.id)
}

function day(value: string | null): string {
  return value
    ? new Date(value).toLocaleString('ru-RU', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' })
    : ''
}
</script>

<template>
  <section class="card approval">
    <header class="approval__header">
      <h2 class="approval__title">
        Согласование
      </h2>
      <span v-if="current" class="badge" :class="{ 'badge--warning': isOpen, 'badge--success': current.status === 'approved' }">
        {{ current.status_label }}
      </span>
    </header>

    <p class="faint approval__note">
      <template v-if="isPublished">
        Материал уже опубликован. Отправьте правку на согласование, если её нужно
        с кем-то согласовать, — люди читают текущий текст.
      </template>
      <template v-else>
        Материал можно выложить самому или показать сперва другим: согласуют все —
        он опубликуется сам.
      </template>
    </p>

    <p v-if="errorMessage" class="alert alert--danger" role="alert">
      {{ errorMessage }}
    </p>

    <!-- Причина возврата — то, за чем автор сюда и пришёл. -->
    <div v-if="wasReturned && current?.returned_reason" class="returned">
      <p class="returned__title">
        Вернули на исправление
      </p>
      <p class="returned__reason">
        {{ current.returned_reason }}
      </p>
      <p class="faint returned__who">
        {{ answered.find(one => one.status === 'returned')?.user.name }} · {{ day(current.closed_at) }}
      </p>
    </div>

    <!-- Идущий круг: кто ответил и кого ждём. -->
    <template v-if="isOpen">
      <ul class="people">
        <li v-for="decision in awaiting" :key="decision.id" class="person">
          <span>{{ decision.user.name }}</span>
          <span class="faint">ждём ответа</span>
        </li>
        <li v-for="decision in answered" :key="decision.id" class="person">
          <span>{{ decision.user.name }}</span>
          <span class="faint">{{ decision.status_label }} · {{ day(decision.decided_at) }}</span>
        </li>
      </ul>

      <div class="approval__actions">
        <button type="button" class="button-ghost button-sm" :disabled="isSaving" @click="withdraw">
          Отозвать отправку
        </button>
      </div>
    </template>

    <!-- Круга нет или он закончился: можно отправить (снова). -->
    <template v-else>
      <CoursePeoplePanel
        title="Кто согласует"
        :note="current
          ? 'Отправите снова — согласуют заново все: текст изменился.'
          : 'Согласовать должны все выбранные. Пока идёт круг, они читают материал, даже если он закрытый.'"
        :people="chosen"
        :is-loading="false"
        :is-saving="isSaving"
        empty-note="Пока никого не выбрали."
        add-label="Добавить согласующего"
        search-placeholder="Фамилия или почта"
        not-found-note="Никого не нашли."
        :search="term => api.candidates(term).then(response => response.data)"
        @add="addPerson"
        @remove="removePerson"
      />

      <div class="approval__actions">
        <button type="button" class="button-primary button-sm" :disabled="isSaving || !chosen.length" @click="send">
          Отправить на согласование
        </button>
      </div>
    </template>
  </section>
</template>

<style scoped>
/*
 * Отступы свои, а не занятые у редактора: scoped-стили родителя достаются и
 * корню вложенного компонента, и панель, поставленная вне редактора, оставалась
 * бы без полей. Тот же подвох уже описан в MaterialVersionsPanel.
 */
.approval {
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
  padding: 1.1rem 1.25rem;
}

.approval__header {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.75rem;
}

.approval__title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 500;
}

.approval__note {
  margin: 0;
  font-size: 0.85rem;
}

.returned {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  padding: 0.7rem 0.85rem;
  border-left: 3px solid var(--color-danger);
  border-radius: var(--radius-sm);
  background: var(--control-surface);
}

.returned__title {
  margin: 0;
  font-size: 0.85rem;
  font-weight: 500;
  color: var(--color-danger);
}

.returned__reason {
  margin: 0;
  white-space: pre-line;
}

.returned__who {
  margin: 0;
  font-size: 0.8rem;
}

.people {
  display: flex;
  flex-direction: column;
  margin: 0;
  padding: 0;
  list-style: none;
}

.person {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.4rem 0;
  border-top: 1px solid var(--color-border-subtle, var(--color-border));
  font-size: 0.93rem;
}

.person:first-child {
  border-top: none;
}

.approval__actions {
  display: flex;
  gap: 0.5rem;
}
</style>
