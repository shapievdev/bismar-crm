<script setup lang="ts">
import type { Category } from '~/types/lms'

const props = defineProps<{
  categories: Category[]
  /** Excluded from the options along with its whole branch, to avoid cycles. */
  excludeId?: number | null
}>()

const model = defineModel<number | null>({ required: true })

/**
 * Flattens the tree into indented options, since a list cannot nest. Depth is
 * shown with figure dashes so alignment survives any font.
 */
const options = computed(() => {
  const flat: { value: number | null, label: string }[] = [{ value: null, label: 'Без категории' }]

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
  <UiSelect v-model="model" :options="options" placeholder="Без категории" />
</template>
