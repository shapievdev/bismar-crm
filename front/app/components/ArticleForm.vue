<script setup lang="ts">
import type { ValidationErrors } from '~/composables/useAuth'
import type { ArticlePayload, KnowledgeCategory, StatusOption } from '~/types/knowledge'

const props = defineProps<{
  categories: KnowledgeCategory[]
  statuses: StatusOption[]
  errors: ValidationErrors
  isSubmitting: boolean
  submitLabel: string
}>()

const emit = defineEmits<{ submit: [payload: ArticlePayload] }>()

const model = defineModel<ArticlePayload>({ required: true })

const { can } = useAuth()

/**
 * Publishing is a separate permission on the server; offering the option to
 * someone who lacks it would only produce a validation error.
 */
const availableStatuses = computed(() =>
  props.statuses.filter(status => status.value !== 'published' || can('knowledge.publish')),
)
</script>

<template>
  <form class="article-form" novalidate @submit.prevent="emit('submit', model)">
    <FormField
      id="title"
      v-model="model.title"
      label="Заголовок"
      :errors="errors.title"
    />

    <div class="field">
      <label for="excerpt">Краткое описание</label>
      <textarea id="excerpt" v-model="model.excerpt" rows="2" maxlength="500" />
      <p v-if="errors.excerpt?.length" class="field__error">
        {{ errors.excerpt[0] }}
      </p>
    </div>

    <div class="row">
      <div class="field">
        <label for="category">Категория</label>
        <select id="category" v-model="model.category_id">
          <option :value="null">
            Без категории
          </option>
          <option v-for="item in categories" :key="item.id" :value="item.id">
            {{ item.name }}
          </option>
        </select>
        <p v-if="errors.category_id?.length" class="field__error">
          {{ errors.category_id[0] }}
        </p>
      </div>

      <div class="field">
        <label for="status">Статус</label>
        <select id="status" v-model="model.status">
          <option v-for="item in availableStatuses" :key="item.value" :value="item.value">
            {{ item.label }}
          </option>
        </select>
        <p v-if="errors.status?.length" class="field__error">
          {{ errors.status[0] }}
        </p>
      </div>
    </div>

    <div class="field">
      <label for="content">Текст статьи</label>
      <textarea id="content" v-model="model.content" rows="16" />
      <p v-if="errors.content?.length" class="field__error">
        {{ errors.content[0] }}
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
.article-form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  max-width: 46rem;
}

.row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
}

@media (max-width: 32rem) {
  .row {
    grid-template-columns: 1fr;
  }
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
