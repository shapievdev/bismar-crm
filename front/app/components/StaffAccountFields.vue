<script setup lang="ts">
import type { ValidationErrors } from '~/composables/useAuth'
import type { StaffAccountDraft } from '~/types/auth'
import { maskPhone } from '~/utils/phone'

/**
 * Поля учётной записи сотрудника — одни и те же, когда его заводят и когда его
 * правят. Разница только в пароле: у нового он обязателен, у прежнего пустое
 * поле означает «не менять», — и об этом говорит подпись.
 */
const props = withDefaults(defineProps<{
  mode: 'create' | 'edit'
  errors: ValidationErrors
  /**
   * Кого можно поставить наставником — работающие, кроме самого правимого.
   *
   * Списком снаружи, а не запросом отсюда: форму открывают и при заведении
   * человека, когда наставника ещё некому назначать, и лишний поход за людьми
   * там был бы холостым.
   */
  colleagues?: { id: number, name: string }[]
}>(), { colleagues: () => [] })

const draft = defineModel<StaffAccountDraft>({ required: true })

/*
 * Положение выбирают из трёх. «Уволен» сюда не входит: увольнение — отдельное
 * действие с причиной ухода и обрывом сессий, а не пункт списка.
 */
const STATUSES = [
  { value: 'working' as const, label: 'Работает' },
  { value: 'parental-leave' as const, label: 'В декрете' },
  { value: 'acting' as const, label: 'ВРИО' },
]

const MODES = [
  { value: '' as const, label: 'Не указан' },
  { value: 'shift' as const, label: 'Сменный' },
  { value: 'office' as const, label: 'Офис' },
]

const mentors = computed(() => [
  { value: '', label: 'Без наставника' },
  ...props.colleagues.map(one => ({ value: String(one.id), label: one.name })),
])

/** Сегодня — дальше этого дня приём не отмечают: человек либо принят, либо нет. */
const today = new Date().toISOString().slice(0, 10)
</script>

<template>
  <div class="fields">
    <FormField id="last_name" v-model="draft.last_name" label="Фамилия" autocomplete="off" :errors="errors.last_name" />
    <FormField id="first_name" v-model="draft.first_name" label="Имя" autocomplete="off" :errors="errors.first_name" />
    <FormField id="middle_name" v-model="draft.middle_name" label="Отчество — если есть" autocomplete="off" :errors="errors.middle_name" />
    <FormField id="email" v-model="draft.email" label="Email — если есть" type="email" autocomplete="off" :errors="errors.email" />

    <FormField
      id="phone"
      v-model="draft.phone"
      label="Телефон — по нему сотрудник входит"
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

    <!--
      Кадровое. Дата приёма стоит рядом с должностью, а не в отдельной панели:
      её спрашивают тогда же, когда заводят человека, и разнесённая по разным
      местам она не заполняется вовсе — а без неё не считается ни стаж, ни
      текучесть.
    -->
    <FormField
      id="hired_at"
      v-model="draft.hired_at"
      label="Дата приёма"
      type="date"
      :max="today"
      :errors="errors.hired_at"
      hint="Без неё сотрудник не попадёт в отчёт о движении персонала"
    />

    <div class="field">
      <span class="field-label">Положение</span>
      <UiSelect v-model="draft.employment_status" :options="STATUSES" />
      <p v-if="errors.employment_status" class="field-error">
        {{ errors.employment_status[0] }}
      </p>
    </div>

    <div class="field">
      <span class="field-label">Режим работы</span>
      <UiSelect v-model="draft.work_mode" :options="MODES" />
      <p v-if="errors.work_mode" class="field-error">
        {{ errors.work_mode[0] }}
      </p>
    </div>

    <div v-if="mode === 'edit' && colleagues.length" class="field">
      <span class="field-label">Наставник</span>
      <UiSelect v-model="draft.mentor_id" :options="mentors" searchable />
      <p v-if="errors.mentor_id" class="field-error">
        {{ errors.mentor_id[0] }}
      </p>
    </div>

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
