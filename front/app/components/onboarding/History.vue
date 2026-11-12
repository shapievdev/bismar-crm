<script setup lang="ts">
import type { RouteActivity } from '~/types/onboarding'
defineProps<{ entries: RouteActivity[] }>()
const names: Record<string, string> = { title: 'Название', description: 'Описание', opens_at: 'Открытие', due_at: 'Срок', completed_at: 'Завершение', scheduled_at: 'Время встречи', confirmed_at: 'Подтверждение', submitted_at: 'Отметка сотрудника', status: 'Состояние', paused_at: 'Пауза', day_offset: 'День', extra_attempts: 'Дополнительные попытки', access_confirmed_at: 'Допуск', tshirt_issued_at: 'Футболка', barcode_issued_at: 'Штрихкод', is_final_exam: 'Итоговый экзамен', allow_ahead: 'Переход вперёд', requires_graduation: 'Обязательный выпуск', text: 'Задание', topic: 'Тема', place: 'Место', version: 'Версия', job_title: 'Должность', store: 'Магазин', kit_items: 'Состав набора', kit_receipts: 'Выдача набора', settings: 'Сообщения и уведомления', responsible_ids: 'Ответственные' }
function details(entry: RouteActivity): string[] {
  try {
    const change = typeof entry.changes === 'string' ? JSON.parse(entry.changes) : entry.changes
    return Object.entries(change?.after ?? {}).filter(([key]) => names[key]).map(([key, value]) => {
      if (key === 'kit_receipts') {
        try { const receipts = typeof value === 'string' ? JSON.parse(value) : value; return 'Подтверждено: ' + Object.values(receipts ?? {}).map((receipt: any) => receipt.label).filter(Boolean).join(', ') } catch { return 'Выдача набора подтверждена' }
      }
      if (key === 'kit_items') return 'Состав набора обновлён'
      if (key === 'settings' || key === 'responsible_ids') return `${names[key]} обновлены`
      const labels: Record<string, string> = { active: 'идёт', paused: 'приостановлен', completed: 'завершён' }
      return `${names[key]}: ${value == null ? 'не задано' : typeof value === 'boolean' ? (value ? 'да' : 'нет') : labels[String(value)] ?? String(value)}`
    })
  } catch { return [] }
}
</script>
<template>
  <details class="card history">
    <summary>История и изменения ({{ entries.length }})</summary>
    <p v-if="!entries.length" class="faint">Здесь будут сохраняться новые события маршрута.</p>
    <ol>
      <li v-for="entry in entries" :key="entry.id">
        <strong>{{ entry.event }}: {{ entry.subject }}</strong>
        <span class="faint">{{ new Date(entry.created_at).toLocaleString('ru-RU') }} · {{ entry.actor_name ?? 'Система' }}</span>
        <span v-for="line in details(entry)" :key="line">{{ line }}</span>
      </li>
    </ol>
  </details>
</template>
<style scoped>.history { padding: 1.25rem; margin-top: 1.25rem; } summary { cursor: pointer; font-weight: 600; } ol { padding-left: 1.2rem; } li { margin-top: 1rem; } li span { display: block; font-size: .85rem; overflow-wrap: anywhere; }</style>
