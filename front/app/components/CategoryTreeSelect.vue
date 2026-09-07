<script setup lang="ts">
import type { Category } from '~/types/lms'

const props = withDefaults(defineProps<{
  categories: Category[]
  /** Excluded from the options along with its whole branch, to avoid cycles. */
  excludeId?: number | null
  /**
   * Разрешать ли «без категории».
   *
   * У материала — нет: каталог открывается списком категорий, и материал без
   * неё в навигации не существует (решение пользователя 2026-09-07). А вот у
   * самой категории пустой родитель — обычное дело: это категория верхнего
   * уровня, и запретить его значило бы запретить корень.
   */
  allowNone?: boolean
}>(), { allowNone: true })

const model = defineModel<number | null>({ required: true })

/**
 * Flattens the tree into indented options, since a list cannot nest. Depth is
 * shown with figure dashes so alignment survives any font.
 */
const options = computed(() => {
  const flat: { value: number | null, label: string }[] = props.allowNone
    ? [{ value: null, label: 'Без категории' }]
    : []

  const walk = (nodes: Category[], depth: number) => {
    for (const node of nodes) {
      if (node.id === props.excludeId) {
        continue
      }

      flat.push({ value: node.id, label: `${'‒ '.repeat(depth)}${node.name}` })
      walk(node.children ?? [], depth + 1)
    }
  }

  walk(props.categories, 0)

  return flat
})
</script>

<template>
  <UiSelect
    v-model="model"
    :options="options"
    :placeholder="allowNone ? 'Без категории' : 'Выберите категорию'"
  />
</template>
