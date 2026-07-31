<script setup lang="ts">
import type { ValidationErrors } from '~/composables/useAuth'
import type { CoursePayload, StatusOption } from '~/types/lms'

defineProps<{
  statuses: StatusOption[]
  errors: ValidationErrors
  isSubmitting: boolean
  submitLabel: string
}>()

const emit = defineEmits<{ submit: [payload: CoursePayload] }>()

const model = defineModel<CoursePayload>({ required: true })

// Publishing is a separate permission on the server; offering it to someone who
// lacks it would only produce a validation error.
const { can } = useAuth()
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
      <label for="status">Статус</label>
      <select id="status" v-model="model.status">
        <option
          v-for="item in statuses.filter(s => s.value !== 'published' || can('courses.publish'))"
          :key="item.value"
          :value="item.value"
        >
          {{ item.label }}
        </option>
      </select>
      <p v-if="errors.status?.length" class="field__error">
        {{ errors.status[0] }}
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

.actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}
</style>
