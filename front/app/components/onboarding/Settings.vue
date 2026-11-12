<script setup lang="ts">
import type { RouteSettings } from '~/types/onboarding'
const settings = defineModel<RouteSettings>({ required: true })
const messages = { passed_message: 'Сообщение при сдаче теста', failed_message: 'Сообщение при неудаче', completed_message: 'Поздравление с завершением', stage_notification: 'Текст уведомления о новом этапе' } as const
const labels = { locked: 'Закрытый этап', available: 'Доступный этап', in_progress: 'Этап в процессе', completed: 'Пройденный этап', quiz_failed: 'Неудачная попытка теста' } as const
</script>
<template>
  <details>
    <summary>Сообщения, статусы и уведомления</summary>
    <div class="settings">
      <label class="choice"><input v-model="settings.notifications_enabled" type="checkbox">Отправлять уведомления об этапах и встречах</label>
      <label v-for="(label, key) in messages" :key="key" class="field">
        <span class="field-label">{{ label }}</span>
        <textarea v-model="settings[key]" class="input" rows="2" maxlength="500" required />
      </label>
      <label v-for="(label, key) in labels" :key="key" class="field">
        <span class="field-label">Подпись: {{ label.toLowerCase() }}</span>
        <input v-model="settings.status_labels[key]" class="input" maxlength="80" required>
      </label>
    </div>
  </details>
</template>
<style scoped>.settings { display: grid; gap: 1rem; margin-top: 1rem; } summary { cursor: pointer; font-weight: 600; }</style>
