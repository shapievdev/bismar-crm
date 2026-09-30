<script setup lang="ts">
import { ApiValidationError, messageFromError, type ValidationErrors } from '~/composables/useAuth'
import type { MaterialReview } from '~/types/lms'

/**
 * Согласование — со стороны того, кого попросили.
 *
 * Стоит там, где материал читают: решают, посмотрев его, а не по названию в
 * списке. Появляется только когда ждут именно этого человека (`awaits_me`) —
 * ответивший второй раз не отвечает, а посторонний панели не видит вовсе.
 *
 * Возврат требует причины: без неё автор не знает, что исправить, — поэтому
 * кнопка открывает поле, а не отправляет сразу.
 */
const props = defineProps<{
  review: MaterialReview
  /** Чем назвать материал в тексте: «документ», «справочник», «курс». */
  materialLabel: string
}>()

const emit = defineEmits<{ decided: [review: MaterialReview] }>()

const api = useApprovalsApi()

const isSaving = ref(false)
const errorMessage = ref<string | null>(null)
const errors = ref<ValidationErrors>({})

const isReturning = ref(false)
const reason = ref('')

const reasonFieldId = useId()

/** Сколько ещё людей ждут: «согласую я — выйдет ли материал». */
const others = computed(() =>
  (props.review.decisions ?? []).filter(decision => decision.status === 'pending').length - 1,
)

async function approve() {
  isSaving.value = true
  errorMessage.value = null

  try {
    emit('decided', (await api.approve(props.review.id)).data)
  }
  catch (caught) {
    errorMessage.value = messageFromError(caught, 'Не удалось согласовать.')
  }
  finally {
    isSaving.value = false
  }
}

async function sendBack() {
  isSaving.value = true
  errorMessage.value = null
  errors.value = {}

  try {
    emit('decided', (await api.returnForRevision(props.review.id, reason.value)).data)

    isReturning.value = false
    reason.value = ''
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      errors.value = caught.errors
    }
    else {
      errorMessage.value = messageFromError(caught, 'Не удалось вернуть на исправление.')
    }
  }
  finally {
    isSaving.value = false
  }
}
</script>

<template>
  <section class="card decision">
    <h2 class="decision__title">
      Вас просят согласовать {{ materialLabel }}
    </h2>

    <p class="faint decision__note">
      <template v-if="others > 0">
        Кроме вас ждут ещё {{ others }}: {{ materialLabel }} выйдет, когда согласуют все.
      </template>
      <template v-else>
        Вы последний: согласуете — {{ materialLabel }} опубликуется сам.
      </template>
    </p>

    <p v-if="errorMessage" class="alert alert--danger" role="alert">
      {{ errorMessage }}
    </p>

    <!-- Возврат с причиной: кнопка открывает поле, а не отправляет молча. -->
    <form v-if="isReturning" class="returning" @submit.prevent="sendBack">
      <div class="field">
        <label class="field-label" :for="reasonFieldId">Что исправить</label>
        <textarea
          :id="reasonFieldId"
          v-model.trim="reason"
          class="input"
          rows="3"
          maxlength="2000"
          placeholder="Например: в пункте 3 не та ставка."
        />
        <p v-if="errors.comment?.length" class="field-error">
          {{ errors.comment[0] }}
        </p>
      </div>

      <div class="decision__actions">
        <button type="submit" class="button-primary button-sm" :disabled="isSaving">
          Вернуть автору
        </button>
        <button type="button" class="button-ghost button-sm" :disabled="isSaving" @click="isReturning = false">
          Отмена
        </button>
      </div>
    </form>

    <div v-else class="decision__actions">
      <button type="button" class="button-primary button-sm" :disabled="isSaving" @click="approve">
        Согласовать
      </button>
      <button type="button" class="button-secondary button-sm" :disabled="isSaving" @click="isReturning = true">
        Вернуть на исправление
      </button>
    </div>
  </section>
</template>

<style scoped>
.decision {
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
  padding: 1.1rem 1.25rem;
  /* Заметно, но не тревожно: это просьба, а не отказ. */
  border-left: 3px solid var(--color-accent);
}

.decision__title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 500;
}

.decision__note {
  margin: 0;
  font-size: 0.85rem;
}

.returning {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.decision__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
</style>
