<script setup lang="ts">
import { applyMask } from '~/utils/maskedInput'

const props = defineProps<{
  id: string
  label: string
  type?: string
  autocomplete?: string
  inputmode?: 'text' | 'tel' | 'email' | 'numeric'
  placeholder?: string
  errors?: string[]
  /**
   * Маска: как показать набранное. Поле хранит уже приведённое значение, так
   * что вставленное из буфера выглядит ровно так же, как набранное вручную.
   */
  format?: (value: string) => string
}>()

const model = defineModel<string>({ required: true })

/**
 * Ввод с маской. Поле без маски сюда не заходит — его ведёт v-model, и вместе
 * с ним остаётся то, что он умеет: ввод через IME, диктовку, автозаполнение.
 *
 * Само наложение маски и возврат курсора — в утилите: тем же правилом живёт
 * телефон на экране профиля, набранный своей разметкой.
 */
function onInput(event: Event) {
  if (!props.format) {
    return
  }

  model.value = applyMask(event.target as HTMLInputElement, props.format)
}
</script>

<template>
  <div class="field">
    <label :for="id">{{ label }}</label>

    <input
      :id="id"
      v-model="model"
      :type="type ?? 'text'"
      :autocomplete="autocomplete"
      :inputmode="inputmode"
      :placeholder="placeholder"
      :aria-invalid="Boolean(errors?.length)"
      :aria-describedby="errors?.length ? `${id}-error` : undefined"
      @input="onInput"
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
