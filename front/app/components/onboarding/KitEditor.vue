<script setup lang="ts">
import type { KitDefinition, KitIcon } from '~/types/onboarding'
const items = defineModel<KitDefinition[]>({ required: true })
const props = withDefaults(defineProps<{ lockedIds?: string[], errors?: Record<string, string[]> }>(), { lockedIds: () => [] })
const iconLabels: Record<KitIcon, string> = { access: 'Допуск', shirt: 'Одежда', barcode: 'Штрихкод', badge: 'Бейдж', key: 'Ключ', book: 'Книга', tool: 'Инструмент', device: 'Устройство', bag: 'Сумка', star: 'Звезда' }
const errors = computed(() => Object.entries(props.errors ?? {}).filter(([key]) => key.startsWith('kit_items')).flatMap(([, messages]) => messages))
function add() {
  if (items.value.length >= 30) return
  items.value = [...items.value, { id: `kit-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 9)}`, label: '', icon: 'badge', kind: 'item', is_required: true }]
}
function move(index: number, offset: number) {
  const next = index + offset
  if (next < 0 || next >= items.value.length) return
  const copy = [...items.value]
  const removed = copy.splice(index, 1)[0]
  if (!removed) return
  copy.splice(next, 0, removed)
  items.value = copy
}
function admission(index: number, checked: boolean) {
  items.value = items.value.map((item, position) => {
    if (position === index) return { ...item, kind: checked ? 'admission' : 'item', is_required: checked ? true : item.is_required }
    if (checked && item.kind === 'admission' && !props.lockedIds.includes(item.id)) return { ...item, kind: 'item' }
    return item
  })
}
const hasLockedAdmission = computed(() => items.value.some(item => item.kind === 'admission' && props.lockedIds.includes(item.id)))
</script>
<template>
  <fieldset class="kit-editor">
    <legend>Состав профессионального набора</legend>
    <p class="faint">Свои пункты для этой программы: например, бейдж, ключ от шкафчика, доступ к программе или рабочий инструмент.</p>
    <p v-if="errors.length" class="field-error" role="alert">{{ errors.join(' ') }}</p>
    <p v-if="!items.length" class="faint">Набор пуст. Добавьте пункты или выключите обязательный выпуск.</p>
    <ol class="kit-editor__items">
      <li v-for="(item, index) in items" :key="item.id" class="kit-editor__item">
        <div class="kit-editor__fields">
          <OnboardingKitIcon :icon="item.icon" class="kit-editor__icon" />
          <label class="field kit-editor__name"><span class="field-label">Пункт {{ index + 1 }}</span><input v-model.trim="item.label" class="input" :readonly="lockedIds.includes(item.id)" placeholder="Например, рабочий планшет" maxlength="100" required></label>
          <label class="field"><span class="field-label">Значок</span><select v-model="item.icon" class="input"><option v-for="(label, icon) in iconLabels" :key="icon" :value="icon">{{ label }}</option></select></label>
        </div>
        <div class="kit-editor__options">
          <label class="choice"><input v-model="item.is_required" type="checkbox" :disabled="item.kind === 'admission'">Обязателен для завершения</label>
          <label class="choice"><input type="checkbox" :checked="item.kind === 'admission'" :disabled="lockedIds.includes(item.id) || (hasLockedAdmission && item.kind !== 'admission')" @change="admission(index, ($event.target as HTMLInputElement).checked)">Допуск после экзамена</label>
          <div class="kit-editor__buttons">
            <button type="button" class="button-ghost button-sm" :disabled="index === 0" :aria-label="`Поднять пункт ${index + 1}`" @click="move(index, -1)">↑</button>
            <button type="button" class="button-ghost button-sm" :disabled="index === items.length - 1" :aria-label="`Опустить пункт ${index + 1}`" @click="move(index, 1)">↓</button>
            <button type="button" class="button-ghost button-sm" :disabled="lockedIds.includes(item.id)" :aria-label="`Убрать пункт ${index + 1}`" @click="items = items.filter(row => row.id !== item.id)">Убрать</button>
          </div>
        </div>
        <p v-if="lockedIds.includes(item.id)" class="faint">Уже выдано: название и факт получения сохранены.</p>
      </li>
    </ol>
    <button type="button" class="button-secondary" :disabled="items.length >= 30" @click="add">Добавить пункт набора</button>
    <p class="faint">Допуск можно задать один. Если он есть, остальные пункты выдаются после его подтверждения. Изменение шаблона не меняет уже назначенные наборы.</p>
  </fieldset>
</template>
<style scoped>
.kit-editor { padding: 0; border: 0; min-width: 0; display: grid; gap: .8rem; }
.kit-editor legend { font-weight: 600; margin-bottom: .6rem; }
.kit-editor__items { list-style: none; padding: 0; margin: 0; display: grid; gap: 1rem; }
.kit-editor__item { display: grid; gap: .6rem; padding: 1rem 0; border-bottom: 1px solid var(--color-border); min-width: 0; }
.kit-editor__fields { display: flex; align-items: center; flex-wrap: wrap; gap: .75rem; }
.kit-editor__icon { flex: none; width: 2rem; height: 2rem; }
.kit-editor__name { flex: 1 1 12rem; min-width: 0; }
.kit-editor__options { display: flex; align-items: center; flex-wrap: wrap; gap: .75rem; }
.kit-editor__buttons { display: flex; flex-wrap: wrap; gap: .25rem; }
</style>
