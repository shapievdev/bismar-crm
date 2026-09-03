<script setup lang="ts">
import type { QuizAttempt } from '~/types/lms'

/**
 * Что стало с работой, отправленной на аттестацию.
 *
 * Между отправкой и вердиктом есть ожидание, и оно должно быть видно: человек,
 * отправивший работу в пустоту, отправит её ещё раз. Поэтому здесь всегда три
 * вещи — дошла ли работа, кто её читает и что ответили.
 *
 * У обычного теста такого состояния не бывает вовсе, и панель ничего не рисует.
 */
const props = defineProps<{
  attempt: QuizAttempt
  /** Кому сдана работа. Приходит с тестом: у попытки его нет. */
  examiner?: string | null
}>()

const status = computed(() => props.attempt.review_status ?? 'auto')

function when(value: string | null | undefined): string {
  return value ? new Date(value).toLocaleString('ru-RU') : ''
}
</script>

<template>
  <div v-if="status !== 'auto'" class="attestation" :class="`attestation--${status}`">
    <template v-if="status === 'pending'">
      <strong>Работа отправлена на аттестацию</strong>
      <p>
        <template v-if="examiner">Её читает {{ examiner }}.</template>
        <template v-else>Она ждёт проверяющего.</template>
        Ответ появится здесь же — отправлять второй раз не нужно.
      </p>
    </template>

    <template v-else-if="status === 'passed'">
      <strong>Аттестация пройдена</strong>
      <p>
        Зачёл {{ attempt.reviewed_by ?? 'проверяющий' }}, {{ when(attempt.reviewed_at) }}.
      </p>
      <p v-if="attempt.review_comment" class="attestation__comment">
        {{ attempt.review_comment }}
      </p>
    </template>

    <template v-else>
      <strong>Работа не зачтена</strong>
      <!-- Причина идёт первой и крупнее прочего: ради неё эту страницу и
           открывают второй раз. -->
      <p v-if="attempt.review_comment" class="attestation__comment">
        {{ attempt.review_comment }}
      </p>
      <p class="attestation__who">
        {{ attempt.reviewed_by ?? 'Проверяющий' }}, {{ when(attempt.reviewed_at) }}
      </p>
    </template>
  </div>
</template>

<style scoped>
.attestation {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  padding: 0.9rem 1.1rem;
  border-radius: var(--radius);
  background: var(--color-surface-sunken);
  font-size: 0.9rem;
}

.attestation--passed {
  background: var(--color-success-soft);
  color: var(--color-success);
}

.attestation--failed {
  background: var(--color-danger-soft);
  color: var(--color-danger);
}

.attestation p {
  margin: 0;
}

.attestation__comment {
  white-space: pre-wrap;
}

.attestation__who {
  opacity: 0.75;
  font-size: 0.82rem;
}
</style>
