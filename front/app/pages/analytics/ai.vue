<script setup lang="ts">
import type { AnalystTurn } from '~/types/analytics'
import { ApiValidationError, messageFromError } from '~/composables/useAuth'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'ИИ-аналитик' })

const { askAnalyst, fetchAnalystHistory, forgetAnalystHistory } = useAnalyticsApi()

/**
 * Переписка — своя у каждого, и читается с сервера, а не держится в памяти
 * страницы: вернувшись завтра, человек продолжает разговор, а не начинает его.
 * Панели при этом пересчитаны — вчерашний вопрос показывает сегодняшние цифры.
 */
const turns = ref<AnalystTurn[]>([])
const question = ref('')
const isAsking = ref(false)
const error = ref<string | null>(null)
const isLoading = ref(true)

/** Вопрос, который сейчас обдумывают, — показывается до прихода ответа. */
const pending = ref<string | null>(null)

const thread = useTemplateRef<HTMLElement>('thread')

onMounted(async () => {
  try {
    turns.value = (await fetchAnalystHistory()).data
  }
  catch {
    // Пустая переписка — не ошибка, с которой стоит пугать человека: он пришёл
    // задать вопрос, а не читать прошлые.
  }
  finally {
    isLoading.value = false
    await toBottom()
  }
})

async function ask() {
  const asked = question.value.trim()

  if (asked === '' || isAsking.value) {
    return
  }

  isAsking.value = true
  error.value = null
  pending.value = asked
  question.value = ''

  await toBottom()

  try {
    const { data } = await askAnalyst(asked)

    turns.value = [...turns.value, data]
  }
  catch (caught) {
    error.value = caught instanceof ApiValidationError
      ? caught.firstMessage
      : messageFromError(caught, 'Не удалось спросить. Попробуйте ещё раз.')

    // Вопрос возвращается в поле: переписывать его заново человек не должен.
    question.value = asked
  }
  finally {
    pending.value = null
    isAsking.value = false
    await toBottom()
  }
}

async function forget() {
  await forgetAnalystHistory()

  turns.value = []
  error.value = null
}

/** Новый ответ показывается целиком — к нему и прокручиваем. */
async function toBottom() {
  await nextTick()

  thread.value?.scrollIntoView({ block: 'end', behavior: 'smooth' })
}

/**
 * С чего начать, когда спрашивать ещё не о чем.
 *
 * Не украшение: раздел принимает вопросы словами, и человек, впервые его
 * открывший, не знает, насколько вольно можно спрашивать. Готовые примеры
 * отвечают на это быстрее любого пояснения.
 */
const examples = [
  'Как идут продажи за квартал по магазинам?',
  'Покажи топ товаров по прибыли за месяц',
  'Сравни каналы продаж за полгода',
  'Что с возвратами за последние три месяца?',
]

function askExample(example: string) {
  question.value = example

  return ask()
}
</script>

<template>
  <div class="page">
    <header class="page__head">
      <h1 class="page__title">
        ИИ-аналитик
      </h1>
      <p class="page__lead">
        Спросите о цифрах словами — в ответ придёт объяснение и собранная панель.
        Считает он не сам: цифры те же, что на остальных вкладках, и посчитаны
        тем же кодом.
      </p>
    </header>

    <div v-if="turns.length" class="analyst__tools">
      <button type="button" class="button-ghost" @click="forget">
        Очистить переписку
      </button>
    </div>

    <div ref="thread" class="analyst">
      <p v-if="isLoading" class="analyst__waiting">
        Читаем переписку…
      </p>

      <!-- Пустой раздел: с чего начать. -->
      <div v-else-if="!turns.length && !pending" class="analyst__start">
        <p class="analyst__start-lead">
          Например:
        </p>

        <ul class="analyst__examples">
          <li v-for="example in examples" :key="example">
            <button type="button" class="analyst__example" @click="askExample(example)">
              {{ example }}
            </button>
          </li>
        </ul>
      </div>

      <article v-for="turn in turns" :key="turn.id ?? turn.question" class="turn">
        <p class="turn__question">
          {{ turn.question }}
        </p>

        <div class="turn__answer">
          <p class="turn__words">
            {{ turn.answer }}
          </p>

          <AnalyticsDashboardView v-if="turn.dashboard" :dashboard="turn.dashboard" />
        </div>
      </article>

      <article v-if="pending" class="turn">
        <p class="turn__question">
          {{ pending }}
        </p>

        <p class="turn__thinking">
          Смотрю цифры и собираю панель…
        </p>
      </article>
    </div>

    <p v-if="error" class="notice notice--error">
      {{ error }}
    </p>

    <form class="analyst__form" @submit.prevent="ask">
      <textarea
        v-model="question"
        class="analyst__input"
        rows="2"
        placeholder="Как идут продажи за месяц?"
        :disabled="isAsking"
        @keydown.enter.exact.prevent="ask"
      />

      <button type="submit" class="button-primary" :disabled="isAsking || !question.trim()">
        {{ isAsking ? 'Думаю…' : 'Спросить' }}
      </button>
    </form>
  </div>
</template>

<style scoped>
/*
 * Разметка страницы — своя, как и у остальных вкладок аналитики: эти классы
 * живут в самих страницах, а не в общем наборе, и шаг сетки задаёт страница.
 */
.page {
  display: flex;
  flex-direction: column;

  --bento-row: 8.5rem;
}

.page__head {
  margin-bottom: 1.25rem;
}

.page__title {
  margin: 0;
  font-size: 1.6rem;
  font-weight: 600;
  letter-spacing: -0.02em;
}

.page__lead {
  margin: 0.35rem 0 0;
  font-size: 0.85rem;
  color: var(--color-text-muted);
  max-width: 62ch;
}

.notice {
  margin: 0.75rem 0 0;
  padding: 0.85rem 1rem;
  border-radius: var(--radius-sm);
  font-size: 0.85rem;
}

.notice--error {
  background: var(--color-danger-soft);
  color: var(--color-danger);
}

.analyst {
  display: flex;
  flex-direction: column;
  gap: 1.75rem;
}

.analyst__tools {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 0.5rem;
}

.analyst__waiting,
.turn__thinking {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.analyst__start-lead {
  margin: 0 0 0.6rem;
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.analyst__examples {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.analyst__example {
  padding: 0.5rem 0.85rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface-raised);
  color: var(--color-text);
  font: inherit;
  font-size: 0.88rem;
  cursor: pointer;
  text-align: left;
}

.analyst__example:hover {
  border-color: var(--color-accent);
}

/* Вопрос человека — выделен, ответ — на полную ширину: панель в нём шириной
   со страницу, и сжимать её в пузырь переписки значило бы делать графики
   нечитаемыми. */
.turn__question {
  margin: 0 0 0.75rem;
  padding: 0.6rem 0.9rem;
  border-radius: var(--radius);
  background: var(--color-surface-sunken, var(--color-surface-raised));
  font-weight: 500;
}

.turn__words {
  margin: 0;
  white-space: pre-line;
}

.analyst__form {
  display: flex;
  gap: 0.6rem;
  align-items: flex-end;
  margin-top: 1.5rem;
  position: sticky;
  bottom: 0;
  padding: 0.75rem 0;
  background: var(--color-surface);
}

.analyst__input {
  flex: 1;
  padding: 0.6rem 0.8rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface-raised);
  color: var(--color-text);
  font: inherit;
  resize: vertical;
}

.analyst__form .button-primary {
  white-space: nowrap;
}
</style>
