<script setup lang="ts">
import type { Category } from '~/types/lms'

const props = defineProps<{
  categories: Category[]
  /** Excluded from the options along with its whole branch, to avoid cycles. */
  excludeId?: number | null
}>()

const model = defineModel<number | null>({ required: true })

interface Option { id: number, label: string }

/**
 * Flattens the tree into indented options, since a native <select> cannot
 * nest. Depth is shown with figure dashes so alignment survives any font.
 */
const options = computed<Option[]>(() => {
  const flat: Option[] = []

  const walk = (nodes: Category[], depth: number) => {
    for (const node of nodes) {
      if (node.id === props.excludeId) {
        continue
      }

      flat.push({ id: node.id, label: `${'‒ '.repeat(depth)}${node.name}` })
      walk(node.children ?? [], depth + 1)
    }
  }

  walk(props.categories, 0)

  return flat
})
</script>

<template>
  <select v-model="model" class="select">
    <option :value="null">
      Без категории
    </option>
    <option v-for="option in options" :key="option.id" :value="option.id">
      {{ option.label }}
    </option>
  </select>
</template>
