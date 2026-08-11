<script setup lang="ts">
defineProps<{
  id: string
  label: string
  type?: string
  autocomplete?: string
  placeholder?: string
  errors?: string[]
}>()

const model = defineModel<string>({ required: true })
</script>

<template>
  <div class="field">
    <label :for="id">{{ label }}</label>

    <input
      :id="id"
      v-model="model"
      :type="type ?? 'text'"
      :autocomplete="autocomplete"
      :placeholder="placeholder"
      :aria-invalid="Boolean(errors?.length)"
      :aria-describedby="errors?.length ? `${id}-error` : undefined"
    >

    <p v-if="errors?.length" :id="`${id}-error`" class="field__error">
      {{ errors[0] }}
    </p>
  </div>
</template>

<style scoped>
.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.field label {
  font-size: 0.875rem;
  font-weight: 500;
}

.field input {
  padding: 0.55rem 0.7rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface);
  color: var(--color-text);
  font: inherit;
}

.field input:focus-visible {
  outline: 2px solid var(--color-accent);
  outline-offset: 1px;
}

.field input[aria-invalid='true'] {
  border-color: var(--color-danger);
}

.field__error {
  margin: 0;
  color: var(--color-danger);
  font-size: 0.825rem;
}
</style>