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
 */
definePageMeta({ middleware: 'auth' })
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

/**
 * Все узлы по номеру и родителю — по ним считаются и «свой» куст, и запрет
 * бросить отдел на собственного потомка.
 */
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

/** Отделы, в которых числится смотрящий: на них метка «ваш отдел». */
const ownDepartmentIds = computed(() => (user.value?.departments ?? []).map(department => department.id))

/* ---------- Что раскрыто ---------- */

const expandedIds = ref<number[]>([])

function expand(id: number) {
  if (!expandedIds.value.includes(id)) {
    expandedIds.value = [...expandedIds.value, id]
  }
}

/**
 * При первом показе раскрыты корень и путь к своему отделу: человек видит и
 * компанию целиком, и место, где работает сам, — не раскрывая ветки руками.
 */
function openInitially() {
  expandedIds.value = roots.value.map(root => root.id)

  for (const own of ownDepartmentIds.value) {
    let current = flat.value.parents.get(own) ?? null

    while (current !== null) {
      expand(current)
      current = flat.value.parents.get(current) ?? null
    }
  }
}

watch(roots, (value, previous) => {
  if (previous === undefined || previous.length === 0) {
    openInitially()
  }
}, { immediate: true })

function toggle(id: number) {
  expandedIds.value = expandedIds.value.includes(id)
    ? expandedIds.value.filter(one => one !== id)
    : [...expandedIds.value, id]
}

/* ---------- Масштаб холста ---------- */

/**
 * Дерево разрастается вширь быстрее любого экрана, поэтому уменьшается оно
 * само, а не страница вокруг: заголовок, панель и рельса остаются в своём
 * размере — как в справочнике, из которого это срисовано.
 *
 * Свойство `zoom`, а не `transform: scale()`: `zoom` меняет саму раскладку, и
 * полосы прокрутки холста считают уменьшённое дерево правильно, тогда как
 * `transform` рисует его поверх прежнего места — и до правого края было бы не
 * докрутить.
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

/**
 * Колесо с Ctrl (на Маке — с ⌘) масштабирует дерево, а не страницу: браузер
 * этим сочетанием увеличивает документ, и без отмены события экран прыгал бы
 * вместе с ним.
 */
function onWheel(event: WheelEvent) {
  if (!event.ctrlKey && !event.metaKey) {
    return
  }

  event.preventDefault()
  setZoom(zoom.value - Math.sign(event.deltaY) * ZOOM_STEP)
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

  expand(parent.id)

  await act(
    () => createDepartment({ name, parent_id: parent.id }),
    'Не удалось создать отдел.',
  )
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

  await act(() => deleteDepartment(department.id), 'Не удалось удалить отдел.')
}

/* ---------- Перетаскивание ---------- */

const draggingId = ref<number | null>(null)
const dropHint = ref<string | null>(null)

/**
 * Бросить отдел на самого себя или на собственного потомка нельзя: дерево
 * замкнулось бы в кольцо. Сервер проверяет то же самое — здесь это ради того,
 * чтобы курсор честно показывал «сюда нельзя».
 */
function mayDropOn(id: number): boolean {
  const dragging = draggingId.value

  return dragging !== null && id !== dragging && !isDescendant(id, dragging)
}

function move(parentId: number, position: number) {
  const dragged = draggingId.value

  if (dragged === null) {
    return
  }

  draggingId.value = null
  dropHint.value = null
  expand(parentId)

  return act(
    () => moveDepartment(dragged, { parent_id: parentId, position }),
    'Не удалось перенести отдел.',
  )
}

/* ---------- Перетаскивание людей ---------- */

const draggingPerson = ref<DraggedPerson | null>(null)

/**
 * Человека несут в другой отдел. В свой же — не несут: это ничего не меняет, а
 * подсветка обещала бы перемену.
 */
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

  // Роль едет вместе с человеком, а прежний отдел его отпускает — обе половины
  // делает сервер разом, см. from_department_id.
  await act(
    () => addDepartmentPeople(departmentId, {
      user_ids: [person.id],
      role: person.role,
      from_department_id: person.fromDepartmentId,
    }),
    'Не удалось перенести сотрудника.',
  )
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
  // На карточку — подчинить последним; в промежуток — встать по порядку.
  dropOn: (id: number) => move(id, flat.value.nodes.get(id)?.children.length ?? 0),
  dropAt: move,

  draggingPerson,
  startPersonDrag: (person: DraggedPerson) => {
    draggingPerson.value = person
  },
  endPersonDrag: () => {
    draggingPerson.value = null
    dropHint.value = null
  },
  mayDropPersonOn,
  dropPersonOn: movePerson,
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
      <!-- Дерево шире экрана почти всегда: холст возит его вбок, а страница
           при этом остаётся на месте. -->
      <div class="canvas" @wheel="onWheel">
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

        <div class="canvas__inner" :style="{ zoom }">
          <StructureNode v-for="root in roots" :key="root.id" :node="root" />
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
.page-header {
  margin-bottom: 1.25rem;
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
  align-items: flex-start;
  gap: 1rem;
}

.canvas {
  position: relative;
  flex: 1;
  min-width: 0;
  overflow-x: auto;
  padding: 0.5rem 0 1.5rem;
}

/*
 * Кнопки масштаба висят над холстом и не уезжают вместе с деревом: холст
 * прокручивается вбок, а они прижаты к его правому верхнему углу.
 */
.zoom {
  position: sticky;
  top: 0;
  left: 0;
  z-index: 2;
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
 * Ряд корней по центру, пока помещается, и слева, когда дерево шире холста:
 * `justify-content: center` при переполнении срезал бы левый край, и до
 * первого отдела было бы не докрутить.
 */
.canvas__inner {
  display: flex;
  justify-content: safe center;
  gap: 2rem;
  /* Поля по краям: без них крайняя карточка липнет к обрезу холста и выглядит
     срезанной, даже когда прокручена до упора. */
  padding: 0 1rem;
  min-width: min-content;
}

@media (max-width: 60rem) {
  .layout {
    flex-direction: column;
  }
}
</style>
