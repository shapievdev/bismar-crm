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
  /**
   * Только последние категории дерева — те, у которых нет вложенных.
   *
   * Так выбирают категорию материалу (решение пользователя 2026-09-30):
   * родительская — развилка, в ней выбирают подкатегорию, а материалов не ждут.
   * Родителю самой категории, наоборот, годится любая: иначе дерево глубже двух
   * уровней не собрать.
   */
  leavesOnly?: boolean
}>(), { allowNone: true, leavesOnly: false })

const model = defineModel<number | null>({ required: true })

/**
 * Flattens the tree into indented options, since a list cannot nest. Depth is
 * shown with figure dashes so alignment survives any font.
 *
 * Отбирая последние категории, отступы теряют смысл — вложенности в списке
 * больше нет, — и вместо них у варианта стоит дорога к нему: категорий с
 * одинаковым именем в разных ветках хватает («Регламенты» и в HR, и в
 * продажах), и различить их иначе нечем. Поиск в списке читает и пояснение,
 * поэтому по названию родителя находятся все его дети.
 */
const options = computed(() => {
  const flat: { value: number | null, label: string, hint?: string }[] = props.allowNone
    ? [{ value: null, label: 'Без категории' }]
    : []

  const walk = (nodes: Category[], depth: number, trail: string[]) => {
    for (const node of nodes) {
      if (node.id === props.excludeId) {
        continue
      }

      const children = node.children ?? []
      const isLeaf = children.length === 0

      if (!props.leavesOnly) {
        flat.push({ value: node.id, label: `${'‒ '.repeat(depth)}${node.name}` })
      }
      // Выбранную сейчас категорию оставляем в списке, даже если она с
      // вложенными: так привязывали до этого правила, и без неё поле молчало бы
      // «выберите категорию» о материале, у которого она есть.
      else if (isLeaf || node.id === model.value) {
        flat.push({
          value: node.id,
          label: node.name,
          hint: isLeaf
            ? (trail.length ? trail.join(' → ') : undefined)
            : 'В этой категории есть вложенные — выберите последнюю',
        })
      }

      walk(children, depth + 1, [...trail, node.name])
    }
  }

  walk(props.categories, 0, [])

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
