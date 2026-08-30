<script setup lang="ts">
/**
 * Окно вопроса — одно на приложение, живёт в оболочке.
 *
 * Собрано на `<dialog>`: браузер сам не пускает щелчки мимо, ловит Esc и
 * возвращает внимание туда, откуда окно открыли, — всё это иначе пришлось бы
 * писать руками и однажды написать неправильно.
 */
const { request, settle } = useAppDialog()

const element = ref<HTMLDialogElement | null>(null)
const field = ref<HTMLInputElement | null>(null)
const draft = ref('')

watch(request, async (value) => {
  if (!value) {
    element.value?.close()

    return
  }

  draft.value = value.value

  await nextTick()
  element.value?.showModal()

  // Курсор сразу в поле, а набранное выделено: чаще всего прежнее название
  // заменяют целиком, а не дописывают к нему.
  if (value.kind === 'prompt') {
    field.value?.focus()
    field.value?.select()
  }
})

function submit() {
  settle(request.value?.kind === 'prompt' ? draft.value : '')
}
</script>

<template>
  <dialog
    ref="element"
    class="dialog"
    :aria-label="request?.title"
    @cancel.prevent="settle(null)"
    @close="request && settle(null)"
  >
    <form v-if="request" class="dialog__body" @submit.prevent="submit">
      <h2 class="dialog__title">
        {{ request.title }}
      </h2>

      <p v-if="request.message" class="dialog__message">
        {{ request.message }}
      </p>

      <div v-if="request.kind === 'prompt'" class="dialog__field">
        <label v-if="request.label" class="field-label" for="dialog-input">{{ request.label }}</label>
        <input
          id="dialog-input"
          ref="field"
          v-model="draft"
          class="input"
          type="text"
          autocomplete="off"
          :placeholder="request.placeholder"
        >
      </div>

      <div class="dialog__actions">
        <button type="button" class="button-secondary" @click="settle(null)">
          {{ request.cancelLabel }}
        </button>
        <button
          type="submit"
          :class="request.danger ? 'button-danger' : 'button-primary'"
          :disabled="request.kind === 'prompt' && draft.trim() === ''"
        >
          {{ request.confirmLabel }}
        </button>
      </div>
    </form>
  </dialog>
</template>

<style scoped>
.dialog {
  width: min(28rem, calc(100vw - 2rem));
  padding: 0;
  border: 0;
  border-radius: var(--radius);
  background: var(--color-surface);
  color: var(--color-text);
  box-shadow: 0 24px 60px rgb(0 0 0 / 28%);
}

.dialog::backdrop {
  background: rgb(0 0 0 / 45%);
}

.dialog__body {
  display: flex;
  flex-direction: column;
  gap: 0.9rem;
  padding: 1.3rem 1.4rem;
}

.dialog__title {
  margin: 0;
  font-size: 1.1rem;
  font-weight: 600;
}

.dialog__message {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.9rem;
  line-height: 1.45;
}

.dialog__field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

/* Согласие справа — там же, где оно стоит в остальных формах приложения. */
.dialog__actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  padding-top: 0.2rem;
}
</style>
