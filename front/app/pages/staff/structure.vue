<script setup lang="ts">
import type { StructureContext } from '~/composables/useStructure'
import type { Department, DraggedPerson } from '~/types/structure'

/**
 * Структура компании — дерево отделов.
 *
 * Открыта всякому, кто вошёл: узнать, кто чем занимается и к кому идти с
 * вопросом, — не привилегия. Перерисовывает её администратор: карточки
 * перетаскиваются, отделы заводятся кнопкой «+» под карточкой, состав правится
 * в панели справа.
 *
 * Уровни рисуются рядами, а не вложением: ряд — это дети одного раскрытого
 * отдела, и раскрытым на уровне бывает ровно один. Так верхний ряд не
 * разъезжается, когда внизу раскрыли ветку из шести отделов, — он попросту о
 * ней не знает. Расплата за это — линии: связать ряды вложенностью уже нельзя,
 * и они рисуются поверх, по измеренным положениям карточек.
 *
 * Страница занимает экран целиком: дерево живёт на полотне, и возить надо его,
 * а не страницу под ним.
 */
definePageMeta({ middleware: 'auth', fills: true })
useHead({ title: 'Структура компании' })

const {
  fetchStructure,
  createDepartment,
  renameDepartment,
  moveDepartment,
  deleteDepartment,
  addDepartmentPeople,
} = useStructureApi()
const { isAdmin, user } = useAuth()
const { confirm, prompt } = useAppDialog()

const { data, pending, error, refresh } = await useAsyncData('structure', () => fetchStructure())

const roots = computed<Department[]>(() => data.value?.data ?? [])

const errorMessage = ref<string | null>(null)

/* ---------- Плоский взгляд на дерево ---------- */

/** Все узлы по номеру и родителю: по ним строятся ряды и запреты переноса. */
const flat = computed(() => {
  const nodes = new Map<number, Department>()
  const parents = new Map<number, number | null>()

  const walk = (node: Department) => {
    nodes.set(node.id, node)
    parents.set(node.id, node.parent_id)
    node.children.forEach(walk)
  }

  roots.value.forEach(walk)

  return { nodes, parents }
})

function isDescendant(candidateId: number, ancestorId: number): boolean {
  let current = flat.value.parents.get(candidateId) ?? null

  while (current !== null) {
    if (current === ancestorId) {
      return true
    }

    current = flat.value.parents.get(current) ?? null
  }

  return false
}

/** Путь от корня до узла — им же задаётся, какие ряды раскрыты. */
function pathTo(id: number): number[] {
  const path: number[] = []
  let current: number | null = id

  while (current !== null) {
    path.unshift(current)
    current = flat.value.parents.get(current) ?? null
  }

  return path
}

/** Отделы, в которых числится смотрящий: на них метка «ваш отдел». */
const ownDepartmentIds = computed(() => (user.value?.departments ?? []).map(department => department.id))

/* ---------- Раскрытые ряды ---------- */

/**
 * Что раскрыто — не набор, а путь: по одному отделу на уровень.
 *
 * Раскрыть второй отдел того же уровня нельзя не по запрету, а по устройству:
 * ряд ниже показывает детей одного узла, и второму там просто нет места.
 */
const openPath = ref<number[]>([])

const expandedIds = computed(() => openPath.value)

/** Ряды сверху вниз: корни, дети раскрытого, дети раскрытого в нём и так далее. */
const levels = computed<Department[][]>(() => {
  const rows: Department[][] = [roots.value]

  for (const id of openPath.value) {
    const node = flat.value.nodes.get(id)

    if (!node || node.children.length === 0) {
      break
    }

    rows.push(node.children)
  }

  return rows.filter(row => row.length > 0)
})

function toggle(id: number) {
  // Закрыть — значит обрезать путь на этом узле: раскрытое ниже закрывается
  // вместе с ним.
  openPath.value = openPath.value.includes(id) ? pathTo(id).slice(0, -1) : pathTo(id)

  void redraw()
}

function open(id: number) {
  openPath.value = pathTo(id)
  void redraw()
}

/**
 * При первом показе раскрыт путь к своему отделу: человек видит и компанию
 * целиком, и место, где работает сам.
 */
function openInitially() {
  const own = ownDepartmentIds.value.find(id => flat.value.nodes.has(id))

  openPath.value = own === undefined
    ? roots.value.slice(0, 1).map(root => root.id)
    : pathTo(own)
}

watch(roots, (value, previous) => {
  if (previous === undefined || previous.length === 0) {
    openInitially()
  }

  void redraw()
}, { immediate: true })

/* ---------- Где стоит ряд ---------- */

/** Промежуток между карточками в ряду — держится в паре с `.level { gap }`. */
const ROW_GAP = 19.2

/** Ширина карточки: меряется у настоящей, чтобы не расходиться со стилями. */
const cardWidth = ref(248)

/**
 * Сдвиг каждого ряда влево-вправо: ряд стоит строго под своим родителем.
 *
 * Считается, а не измеряется: ширина ряда складывается из одинаковых карточек,
 * а середина родителя — из его места в своём ряду. Измерять было бы нельзя —
 * положение ряда зависит от этого же сдвига, и счёт пошёл бы по кругу.
 */
const rowOffsets = computed<number[]>(() => {
  const width = cardWidth.value
  const offsets: number[] = []

  levels.value.forEach((row, depth) => {
    const rowWidth = row.length * width + Math.max(0, row.length - 1) * ROW_GAP

    if (depth === 0) {
      offsets.push(0)

      return
    }

    const parentId = openPath.value[depth - 1]
    const index = Math.max(0, levels.value[depth - 1]?.findIndex(node => node.id === parentId) ?? 0)
    const parentCentre = (offsets[depth - 1] ?? 0) + index * (width + ROW_GAP) + width / 2

    offsets.push(parentCentre - rowWidth / 2)
  })

  // Крайний левый ряд прижимается к нулю: отрицательный сдвиг увёл бы дерево
  // за край холста, откуда до него не докрутить.
  const leftmost = Math.min(0, ...offsets)

  return offsets.map(offset => offset - leftmost)
})

/* ---------- Масштаб холста ---------- */

/**
 * Дерево разрастается вширь быстрее любого экрана, поэтому уменьшается оно
 * само, а не страница вокруг.
 *
 * Свойство `zoom`, а не `transform: scale()`: `zoom` меняет саму раскладку, и
 * полосы прокрутки холста считают уменьшённое дерево правильно.
 */
const MIN_ZOOM = 0.4
const MAX_ZOOM = 1.5
const ZOOM_STEP = 0.1

const zoom = ref(1)

function setZoom(value: number) {
  zoom.value = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, Math.round(value * 100) / 100))
}

function zoomIn() {
  setZoom(zoom.value + ZOOM_STEP)
}

function zoomOut() {
  setZoom(zoom.value - ZOOM_STEP)
}

watch(zoom, () => void redraw())

/**
 * Колесо с Ctrl (на Маке — с ⌘) масштабирует дерево, а не страницу. Щипок на
 * трекпаде приходит тем же событием, поэтому шаг берётся от самого движения:
 * пальцами масштабируют плавно, колесом — щелчками.
 *
 * Без Ctrl колесо возит полотно — этим занимается сам холст, как в редакторах
 * схем: вниз-вверх колесом, вбок с Shift или двумя пальцами.
 */
function onWheel(event: WheelEvent) {
  if (!event.ctrlKey && !event.metaKey) {
    return
  }

  event.preventDefault()

  const step = Math.abs(event.deltaY) < 12
    ? event.deltaY * 0.01
    : Math.sign(event.deltaY) * ZOOM_STEP

  void zoomAt(zoom.value - step, event.clientX, event.clientY)
}

/**
 * Масштабирует так, чтобы точка под курсором осталась на месте: иначе дерево
 * уезжает из-под руки — увеличиваешь отдел справа, а приближается середина.
 */
async function zoomAt(next: number, clientX: number, clientY: number) {
  const element = canvas.value
  const before = zoom.value

  setZoom(next)

  if (!element || zoom.value === before) {
    return
  }

  const frame = element.getBoundingClientRect()
  const x = clientX - frame.left
  const y = clientY - frame.top

  const pointX = (element.scrollLeft + x) / before
  const pointY = (element.scrollTop + y) / before

  await nextTick()

  const smooth = element.style.scrollBehavior

  element.style.scrollBehavior = 'auto'
  element.scrollLeft = pointX * zoom.value - x
  element.scrollTop = pointY * zoom.value - y
  element.style.scrollBehavior = smooth
}

/* ---------- Линии между рядами ---------- */

interface Wire {
  path: string
  active: boolean
  arrow: string | null
}

const board = ref<HTMLElement | null>(null)
const wires = ref<Wire[]>([])

/** Радиус закругления на поворотах линии. */
const BEND = 12

/**
 * Рисует связи по измеренным положениям карточек.
 *
 * Ряды лежат независимо друг от друга, поэтому родитель почти никогда не стоит
 * ровно над своими детьми: линия идёт от него вниз, вбок по общей поперечине и
 * снова вниз — с закруглениями на поворотах, как чертят схемы.
 */
async function redraw() {
  await nextTick()

  const plane = board.value

  if (!plane) {
    return
  }

  const scale = zoom.value
  const frame = plane.getBoundingClientRect()

  // Ширина карточки — из разметки: подставлять её числом значит однажды
  // разойтись со стилями и получить ряды, стоящие мимо родителя.
  const sample = plane.querySelector('[data-unit] .dept')

  if (sample) {
    cardWidth.value = sample.getBoundingClientRect().width / scale
  }

  const box = (id: number) => {
    const element = plane.querySelector(`[data-unit="${id}"]`)

    if (!element) {
      return null
    }

    const rect = element.getBoundingClientRect()

    return {
      x: (rect.left + rect.width / 2 - frame.left) / scale,
      top: (rect.top - frame.top) / scale,
      bottom: (rect.bottom - frame.top) / scale,
    }
  }

  const drawn: Wire[] = []

  openPath.value.forEach((parentId, depth) => {
    const children = levels.value[depth + 1]
    const from = box(parentId)

    if (!children || !from) {
      return
    }

    const targets = children
      .map(child => ({ child, at: box(child.id) }))
      .filter((one): one is { child: Department, at: { x: number, top: number, bottom: number } } => one.at !== null)

    if (targets.length === 0) {
      return
    }

    // Поперечина посередине между рядами — общая для всех детей.
    const bus = from.bottom + (Math.min(...targets.map(one => one.at.top)) - from.bottom) / 2

    for (const { child, at } of targets) {
      /*
       * Светится одна дорога, а не веер: та, что ведёт к раскрытому отделу и
       * дальше к выбранному. Подсвеченный веер к шести детям разом ничего не
       * говорит — по нему не видно, где ты, а видно только, что уровень открыт.
       */
      const active = openPath.value.includes(child.id) || selectedId.value === child.id

      drawn.push({
        path: wire(from.x, from.bottom, at.x, at.top, bus),
        active,
        // Стрелка — на конце подсвеченной линии: «вот сюда вы смотрите».
        arrow: active
          ? `${at.x},${at.top} ${at.x - 5},${at.top - 7} ${at.x + 5},${at.top - 7}`
          : null,
      })
    }
  })

  wires.value = drawn
}

/** Линия «вниз — вбок — вниз» со скруглёнными поворотами. */
function wire(fromX: number, fromY: number, toX: number, toY: number, bus: number): string {
  if (Math.abs(toX - fromX) < 1) {
    return `M ${fromX} ${fromY} L ${toX} ${toY}`
  }

  const side = toX > fromX ? 1 : -1
  const bend = Math.min(BEND, Math.abs(toX - fromX) / 2, Math.abs(bus - fromY), Math.abs(toY - bus))

  return [
    `M ${fromX} ${fromY}`,
    `L ${fromX} ${bus - bend}`,
    `Q ${fromX} ${bus} ${fromX + side * bend} ${bus}`,
    `L ${toX - side * bend} ${bus}`,
    `Q ${toX} ${bus} ${toX} ${bus + bend}`,
    `L ${toX} ${toY}`,
  ].join(' ')
}

/*
 * Карточки меняют высоту, когда догружаются аватарки, а ряды — ширину при
 * раскрытии: линии обязаны идти следом, иначе повиснут в пустоте.
 */
let watcher: ResizeObserver | null = null

onMounted(() => {
  void redraw()

  watcher = new ResizeObserver(() => void redraw())

  if (board.value) {
    watcher.observe(board.value)
  }
})

onBeforeUnmount(() => watcher?.disconnect())

/* ---------- Перетаскивание холста ---------- */

const canvas = ref<HTMLElement | null>(null)
const panning = ref(false)

let grabbed = { x: 0, y: 0, left: 0, top: 0 }

function onPanStart(event: PointerEvent) {
  const element = canvas.value
  const onCard = (event.target as HTMLElement).closest('.card, button, a, input') !== null

  // Средней кнопкой возят откуда угодно, левой — только с пустого места: на
  // карточках своё перетаскивание, и перехватывать его нельзя.
  if (!element || (event.button !== 1 && (event.button !== 0 || onCard))) {
    return
  }

  panning.value = true
  grabbed = { x: event.clientX, y: event.clientY, left: element.scrollLeft, top: element.scrollTop }
  element.setPointerCapture(event.pointerId)
}

function onPanMove(event: PointerEvent) {
  const element = canvas.value

  if (!panning.value || !element) {
    return
  }

  element.scrollLeft = grabbed.left - (event.clientX - grabbed.x)
  element.scrollTop = grabbed.top - (event.clientY - grabbed.y)
}

function onPanEnd(event: PointerEvent) {
  panning.value = false
  canvas.value?.releasePointerCapture?.(event.pointerId)
}

/* ---------- Панель отдела ---------- */

const selectedId = ref<number | null>(null)
const selected = computed(() => (selectedId.value === null ? null : flat.value.nodes.get(selectedId.value) ?? null))

/* ---------- Правка ---------- */

async function act(action: () => Promise<unknown>, fallback: string) {
  errorMessage.value = null

  try {
    await action()
    await refresh()
    await redraw()
  }
  catch (caught) {
    errorMessage.value = (caught as { data?: { message?: string } }).data?.message ?? fallback
  }
}

async function addChild(parent: Department) {
  const name = await prompt({
    title: 'Новый отдел',
    message: `Он встанет в подчинение «${parent.name}» последним — место среди соседей выбирается перетаскиванием.`,
    label: 'Название',
    placeholder: 'Например, Финансовый департамент',
    confirmLabel: 'Создать',
  })

  if (name === null) {
    return
  }

  await act(() => createDepartment({ name, parent_id: parent.id }), 'Не удалось создать отдел.')

  // Раскрываем ряд, в котором он появился, — иначе нового отдела не увидеть.
  open(parent.id)
}

async function rename(department: Department) {
  const name = await prompt({
    title: 'Название отдела',
    label: 'Как он называется',
    value: department.name,
  })

  if (name === null || name === department.name) {
    return
  }

  await act(() => renameDepartment(department.id, name), 'Не удалось переименовать отдел.')
}

async function remove(department: Department) {
  const confirmed = await confirm({
    title: `Удалить «${department.name}»?`,
    message: 'Подчинённые отделы поднимутся уровнем выше, а люди останутся в системе — они потеряют только эту приписку.',
    confirmLabel: 'Удалить',
    danger: true,
  })

  if (!confirmed) {
    return
  }

  if (selectedId.value === department.id) {
    selectedId.value = null
  }

  const parent = flat.value.parents.get(department.id) ?? null

  await act(() => deleteDepartment(department.id), 'Не удалось удалить отдел.')

  openPath.value = parent === null ? [] : pathTo(parent)
}

/* ---------- Перетаскивание отделов ---------- */

const draggingId = ref<number | null>(null)
const dropHint = ref<string | null>(null)

function mayDropOn(id: number): boolean {
  const dragging = draggingId.value

  return dragging !== null && id !== dragging && !isDescendant(id, dragging)
}

async function move(parentId: number, position: number) {
  const dragged = draggingId.value

  if (dragged === null) {
    return
  }

  draggingId.value = null
  dropHint.value = null

  await act(
    () => moveDepartment(dragged, { parent_id: parentId, position }),
    'Не удалось перенести отдел.',
  )

  open(parentId)
}

/* ---------- Перетаскивание людей ---------- */

const draggingPerson = ref<DraggedPerson | null>(null)

function mayDropPersonOn(departmentId: number): boolean {
  return draggingPerson.value !== null && draggingPerson.value.fromDepartmentId !== departmentId
}

async function movePerson(departmentId: number) {
  const person = draggingPerson.value

  draggingPerson.value = null
  dropHint.value = null

  if (person === null || person.fromDepartmentId === departmentId) {
    return
  }

  await act(
    () => addDepartmentPeople(departmentId, {
      user_ids: [person.id],
      role: person.role,
      from_department_id: person.fromDepartmentId,
    }),
    'Не удалось перенести сотрудника.',
  )
}

/* ---------- Промежутки между соседями ---------- */

function slotHint(parentId: number, position: number): string {
  return `slot:${parentId}:${position}`
}

function onSlotOver(event: DragEvent, parentId: number, position: number) {
  if (draggingId.value === null || !mayDropOn(parentId)) {
    return
  }

  event.preventDefault()
  event.dataTransfer!.dropEffect = 'move'
  dropHint.value = slotHint(parentId, position)
}

function onSlotDrop(event: DragEvent, parentId: number, position: number) {
  if (draggingId.value === null || !mayDropOn(parentId)) {
    return
  }

  event.preventDefault()
  event.stopPropagation()
  void move(parentId, position)
}

const structure: StructureContext = {
  editable: computed(() => isAdmin.value),
  ownDepartmentIds,
  expandedIds,
  selectedId,
  draggingId,
  dropHint,
  toggle,
  select: (id: number) => {
    selectedId.value = selectedId.value === id ? null : id
    void redraw()
  },
  addChild,
  rename,
  remove,
  startDrag: (id: number) => {
    draggingId.value = id
  },
  endDrag: () => {
    draggingId.value = null
    dropHint.value = null
  },
  mayDropOn,
  dropOn: (id: number) => void move(id, flat.value.nodes.get(id)?.children.length ?? 0),
  dropAt: (parentId: number, position: number) => void move(parentId, position),

  draggingPerson,
  startPersonDrag: (person: DraggedPerson) => {
    draggingPerson.value = person
  },
  endPersonDrag: () => {
    draggingPerson.value = null
    dropHint.value = null
  },
  mayDropPersonOn,
  dropPersonOn: (id: number) => void movePerson(id),
}

provide(structureKey, structure)
</script>

<template>
  <section class="structure">
    <header class="page-header">
      <div>
        <h1>Структура компании</h1>
        <p class="muted">
          {{ isAdmin
            ? 'Перетаскивайте карточки, чтобы переподчинить отдел, а людей — чтобы перевести их в другой. Новый отдел заводится кнопкой «+» под карточкой.'
            : 'Кто чем занимается и к кому идти с вопросом. Щёлкните по отделу, чтобы увидеть его состав.' }}
        </p>
      </div>
    </header>

    <p v-if="errorMessage" class="auth-alert" role="alert">
      {{ errorMessage }}
    </p>

    <p v-if="pending" class="muted">
      Загрузка…
    </p>

    <p v-else-if="error" class="auth-alert" role="alert">
      Не удалось загрузить структуру компании.
    </p>

    <div v-else class="layout">
      <div
        ref="canvas"
        class="canvas"
        :class="{ 'canvas--panning': panning }"
        @wheel="onWheel"
        @pointerdown="onPanStart"
        @pointermove="onPanMove"
        @pointerup="onPanEnd"
        @pointercancel="onPanEnd"
      >
        <div class="zoom">
          <button type="button" class="zoom__button" :disabled="zoom <= 0.4" aria-label="Уменьшить" @click="zoomOut">
            −
          </button>
          <button type="button" class="zoom__value" title="Вернуть обычный размер" @click="setZoom(1)">
            {{ Math.round(zoom * 100) }}%
          </button>
          <button type="button" class="zoom__button" :disabled="zoom >= 1.5" aria-label="Увеличить" @click="zoomIn">
            +
          </button>
        </div>

        <div ref="board" class="board" :style="{ zoom }">
          <!-- Связи между рядами: рисуются поверх и щелчков не перехватывают. -->
          <svg class="wires" aria-hidden="true">
            <path
              v-for="(line, index) in wires"
              :key="`wire-${index}`"
              :d="line.path"
              class="wire"
              :class="{ 'wire--on': line.active }"
            />
            <polygon
              v-for="(line, index) in wires.filter(one => one.arrow !== null)"
              :key="`arrow-${index}`"
              :points="line.arrow ?? ''"
              class="wire__arrow"
            />
          </svg>

          <div
            v-for="(row, depth) in levels"
            :key="depth"
            class="level"
            :style="{ marginLeft: `${rowOffsets[depth] ?? 0}px` }"
          >
            <div v-for="(node, index) in row" :key="node.id" :data-unit="node.id" class="unit">
              <span
                class="slot"
                :class="{ 'slot--active': draggingId !== null, 'slot--hot': dropHint === slotHint(node.parent_id ?? 0, index) }"
                @dragover="onSlotOver($event, node.parent_id ?? 0, index)"
                @dragleave="dropHint === slotHint(node.parent_id ?? 0, index) && (dropHint = null)"
                @drop="onSlotDrop($event, node.parent_id ?? 0, index)"
              />

              <StructureDepartmentCard :node="node" />

              <!-- «+» под карточкой: так добавляют дочерний отдел, не открывая меню. -->
              <button
                v-if="isAdmin"
                type="button"
                class="unit__add"
                :aria-label="`Добавить отдел в «${node.name}»`"
                @click="addChild(node)"
              >
                +
              </button>

              <span
                v-if="index === row.length - 1"
                class="slot slot--last"
                :class="{ 'slot--active': draggingId !== null, 'slot--hot': dropHint === slotHint(node.parent_id ?? 0, index + 1) }"
                @dragover="onSlotOver($event, node.parent_id ?? 0, index + 1)"
                @dragleave="dropHint === slotHint(node.parent_id ?? 0, index + 1) && (dropHint = null)"
                @drop="onSlotDrop($event, node.parent_id ?? 0, index + 1)"
              />
            </div>
          </div>
        </div>
      </div>

      <StructureDepartmentPanel
        v-if="selected"
        :department="selected"
        :editable="isAdmin"
        @close="selectedId = null"
        @changed="refresh()"
      />
    </div>
  </section>
</template>

<style scoped>
.structure {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
}

.page-header {
  margin-bottom: 1rem;
}

.page-header h1 {
  margin: 0 0 0.25rem;
  font-size: 1.5rem;
}

.muted {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.layout {
  display: flex;
  align-items: stretch;
  gap: 1rem;
  flex: 1;
  min-height: 0;
}

/*
 * Полотно прокручивается само, обеими осями: страница под ним стоит на месте,
 * и колесо всегда возит одно и то же — как в редакторах схем.
 */
.canvas {
  position: relative;
  flex: 1;
  min-width: 0;
  overflow: auto;
  padding: 0.5rem 0 1.5rem;
  border-radius: var(--radius);
  cursor: grab;
  scroll-behavior: smooth;
  overscroll-behavior: contain;
}

.canvas--panning {
  cursor: grabbing;
  /* Пока тащат руками, плавность мешает: холст должен идти за пальцем. */
  scroll-behavior: auto;
  user-select: none;
}

/* Кнопки масштаба висят над холстом и не уезжают вместе с деревом. */
.zoom {
  position: sticky;
  top: 0;
  left: 0;
  z-index: 3;
  display: flex;
  align-items: center;
  gap: 0.15rem;
  width: fit-content;
  margin-left: auto;
  padding: 0.15rem;
  border-radius: var(--radius-pill);
  background: var(--color-surface);
}

.zoom__button,
.zoom__value {
  border: 0;
  background: transparent;
  color: var(--color-text-muted);
  font: inherit;
  line-height: 1;
  cursor: pointer;
  border-radius: var(--radius-pill);
}

.zoom__button {
  width: 1.75rem;
  height: 1.75rem;
  font-size: 1rem;
}

.zoom__value {
  padding: 0 0.5rem;
  font-size: 0.78rem;
  font-variant-numeric: tabular-nums;
}

.zoom__button:hover:not(:disabled),
.zoom__value:hover {
  background: var(--color-surface-sunken);
  color: var(--color-text);
}

.zoom__button:disabled {
  opacity: 0.4;
  cursor: default;
}

/*
 * Ряды. Каждый сам по себе: раскрытая внизу ветка не раздвигает тех, кто стоит
 * выше, потому что они о ней ничего не знают.
 */
.board {
  position: relative;
  display: flex;
  flex-direction: column;
  /* Ряды не центруются по отдельности: каждый встаёт под своего родителя сам. */
  align-items: flex-start;
  gap: 3.2rem;
  /*
   * Ширина по содержимому и поля-автоматы: пока дерево уже холста, оно стоит
   * посередине; стало шире — поля схлопываются, дерево прижимается к левому
   * краю и дальше его возят прокруткой.
   */
  width: max-content;
  margin: 0 auto;
  padding: 0 2rem 1rem;
}

.level {
  display: flex;
  align-items: flex-start;
  gap: 1.2rem;
}

.unit {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.45rem;
}

.unit__add {
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
  transition: background-color 0.15s ease, color 0.15s ease, border-color 0.15s ease, transform 0.15s ease;
}

.unit__add:hover {
  border-color: transparent;
  background: var(--color-accent);
  color: var(--color-accent-text);
  transform: scale(1.08);
}

/*
 * Линии поверх рядов. Не перехватывают ни щелчков, ни перетаскивания: они
 * ничего не значат сами по себе — это чертёж.
 */
.wires {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  overflow: visible;
  pointer-events: none;
}

.wire {
  fill: none;
  stroke: var(--color-border-strong);
  stroke-width: 1.5;
  stroke-linecap: round;
  transition: stroke 0.2s ease;
}

/* Раскрытая ветка светится по всей длине — от родителя до стрелки у ребёнка. */
.wire--on {
  stroke: var(--color-highlight-strong);
  stroke-width: 2;
}

.wire__arrow {
  fill: var(--color-highlight-strong);
}

/*
 * Промежуток между соседями: сюда бросают, чтобы встать по порядку. Появляется
 * только когда что-то тащат, иначе перехватывал бы обычные щелчки.
 */
.slot {
  position: absolute;
  top: 0;
  bottom: 1.5rem;
  left: -0.85rem;
  width: 1.2rem;
  border-radius: var(--radius-pill);
  pointer-events: none;
}

.slot--last {
  left: auto;
  right: -0.85rem;
}

.slot--active {
  pointer-events: auto;
}

.slot--hot {
  background: color-mix(in srgb, var(--color-highlight) 45%, transparent);
}

@media (max-width: 60rem) {
  .layout {
    flex-direction: column;
  }

  /* На узком экране полотну хватит части высоты: панель встаёт под ним. */
  .canvas {
    flex: none;
    height: 60vh;
  }
}
</style>
