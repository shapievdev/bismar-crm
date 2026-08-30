<script setup lang="ts">
import type { Department } from '~/types/structure'

/**
 * Узел дерева: карточка отдела и, если он раскрыт, ряд его детей.
 *
 * Компонент вызывает сам себя — этим и рисуется вложенность любой глубины.
 * Линии между уровнями чертит CSS: вертикальная ножка от родителя вниз и
 * поперечина над детьми, у крайних обрезанная до середины.
 */
const props = defineProps<{ node: Department }>()

const structure = useStructure()

/** Раскрыт ли узел — и есть ли что показывать под ним. */
const isOpen = computed(() =>
  structure.expandedIds.value.includes(props.node.id) && props.node.children.length > 0)

/** Промежуток между соседями: сюда бросают, чтобы встать по порядку. */
function slotHint(parentId: number, position: number): string {
  return `slot:${parentId}:${position}`
}

function onSlotOver(event: DragEvent, parentId: number, position: number) {
  if (structure.draggingId.value === null || !structure.mayDropOn(parentId)) {
    return
  }

  event.preventDefault()
  event.dataTransfer!.dropEffect = 'move'
  structure.dropHint.value = slotHint(parentId, position)
}

function onSlotDrop(event: DragEvent, parentId: number, position: number) {
  if (structure.draggingId.value === null || !structure.mayDropOn(parentId)) {
    return
  }

  event.preventDefault()
  event.stopPropagation()
  structure.dropAt(parentId, position)
}
</script>

<template>
  <div class="node">
    <StructureDepartmentCard :node="node" />

    <!--
      Стык с нижним уровнем: линия вниз, а «+» сидит прямо на ней — так он
      читается как «добавить сюда, в подчинение», а не как отдельная кнопка,
      повисшая под карточкой.
    -->
    <div
      v-if="structure.editable.value || isOpen"
      class="node__link"
      :class="{ 'node__link--stub': !isOpen }"
    >
      <button
        v-if="structure.editable.value"
        type="button"
        class="node__add"
        :aria-label="`Добавить отдел в «${node.name}»`"
        @click="structure.addChild(node)"
      >
        +
      </button>
    </div>

    <template v-if="isOpen">
      <div class="node__children">
        <div
          v-for="(child, index) in node.children"
          :key="child.id"
          class="child"
          :class="{ 'child--only': node.children.length === 1 }"
        >
          <span
            class="slot"
            :class="{ 'slot--active': structure.draggingId.value !== null, 'slot--hot': structure.dropHint.value === slotHint(node.id, index) }"
            @dragover="onSlotOver($event, node.id, index)"
            @dragleave="structure.dropHint.value === slotHint(node.id, index) && (structure.dropHint.value = null)"
            @drop="onSlotDrop($event, node.id, index)"
          />

          <StructureNode :node="child" />

          <span
            v-if="index === node.children.length - 1"
            class="slot slot--last"
            :class="{ 'slot--active': structure.draggingId.value !== null, 'slot--hot': structure.dropHint.value === slotHint(node.id, index + 1) }"
            @dragover="onSlotOver($event, node.id, index + 1)"
            @dragleave="structure.dropHint.value === slotHint(node.id, index + 1) && (structure.dropHint.value = null)"
            @drop="onSlotDrop($event, node.id, index + 1)"
          />
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.node {
  display: flex;
  flex-direction: column;
  align-items: center;
}

/* Ножка от карточки вниз: сквозь неё проходит линия, на ней сидит «+». */
.node__link {
  position: relative;
  display: grid;
  place-items: center;
  width: 100%;
  height: 2.3rem;
}

.node__link::before {
  content: '';
  position: absolute;
  top: 0;
  bottom: 0;
  left: 50%;
  width: 1px;
  background: var(--color-border-strong);
}

/* Отделов ниже нет — линии ниже кнопки тоже: ей некуда вести. */
.node__link--stub::before {
  bottom: 50%;
}

.node__add {
  position: relative;
  z-index: 1;
  display: grid;
  place-items: center;
  width: 1.5rem;
  height: 1.5rem;
  border: 1px solid var(--color-border);
  border-radius: 50%;
  background: var(--color-surface);
  color: var(--color-text-muted);
  font-size: 0.9rem;
  line-height: 1;
  cursor: pointer;
  transition: background-color 0.15s ease, color 0.15s ease, border-color 0.15s ease;
}

.node__add:hover {
  border-color: transparent;
  background: var(--color-accent);
  color: var(--color-accent-text);
}

.node__children {
  display: flex;
  align-items: flex-start;
}

/*
 * Поперечина и ножка ребёнка — на псевдоэлементах самого ребёнка: так линия
 * тянется ровно от середины первого до середины последнего и не выезжает за
 * ряд, сколько бы их ни было.
 */
.child {
  position: relative;
  padding: 1.1rem 0.6rem 0;
}

.child::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 1px;
  background: var(--color-border-strong);
}

.child:first-child::before {
  left: 50%;
}

.child:last-child::before {
  right: 50%;
}

.child--only::before {
  left: 50%;
  right: 50%;
}

.child::after {
  content: '';
  position: absolute;
  top: 0;
  left: 50%;
  width: 1px;
  height: 1.1rem;
  background: var(--color-border-strong);
}

/*
 * Промежуток между соседями. Не занимает места в потоке: карточки стоят
 * вплотную к своим отступам, а зона появляется поверх них — и только когда
 * что-то тащат, иначе она перехватывала бы обычные щелчки.
 */
.slot {
  position: absolute;
  top: 1.1rem;
  bottom: 0;
  left: -0.35rem;
  width: 1.3rem;
  border-radius: var(--radius-pill);
  pointer-events: none;
}

.slot--last {
  left: auto;
  right: -0.35rem;
}

.slot--active {
  pointer-events: auto;
}

.slot--hot {
  background: color-mix(in srgb, var(--color-highlight) 45%, transparent);
}
</style>
