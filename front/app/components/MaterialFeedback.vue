<script setup lang="ts">
import type { AppealTarget } from '~/composables/useAppealApi'
import type { CoursePerson } from '~/types/lms'
import { ApiValidationError } from '~/composables/useAuth'

/**
 * «Нашли ответ?» — панель под материалом.
 *
 * Три ответа, и только два из них кому-то пишут. «Да, вопрос закрыт» ничего не
 * сохраняет и никого не беспокоит: она убирает панель, потому что спрашивать
 * дальше не о чем (решение пользователя 2026-09-06). Остальные два начинают
 * разговор с тем, кто материал правит, — «не хватило» значит дописать,
 * «неверно» значит исправить, и автору это разные работы.
 *
 * Текста без сообщения не бывает: одна кнопка оставляет автора с «что-то не
 * так» и ничего не говорит о том, что править. Поэтому кнопка отправки не
 * работает, пока не написали.
 */
const props = defineProps<{ target: AppealTarget }>()

const { recipients: fetchRecipients, send } = useAppealApi()
const { user } = useAuth()

type Reason = 'missing' | 'incorrect'

const REASONS: Record<Reason, string> = {
  missing: 'Ответа не хватило',
  incorrect: 'Здесь написано неверно',
}

/** `null` — панель в покое; строка — открыта форма с этой причиной. */
const reason = ref<Reason | null>(null)
const isClosed = ref(false)

const people = ref<CoursePerson[]>([])
const isLoadingPeople = ref(false)
const recipientId = ref<number | null>(null)
const body = ref('')
const isSending = ref(false)
const error = ref<string | null>(null)

/** Куда идти читать ответ. Пусто, пока ничего не отправляли. */
const sentTo = ref<number | null>(null)

/*
 * Себе замечаний не пишут: автор правит материал сразу. Из списка он поэтому
 * убран — а если он там один, то и писать некому.
 */
const candidates = computed(() =>
  people.value.filter(person => person.id !== user.value?.id),
)

async function open(next: Reason) {
  reason.value = next
  error.value = null

  if (people.value.length || isLoadingPeople.value) {
    return
  }

  /*
   * Список спрашивается у сервера, а не собирается из того, что уже лежит на
   * странице: писать можно только автору и ответственным, и этот же список
   * сервер проверяет при отправке. Второй его источник рано или поздно
   * разойдётся с первым — и разойдётся молча.
   */
  isLoadingPeople.value = true

  try {
    people.value = (await fetchRecipients(props.target)).data
    recipientId.value = candidates.value[0]?.id ?? null
  }
  catch {
    error.value = 'Не удалось узнать, кому писать.'
  }
  finally {
    isLoadingPeople.value = false
  }
}

function cancel() {
  reason.value = null
  body.value = ''
  error.value = null
}

async function submit() {
  if (reason.value === null || recipientId.value === null || body.value.trim() === '') {
    return
  }

  isSending.value = true
  error.value = null

  try {
    const { data } = await send(props.target, {
      recipient_id: recipientId.value,
      reason: reason.value,
      body: body.value.trim(),
    })

    sentTo.value = data.conversation_id
    reason.value = null
    body.value = ''
  }
  catch (caught) {
    error.value = caught instanceof ApiValidationError
      ? (caught.errors.body?.[0] ?? 'Не удалось отправить.')
      : 'Не удалось отправить.'
  }
  finally {
    isSending.value = false
  }
}
</script>

<template>
  <section class="card card--raised feedback">
    <!-- Отправленное сообщение — конец разговора здесь и начало его в
         мессенджере: панель отвечает, куда идти за ответом. -->
    <template v-if="sentTo !== null">
      <h2 class="feedback__title">
        Отправлено
      </h2>
      <p class="feedback__hint">
        Автор увидит сообщение в мессенджере и ответит там же.
      </p>
      <NuxtLink :to="`/messenger?id=${sentTo}`" class="button-secondary button-sm">
        Открыть переписку
      </NuxtLink>
    </template>

    <template v-else-if="isClosed">
      <h2 class="feedback__title">
        Рады, что помогло
      </h2>
    </template>

    <template v-else>
      <h2 class="feedback__title">
        Нашли ответ?
      </h2>
      <p class="feedback__hint">
        Если ответа не хватило, материал поправит тот, кто его ведёт.
      </p>

      <div class="feedback__actions">
        <button
          type="button"
          class="button-primary"
          :class="{ 'button-sm': reason !== null }"
          @click="isClosed = true"
        >
          Да, вопрос закрыт
        </button>
        <button
          v-for="(label, value) in REASONS"
          :key="value"
          type="button"
          class="button-secondary"
          :class="{ 'button-sm': reason !== null, 'feedback__chosen': reason === value }"
          @click="open(value as Reason)"
        >
          {{ label }}
        </button>
      </div>

      <!-- Форма появляется только после выбора причины: до него спрашивать
           «кому и что написать» не о чем. -->
      <form v-if="reason" class="feedback__form" novalidate @submit.prevent="submit">
        <p v-if="error" class="alert alert--danger" role="alert">
          {{ error }}
        </p>

        <p v-if="isLoadingPeople" class="faint">
          Смотрим, кто ведёт материал…
        </p>

        <!-- Себя из списка убрали, и остаться он мог пустым по двум разным
             причинам: за материал отвечаете вы сами — или не отвечает никто. -->
        <p v-else-if="!candidates.length" class="faint">
          {{ people.length
            ? 'Это ваш материал — поправьте его сами.'
            : 'Писать некому: у материала нет ни автора, ни ответственных.' }}
        </p>

        <template v-else>
          <div class="field">
            <span class="field-label">Кому написать</span>

            <div class="people">
              <label v-for="person in candidates" :key="person.id" class="person">
                <input v-model="recipientId" type="radio" :value="person.id" name="appeal-recipient">
                <UserAvatar :name="person.name" :src="person.avatar_url" :size="28" />
                <span class="person__name">{{ person.name }}</span>
              </label>
            </div>
          </div>

          <div class="field">
            <label class="field-label" for="appeal-body">
              {{ reason === 'missing' ? 'Чего не хватило' : 'Что здесь неверно' }}
            </label>
            <textarea
              id="appeal-body"
              v-model="body"
              class="textarea"
              rows="3"
              :placeholder="reason === 'missing'
                ? 'Например: не сказано, что делать, если клиент просит скидку.'
                : 'Например: скидка теперь 15 процентов, а не 10.'"
            />
          </div>

          <div class="feedback__submit">
            <button type="submit" class="button-primary" :disabled="isSending || !body.trim()">
              {{ isSending ? 'Отправляем…' : 'Отправить' }}
            </button>
            <button type="button" class="button-ghost button-sm" @click="cancel">
              Отмена
            </button>
          </div>
        </template>
      </form>
    </template>
  </section>
</template>

<style scoped>
.feedback {
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
  padding: 1.4rem 1.5rem;
}

.feedback__title {
  margin: 0;
  font-size: 1.15rem;
  font-weight: 600;
}

.feedback__hint {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.feedback__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.6rem;
  margin-top: 0.2rem;
}

/* Выбранная причина остаётся видимой: форма ниже отвечает именно на неё. */
.feedback__chosen {
  box-shadow: inset 0 0 0 1.5px var(--color-accent);
}

.feedback__form {
  display: flex;
  flex-direction: column;
  gap: 0.9rem;
  margin-top: 0.4rem;
}

.feedback__submit {
  display: flex;
  align-items: center;
  gap: 0.6rem;
}

/* Список людей — строками с лицом: имя без лица в списке из четырёх человек
   читается медленнее, чем узнаётся снимок. */
.people {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.person {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.35rem 0.8rem 0.35rem 0.5rem;
  border-radius: var(--radius-pill);
  background: var(--control-surface);
  cursor: pointer;
}

.person:has(input:checked) {
  box-shadow: inset 0 0 0 1.5px var(--color-accent);
}

.person__name {
  font-size: 0.9rem;
}

@media (max-width: 48rem) {
  .feedback {
    padding: 1.15rem 1.15rem 1.25rem;
  }

  /* Три кнопки в строку на телефон не помещаются, а перенос по одной делает
     панель лесенкой: пусть каждая занимает строку целиком. */
  .feedback__actions {
    flex-direction: column;
    align-items: stretch;
  }
}
</style>
