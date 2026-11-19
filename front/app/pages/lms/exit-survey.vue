<script setup lang="ts">
import type { ExitSurveyDefinition } from '~/types/exit-survey'
definePageMeta({ middleware: 'auth', layout: false })
useHead({ title: 'Анкета при увольнении — Bismar' })
const { $api } = useNuxtApp()
const { clearSession, logout } = useAuth()
const { data, error, refresh } = await useAsyncData('exit-survey', () => $api<{ data: { id: number, definition: ExitSurveyDefinition, prefill: Record<string, string> } }>('/api/exit-survey'))
const answers = reactive<Record<string, string>>({ ...data.value?.data.prefill })
watch(() => data.value?.data.prefill, (prefill) => {
  if (prefill) for (const [key, value] of Object.entries(prefill)) {
    if (!answers[key]) answers[key] = value
  }
})
const errors = ref<Record<string, string[]>>({})
const saving = ref(false)
const submitted = ref(false)
const failure = ref('')
const fields = computed(() => data.value?.data.definition.fields ?? [])
const required = computed(() => fields.value.filter(field => field.required))
const completed = computed(() => required.value.filter(field => answers[field.id]?.trim() && (answers[field.id] !== 'other' || answers[`${field.id}_other`]?.trim())).length)
async function submit() {
  if (!data.value || saving.value) return
  saving.value = true
  failure.value = ''
  errors.value = {}
  try {
    await $api('/api/exit-survey', { method: 'POST', body: { assignment_id: data.value.data.id, answers } })
    clearSession()
    submitted.value = true
    window.scrollTo({ top: 0 })
  }
  catch (caught) {
    errors.value = (caught as { data?: { errors?: Record<string, string[]> } }).data?.errors ?? {}
    failure.value = messageFromError(caught, 'Не удалось отправить анкету. Ответы остались в форме. Попробуйте ещё раз.')
    await nextTick()
    document.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus()
  }
  finally { saving.value = false }
}
async function signOut() { await logout(); await navigateTo('/login') }
</script>
<template>
  <main class="exit-form">
    <header class="brand"><strong>BISMAR</strong><span class="muted">База знаний</span><button v-if="!submitted" class="button-ghost" type="button" @click="signOut">Выйти</button></header>
    <section v-if="submitted" class="card thanks" role="status"><h1>Спасибо за Ваши ответы</h1><p>Анкета сохранена. Ваше мнение поможет нам стать лучше.</p><p class="muted">Доступ к платформе завершён.</p><NuxtLink to="/login" class="button-secondary">На страницу входа</NuxtLink></section>
    <section v-else-if="error" class="card" role="alert"><h1>Не удалось открыть анкету</h1><p>Попробуйте обновить страницу. Если анкета уже отправлена, повторное заполнение недоступно.</p><button class="button-primary" @click="refresh()">Попробовать снова</button></section>
    <form v-else-if="data" @submit.prevent="submit">
      <section class="card"><p class="muted">Обратная связь</p><h1>{{ data.data.definition.title }}</h1><p>{{ data.data.definition.description }}</p><p class="notice">После отправки анкеты доступ к платформе закроется. Ответы увидят администраторы и сотрудники с полным доступом к разделу «Сотрудники».</p><label for="exit-progress" class="muted">Заполнено {{ completed }} из {{ required.length }} обязательных полей</label><progress id="exit-progress" :value="completed" :max="required.length" /></section>
      <section class="card"><h2>О Вас и работе в компании</h2><div class="identity">
        <div v-for="field in fields.filter(item => item.type !== 'radio' && item.id !== 'comments')" :key="field.id" class="field" :class="{ wide: field.type === 'textarea' }">
          <label :for="`exit-${field.id}`" class="field-label">{{ field.label }} <span aria-hidden="true">*</span></label>
          <select v-if="field.type === 'select'" :id="`exit-${field.id}`" v-model="answers[field.id]" class="input" required :aria-invalid="Boolean(errors[`answers.${field.id}`])"><option value="" disabled>Выберите подразделение</option><option v-for="option in field.options" :key="option" :value="option">{{ option }}</option></select>
          <textarea v-else-if="field.type === 'textarea'" :id="`exit-${field.id}`" v-model="answers[field.id]" class="input" rows="3" required maxlength="5000" :aria-invalid="Boolean(errors[`answers.${field.id}`])" />
          <input v-else :id="`exit-${field.id}`" v-model="answers[field.id]" class="input" :type="field.type" required maxlength="5000" :aria-invalid="Boolean(errors[`answers.${field.id}`])">
          <p v-if="errors[`answers.${field.id}`]" class="failure">{{ errors[`answers.${field.id}`]?.[0] }}</p>
        </div></div></section>
      <fieldset v-for="(field, index) in fields.filter(item => item.type === 'radio')" :key="field.id" class="card question">
        <legend>{{ index + 1 }}. {{ field.label }} <span aria-hidden="true">*</span></legend>
        <label v-for="option in [...(field.options ?? []), ...(field.other ? ['other'] : [])]" :key="option" class="option" :class="{ selected: answers[field.id] === option }"><input v-model="answers[field.id]" type="radio" :name="field.id" :value="option" required :aria-invalid="Boolean(errors[`answers.${field.id}`])"><span>{{ option === 'other' ? 'Другое' : option }}</span></label>
        <input v-if="answers[field.id] === 'other'" v-model="answers[`${field.id}_other`]" class="input" :aria-label="`Другой ответ: ${field.label}`" placeholder="Напишите свой ответ" required maxlength="5000" :aria-invalid="Boolean(errors[`answers.${field.id}_other`])">
        <p v-if="errors[`answers.${field.id}`] || errors[`answers.${field.id}_other`]" class="failure">{{ errors[`answers.${field.id}`]?.[0] ?? errors[`answers.${field.id}_other`]?.[0] }}</p>
      </fieldset>
      <section class="card"><label for="exit-comments" class="field-label">Дополнительные комментарии <span class="muted">— необязательно</span></label><textarea id="exit-comments" v-model="answers.comments" class="input" rows="4" maxlength="5000" /><p v-if="failure" class="failure" role="alert">{{ failure }}</p><div class="submit"><span class="muted">* Обязательные поля</span><button class="button-primary" type="submit" :disabled="saving">{{ saving ? 'Отправляем…' : 'Отправить анкету' }}</button></div></section>
    </form>
  </main>
</template>
<style scoped>
.exit-form { max-width: 860px; margin: 0 auto; padding: 28px 20px 60px; }
.brand { display: flex; align-items: center; gap: 16px; margin-bottom: 28px; }.brand strong { letter-spacing: .12em; }.brand button { margin-left: auto; }
.card { background: var(--color-surface); border: 0; border-radius: 24px; padding: 32px; margin: 0 0 18px; min-width: 0; }
h1 { font-size: clamp(26px, 4vw, 38px); line-height: 1.2; margin: 8px 0 20px; }h2 { font-size: 21px; margin: 0 0 24px; }p { line-height: 1.65; }
.notice { padding: 16px; background: var(--color-bg); border-radius: 14px; font-size: 14px; }progress { display: block; width: 100%; height: 8px; accent-color: var(--color-accent); margin-top: 12px; }
.identity { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 24px; }.field { display: flex; flex-direction: column; gap: 8px; }.wide { grid-column: 1 / -1; }.input { width: 100%; min-width: 0; }
.question { padding-top: 26px; }.question legend { float: left; width: 100%; font-size: 19px; font-weight: 600; line-height: 1.5; margin-bottom: 20px; }.question legend + * { clear: both; }
.option { display: flex; align-items: flex-start; gap: 12px; padding: 15px; border: 1px solid var(--color-border); border-radius: 14px; margin: 10px 0; cursor: pointer; line-height: 1.5; }.selected { border-color: var(--color-accent); background: var(--color-bg); }.option input { margin-top: 5px; flex: 0 0 auto; accent-color: var(--color-accent); }
.failure { color: var(--color-danger, #c92336); }.submit { display: flex; align-items: center; justify-content: space-between; gap: 20px; margin-top: 24px; }.thanks { text-align: center; padding-block: 64px; }
@media (max-width: 600px) { .exit-form { padding: 18px 12px 36px; }.card { padding: 22px 18px; border-radius: 20px; }.identity { grid-template-columns: minmax(0, 1fr); }.submit { flex-direction: column; align-items: stretch; }.question legend { font-size: 17px; } }
</style>
