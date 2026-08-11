<script setup lang="ts">
import type { SelectOption } from '~/components/ui/Select.vue'
import type { ValidationErrors } from '~/composables/useAuth'
import type { Category, CoursePayload, CourseStatus, CourseVisibility, StatusOption } from '~/types/lms'

const props = defineProps<{
  statuses: StatusOption[]
  categories: Category[]
  errors: ValidationErrors
  isSubmitting: boolean
  submitLabel: string
  /**
   * Может ли этот человек закрыть курс. Решает это автор — у нового курса им
   * становится тот, кто его заводит, поэтому на создании выбор есть всегда.
   */
  canManageAccess: boolean
}>()

const emit = defineEmits<{ submit: [payload: CoursePayload] }>()

const model = defineModel<CoursePayload>({ required: true })

// Publishing is a separate permission on the server; offering it to someone who
// lacks it would only produce a validation error.
const { can } = useAuth()

const statusOptions = computed<SelectOption<CourseStatus>[]>(() =>
  props.statuses
    .filter(status => status.value !== 'published' || can('courses.publish'))
    .map(status => ({ value: status.value, label: status.label })),
)

/**
 * Подпись под каждым вариантом обязательна: «открытый» и «приватный» — слова
 * знакомые, а вот кому именно виден приватный курс, из них не следует.
 */
const visibilityOptions: SelectOption<CourseVisibility>[] = [
  { value: 'public', label: 'Открытый', hint: 'Виден всем, кто может читать базу знаний' },
  { value: 'private', label: 'Приватный', hint: 'Виден автору и тем, кого он добавил' },
]
</script>

<template>
  <form class="course-form" novalidate @submit.prevent="emit('submit', model)">
    <FormField id="title" v-model="model.title" label="Название" :errors="errors.title" />

    <div class="field">
      <label for="summary">Краткое описание</label>
      <textarea id="summary" v-model="model.summary" rows="2" maxlength="500" />
      <p v-if="errors.summary?.length" class="field__error">
        {{ errors.summary[0] }}
      </p>
    </div>

    <div class="field">
      <label for="description">Описание</label>
      <textarea id="description" v-model="model.description" rows="8" />
      <p v-if="errors.description?.length" class="field__error">
        {{ errors.description[0] }}
      </p>
    </div>

    <div class="field">
      <label for="category">Категория</label>
      <CategoryTreeSelect id="category" v-model="model.category_id" :categories="categories" />
      <p v-if="errors.category_id?.length" class="field__error">
        {{ errors.category_id[0] }}
      </p>
    </div>

    <div class="field">
      <label for="status">Статус</label>
      <UiSelect id="status" v-model="model.status" :options="statusOptions" />
      <p v-if="errors.status?.length" class="field__error">
        {{ errors.status[0] }}
      </p>
    </div>

    <div v-if="canManageAccess" class="field">
      <label for="visibility">Доступ</label>
      <UiSelect id="visibility" v-model="model.visibility" :options="visibilityOptions" />
      <p v-if="model.visibility === 'private'" class="field__hint">
        Кого пускать в курс — ниже, под формой.
      </p>
      <p v-if="errors.visibility?.length" class="field__error">
        {{ errors.visibility[0] }}
      </p>
    </div>

    <div class="actions">
      <button type="submit" class="button-primary" :disabled="isSubmitting">
        {{ isSubmitting ? 'Сохраняем…' : submitLabel }}
      </button>
      <slot name="secondary-actions" />
    </div>
  </form>
</template>

<style scoped>
.course-form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  max-width: 42rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.field label {
  font-size: 0.875rem;
  font-weight: 500;
}

.field textarea,
.field select {
  padding: 0.55rem 0.7rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface);
  color: var(--color-text);
  font: inherit;
  resize: vertical;
}

.field__error {
  margin: 0;
  color: var(--color-danger);
  font-size: 0.825rem;
}

.field__hint {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.825rem;
}

.actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}
</style>
