<script setup lang="ts">
import { messageFromError } from '~/composables/useAuth'

/**
 * Полоса «вы работаете под чужим именем».
 *
 * Стоит над всем приложением и намеренно заметна: под чужим именем каждое
 * действие записывается на сотрудника — отметка об ознакомлении, сообщение в
 * мессенджере, сданный тест, — и человек обязан помнить, что он не у себя.
 * Тихая пометка где-нибудь в углу эту работу не делает.
 *
 * Появляется только у того, кто правда перешёл: остальным сервер поля не
 * присылает вовсе.
 */
const { user, impersonatedBy, returnToSelf } = useAuth()

const isReturning = ref(false)
const errorMessage = ref<string | null>(null)

async function comeBack() {
  isReturning.value = true
  errorMessage.value = null

  try {
    await returnToSelf()
  }
  catch (caught) {
    errorMessage.value = messageFromError(caught, 'Не удалось вернуться в свою учётную запись.')
    isReturning.value = false
  }
}
</script>

<template>
  <div v-if="impersonatedBy" class="impersonation" role="status">
    <span class="impersonation__text">
      Вы работаете под именем <b>{{ user?.name }}</b>. Всё, что вы сделаете, запишется на него.
    </span>

    <span v-if="errorMessage" class="impersonation__error">{{ errorMessage }}</span>

    <button type="button" class="impersonation__back" :disabled="isReturning" @click="comeBack">
      {{ isReturning ? 'Возвращаемся…' : `Вернуться в ${impersonatedBy}` }}
    </button>
  </div>
</template>

<style scoped>
/*
 * Полоса поверх всего и своим цветом: она не часть страницы, а сообщение о
 * состоянии всего приложения. Цвет берётся у опасного действия не потому, что
 * что-то сломалось, а потому, что так его замечают.
 */
.impersonation {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: center;
  gap: 0.5rem 1rem;
  padding: 0.5rem 1rem;
  background: var(--color-danger);
  color: #fff;
  font-size: 0.88rem;
  text-align: center;
}

.impersonation__text b {
  font-weight: 600;
}

.impersonation__error {
  font-weight: 500;
}

/*
 * Кнопка светлая на цветном: обводка на таком фоне читается хуже, а нажимают
 * сюда часто — ради этого полоса и висит.
 */
.impersonation__back {
  padding: 0.3rem 0.9rem;
  border: none;
  border-radius: var(--radius);
  background: #fff;
  color: var(--color-danger);
  font: inherit;
  font-weight: 500;
  cursor: pointer;
}

.impersonation__back:disabled {
  opacity: 0.7;
  cursor: default;
}
</style>
