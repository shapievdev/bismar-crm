<script setup lang="ts">
import type { Achievement } from '~/types/achievements'

/**
 * Выбор значка для шага маршрута.
 *
 * Поверх UiSelect, а не системного `<select>`: открытый список системного
 * рисует операционная система — своей палитрой, своим шрифтом и во всю
 * ширину окна, — и в форме этапа он выпадал из вёрстки. UiSelect приложение
 * рисует само, и поиск в нём появляется, когда вариантов становится больше
 * горсти, — ровно то, что нужно растущему каталогу значков.
 *
 * Описание достижения едет второй строкой подсказки: названия вроде «Первый
 * шаг» и «Первый выход» в длинном списке различают именно по ней, и поиск
 * идёт в том числе по ней.
 *
 * Каталог приходит сверху: он один на весь редактор, и тянуть его в каждой
 * строке этапа значило бы спрашивать сервер по разу на материал.
 */
const props = withDefaults(defineProps<{
  achievements: Achievement[]
  /** Подпись сверху; пусто — только список (строки шагов тесные). */
  label?: string
  /** Короткий вид: в строке материала или задания, рядом с его названием. */
  compact?: boolean
  id?: string
}>(), {
  label: '',
  compact: false,
  id: undefined,
})

const model = defineModel<number | null>({ required: true })

const options = computed(() => [
  { value: null as number | null, label: 'Без достижения' },
  ...props.achievements.map(achievement => ({
    value: achievement.id as number | null,
    label: achievement.title,
    hint: achievement.description ?? undefined,
  })),
])

const chosen = computed(() => props.achievements.find(item => item.id === model.value) ?? null)
</script>

<template>
  <div class="award" :class="{ 'award--compact': compact }">
    <label v-if="label" class="field-label" :for="id">{{ label }}</label>

    <div class="award__row">
      <!-- Выбранный значок рядом со списком: названия похожи, а картинки нет,
           и по ней видно, что выбрано, не раскрывая список. -->
      <AchievementsMedal
        v-if="chosen"
        :achievement="chosen"
        size="sm"
      />

      <UiSelect
        :id="id"
        v-model="model"
        class="award__select"
        :options="options"
        placeholder="Без достижения"
      />
    </div>
  </div>
</template>

<style scoped>
.award {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  min-width: 0;
}

.award__row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  min-width: 0;
}

.award__select {
  min-width: 0;
}

/* В строке шага список не растягивается: рядом название материала, и отдать
   ему половину строки значило бы спрятать само название. */
.award--compact {
  flex: none;
  width: min(13rem, 100%);
}

/* Строка шага залита той же подложкой, что и сам список, и без рамки он на
   ней пропадает — выглядит как подпись, а не как поле, которое можно нажать. */
.award--compact :deep(.select-field__control) {
  padding-block: 0.4rem;
  border-color: var(--color-border);
  background: var(--color-surface);
  font-size: 0.9rem;
}
</style>
