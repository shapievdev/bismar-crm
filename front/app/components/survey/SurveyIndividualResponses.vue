<script setup lang="ts">
import type { SurveyIndividualResponse } from '~/types/survey'

const props = defineProps<{ responses: SurveyIndividualResponse[] }>()
const search = ref('')
const filtered = computed(() => {
  const needle = search.value.trim().toLocaleLowerCase('ru-RU')
  return props.responses.filter(row => (row.person ?? 'Сотрудник удалён').toLocaleLowerCase('ru-RU').includes(needle))
})

function when(value: string) {
  return new Date(value).toLocaleString('ru-RU', { dateStyle: 'medium', timeStyle: 'short' })
}

function hasAnswer(answer: SurveyIndividualResponse['answers'][number]) {
  return answer.options.length > 0 || Boolean(answer.other) || answer.scale !== null || Boolean(answer.text)
}
</script>

<template>
  <section class="individual" aria-label="Ответы сотрудников">
    <h3>Ответы сотрудников</h3>
    <p class="faint">Раскройте сотрудника, чтобы увидеть его ответы на каждый вопрос.</p>
    <label class="individual__search">
      <span>Найти сотрудника</span>
      <input v-model="search" class="input" type="search" placeholder="Фамилия или имя">
    </label>
    <p v-if="!filtered.length" class="faint">{{ responses.length ? 'Сотрудников не найдено.' : 'Ответов пока нет.' }}</p>
    <details v-for="response in filtered" :key="response.id" class="respondent">
      <summary>
        <span>{{ response.person ?? 'Сотрудник удалён' }}</span>
        <time v-if="response.submitted_at" :datetime="response.submitted_at" class="faint">{{ when(response.submitted_at) }}</time>
      </summary>
      <dl class="answers">
        <div v-for="answer in response.answers" :key="answer.question_id" class="answer">
          <dt>{{ answer.question }}</dt>
          <dd>
            <ul v-if="answer.options.length">
              <li v-for="option in answer.options" :key="option.id">{{ option.text }}</li>
            </ul>
            <p v-if="answer.other">Свой вариант: {{ answer.other }}</p>
            <p v-if="answer.scale !== null">Оценка: {{ answer.scale }}</p>
            <p v-if="answer.text">{{ answer.text }}</p>
            <p v-if="!hasAnswer(answer)" class="faint">Не ответил</p>
          </dd>
        </div>
      </dl>
    </details>
  </section>
</template>

<style scoped>
.individual { display: grid; gap: .85rem; border-top: 1px solid var(--color-border); padding-top: 1.25rem; }
h3, p { margin: 0; }
.individual__search { display: grid; gap: .4rem; max-width: 28rem; }
.respondent { border: 1px solid var(--color-border); border-radius: var(--radius-sm); padding: .85rem; min-width: 0; }
summary { cursor: pointer; overflow-wrap: anywhere; }
summary time { display: block; margin: .35rem 0 0 1rem; font-size: .8rem; }
.answers { margin: 1rem 0 0; display: grid; gap: 1rem; }
.answer { min-width: 0; }
dt { font-weight: 600; }
dd { margin: .4rem 0 0; white-space: pre-wrap; overflow-wrap: anywhere; }
dd ul { margin: 0; padding-left: 1.2rem; }
dd p + p { margin-top: .4rem; }
</style>
