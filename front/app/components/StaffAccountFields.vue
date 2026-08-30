<script setup lang="ts">
import type { ValidationErrors } from '~/composables/useAuth'
import type { StaffAccountDraft } from '~/types/auth'
import { maskPhone } from '~/utils/phone'

/**
 * Поля учётной записи сотрудника — одни и те же, когда его заводят и когда его
 * правят. Разница только в пароле: у нового он обязателен, у прежнего пустое
 * поле означает «не менять», — и об этом говорит подпись.
 */
defineProps<{
  mode: 'create' | 'edit'
  errors: ValidationErrors
}>()

const draft = defineModel<StaffAccountDraft>({ required: true })
</script>

<template>
  <div class="fields">
    <FormField id="last_name" v-model="draft.last_name" label="Фамилия" autocomplete="off" :errors="errors.last_name" />
    <FormField id="first_name" v-model="draft.first_name" label="Имя" autocomplete="off" :errors="errors.first_name" />
    <FormField id="middle_name" v-model="draft.middle_name" label="Отчество — если есть" autocomplete="off" :errors="errors.middle_name" />
    <FormField id="email" v-model="draft.email" label="Email" type="email" autocomplete="off" :errors="errors.email" />

    <FormField
      id="phone"
      v-model="draft.phone"
      label="Телефон — если есть"
      type="tel"
      inputmode="tel"
      autocomplete="off"
      placeholder="+7 (999) 000-99-77"
      :format="maskPhone"
      :errors="errors.phone"
    />

    <FormField
      id="job_title"
      v-model="draft.job_title"
      label="Должность — если есть"
      autocomplete="off"
      :errors="errors.job_title"
    />

    <FormField
      id="password"
      v-model="draft.password"
      :label="mode === 'create' ? 'Пароль' : 'Новый пароль — пусто, чтобы не менять'"
      type="password"
      autocomplete="new-password"
      :errors="errors.password"
    />
  </div>
</template>

<style scoped>
.fields {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr));
  gap: 0.9rem;
}
</style>
