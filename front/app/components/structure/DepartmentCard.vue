<script setup lang="ts">
import type { Department, DepartmentPerson } from '~/types/structure'

/**
 * Карточка отдела — то, из чего сложено дерево.
 *
 * Числа на ней разные и путать их нельзя: рядом с именем руководителя —
 * сколько людей во всём его кусте вместе с вложенными отделами, а
 * «Подчинённые» — прямые участники этого отдела.
 */
const props = defineProps<{ node: Department }>()

const structure = useStructure()

const isOwn = computed(() => structure.ownDepartmentIds.value.includes(props.node.id))
const isSelected = computed(() => structure.selectedId.value === props.node.id)
const isExpanded = computed(() => structure.expandedIds.value.includes(props.node.id))
const isDragging = computed(() => structure.draggingId.value === props.node.id)
const isDropTarget = computed(() => structure.dropHint.value === `card:${props.node.id}`)

/** Второй руководитель показывается, остальные сворачиваются в «+N». */
const shownHeads = computed(() => props.node.heads.slice(0, 2))
const hiddenHeads = computed(() => Math.max(0, props.node.heads.length - shownHeads.value.length))

/** Двое заместителей помещаются в колонку; остальные — строкой «и ещё N». */
const shownDeputies = computed(() => props.node.deputies.slice(0, 2))
const hiddenDeputies = computed(() => Math.max(0, props.node.deputies.length - shownDeputies.value.length))

/** Сколько подчинённых не поместилось в ряд лиц. */
const hiddenMembers = computed(() => Math.max(0, props.node.members_count - props.node.members.length))

const membersLabel = computed(() =>
  `${props.node.members_count} ${pluralise(props.node.members_count, 'сотрудник', 'сотрудника', 'сотрудников')}`)

const childrenLabel = computed(() =>
  `${props.node.children_count} ${pluralise(props.node.children_count, 'отдел', 'отдела', 'отделов')}`)

const menuOpen = ref(false)

function withMenuClosed(action: () => void) {
  menuOpen.value = false
  action()
}

/* ---------- Перетаскивание ---------- */

function onDragStart(event: DragEvent) {
  if (!structure.editable.value || props.node.is_root) {
    event.preventDefault()

    return
  }

  // Данные кладутся, хотя читает их не браузер, а мы сами: без них Safari
  // считает перетаскивание пустым и не начинает его вовсе.
  event.dataTransfer?.setData('text/plain', String(props.node.id))
  event.dataTransfer!.effectAllowed = 'move'
  structure.startDrag(props.node.id)
}

/** Карточка принимает и отдел (подчинить), и человека (перевести к себе). */
function accepts(): boolean {
  return structure.mayDropOn(props.node.id) || structure.mayDropPersonOn(props.node.id)
}

function onDragOver(event: DragEvent) {
  if (!accepts()) {
    return
  }

  // Отмена события — то самое разрешение бросить: без неё браузер не даст.
  event.preventDefault()
  event.dataTransfer!.dropEffect = 'move'
  structure.dropHint.value = `card:${props.node.id}`
}

function onDragLeave() {
  if (isDropTarget.value) {
    structure.dropHint.value = null
  }
}

function onDrop(event: DragEvent) {
  if (!accepts()) {
    return
  }

  event.preventDefault()
  event.stopPropagation()

  // Человек важнее отдела: если тащат его, отдел никто не тащит.
  if (structure.draggingPerson.value !== null) {
    structure.dropPersonOn(props.node.id)

    return
  }

  structure.dropOn(props.node.id)
}

/**
 * Руководителя и заместителя можно унести с карточки в другой отдел.
 *
 * Событие останавливается: карточка тоже умеет перетаскиваться, и без этого
 * браузер начал бы тащить отдел вместо человека.
 */
function onPersonDragStart(event: DragEvent, person: DepartmentPerson) {
  if (!structure.editable.value || person.role === null) {
    return
  }

  event.stopPropagation()
  event.dataTransfer?.setData('text/plain', String(person.id))
  event.dataTransfer!.effectAllowed = 'move'

  structure.startPersonDrag({
    id: person.id,
    name: person.name,
    role: person.role,
    fromDepartmentId: props.node.id,
  })
}
</script>

<template>
  <article
    class="card dept"
    :class="{
      'dept--own': isOwn,
      'dept--selected': isSelected,
      'dept--dragging': isDragging,
      'dept--target': isDropTarget,
      'dept--root': node.is_root,
    }"
    :draggable="structure.editable.value && !node.is_root"
    @dragstart="onDragStart"
    @dragend="structure.endDrag()"
    @dragover="onDragOver"
    @dragleave="onDragLeave"
    @drop="onDrop"
    @click="structure.select(node.id)"
  >
    <header class="dept__head">
      <span v-if="structure.editable.value && !node.is_root" class="dept__grip" aria-hidden="true">⠿</span>

      <h3 class="dept__name">
        {{ node.name }}
      </h3>

      <span v-if="isOwn" class="dept__own">ВАШ ОТДЕЛ</span>

      <div v-if="structure.editable.value" class="dept__menu">
        <button
          type="button"
          class="dept__menu-button"
          :aria-expanded="menuOpen"
          aria-label="Действия с отделом"
          @click.stop="menuOpen = !menuOpen"
        >
          …
        </button>

        <ul v-if="menuOpen" class="menu" @click.stop>
          <li>
            <button type="button" @click="withMenuClosed(() => structure.rename(node))">
              Переименовать
            </button>
          </li>
          <li>
            <button type="button" @click="withMenuClosed(() => structure.addChild(node))">
              Добавить отдел
            </button>
          </li>
          <li v-if="!node.is_root">
            <button type="button" class="menu__danger" @click="withMenuClosed(() => structure.remove(node))">
              Удалить
            </button>
          </li>
        </ul>
      </div>
    </header>

    <div v-if="shownHeads.length" class="dept__people">
      <div
        v-for="(head, index) in shownHeads"
        :key="head.id"
        class="person"
        :class="{ 'person--movable': structure.editable.value }"
        :draggable="structure.editable.value"
        @dragstart="onPersonDragStart($event, head)"
        @dragend.stop="structure.endPersonDrag()"
      >
        <UserAvatar :name="head.name" :src="head.avatar_url" :size="28" />

        <span class="person__body">
          <span class="person__line">
            <span class="person__name">{{ head.name }}</span>

            <!-- Плашка стоит вплотную к имени и говорит о кусте целиком, а не
                 об этом человеке: столько людей под отделом вместе с
                 вложенными. У второго имени на её месте — «сколько ещё». -->
            <span
              v-if="index === 0"
              class="chip"
              :title="`Всего людей в подчинении: ${node.people_total}`"
            >
              <svg viewBox="0 0 16 16" width="11" height="11" fill="currentColor" aria-hidden="true">
                <path d="M5.5 8a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Zm5.2.2a2 2 0 1 0 0-4 2 2 0 0 0 0 4ZM1 13c0-2 2-3.4 4.5-3.4S10 11 10 13v.5H1V13Zm10.1-2.6c2 .2 3.4 1.4 3.4 2.9v.2h-3.2V13c0-1-.4-1.9-1.1-2.5.3 0 .6-.1.9-.1Z" />
              </svg>
              {{ node.people_total }}
            </span>
            <span v-else-if="hiddenHeads" class="chip">+{{ hiddenHeads }}</span>
          </span>

          <span class="person__title">{{ head.job_title ?? 'Должность не указана' }}</span>
        </span>
      </div>
    </div>

    <p v-else class="dept__nobody">
      Руководитель не назначен
    </p>

    <div class="dept__facts">
      <div class="fact">
        <span class="fact__label">Подчинённые</span>

        <span class="fact__pill">{{ membersLabel }}</span>

        <!-- Лица, а не одно число: по ним отдел узнают быстрее, чем по счёту.
             Остальные сворачиваются в «+N», весь состав — в панели. -->
        <span v-if="node.members.length" class="faces">
          <span
            v-for="person in node.members"
            :key="person.id"
            class="faces__one"
            :class="{ 'person--movable': structure.editable.value }"
            :draggable="structure.editable.value"
            :title="person.name"
            @dragstart="onPersonDragStart($event, person)"
            @dragend.stop="structure.endPersonDrag()"
          >
            <UserAvatar :name="person.name" :src="person.avatar_url" :size="22" />
          </span>

          <span v-if="hiddenMembers" class="faces__more">+{{ hiddenMembers }}</span>
        </span>
      </div>

      <div v-if="node.deputies.length" class="fact">
        <span class="fact__label">Заместители</span>

        <span
          v-for="deputy in shownDeputies"
          :key="deputy.id"
          class="fact__people"
          :class="{ 'person--movable': structure.editable.value }"
          :draggable="structure.editable.value"
          @dragstart="onPersonDragStart($event, deputy)"
          @dragend.stop="structure.endPersonDrag()"
        >
          <UserAvatar :name="deputy.name" :src="deputy.avatar_url" :size="20" />
          <span class="fact__deputy">{{ deputy.short_name }}</span>
        </span>

        <span v-if="hiddenDeputies" class="fact__label">и ещё {{ hiddenDeputies }}</span>
      </div>
    </div>

    <footer
      class="dept__foot"
      :class="{ 'dept__foot--open': isExpanded && node.children_count > 0 }"
    >
      <button
        v-if="node.children_count"
        type="button"
        class="dept__toggle"
        :aria-expanded="isExpanded"
        @click.stop="structure.toggle(node.id)"
      >
        {{ childrenLabel }}
        <span class="dept__chevron" :class="{ 'dept__chevron--up': isExpanded }" aria-hidden="true">⌄</span>
      </button>

      <span v-else class="dept__none">нет отделов в подчинении</span>
    </footer>
  </article>
</template>

<style scoped>
.dept {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  width: 15.5rem;
  padding: 0.75rem 0.85rem 0;
  border: 1px solid transparent;
  cursor: pointer;
  transition: border-color 0.15s ease, box-shadow 0.15s ease, opacity 0.15s ease;
}

.dept:hover {
  border-color: var(--color-border-strong);
}

/* Выбранный отдел — тот, чья панель открыта справа. */
.dept--selected {
  border-color: var(--color-accent);
}

/* Куда бросят карточку, если отпустить сейчас. */
.dept--target {
  border-color: var(--color-highlight-strong);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-highlight) 35%, transparent);
}

.dept--dragging {
  opacity: 0.45;
}

.dept__head {
  display: flex;
  align-items: center;
  gap: 0.35rem;
}

.dept__grip {
  color: var(--color-text-muted);
  cursor: grab;
  font-size: 0.85rem;
  line-height: 1;
}

.dept__name {
  margin: 0;
  font-size: 0.95rem;
  font-weight: 600;
  line-height: 1.25;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Метка своего отдела: её ищут глазами первой, поэтому она цветная. */
.dept__own {
  padding: 0.1rem 0.4rem;
  border-radius: var(--radius-pill);
  background: var(--color-accent);
  color: var(--color-accent-text);
  font-size: 0.6rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  white-space: nowrap;
}

.dept__menu {
  position: relative;
  margin-left: auto;
}

.dept__menu-button {
  border: 0;
  background: transparent;
  color: var(--color-text-muted);
  font-size: 1rem;
  line-height: 1;
  padding: 0.1rem 0.3rem;
  border-radius: var(--radius);
  cursor: pointer;
}

.dept__menu-button:hover {
  background: var(--color-surface-sunken);
  color: var(--color-text);
}

.menu {
  position: absolute;
  top: 100%;
  right: 0;
  z-index: 5;
  min-width: 11rem;
  margin: 0.25rem 0 0;
  padding: 0.25rem;
  list-style: none;
  border-radius: var(--radius);
  background: var(--color-surface-raised);
  box-shadow: 0 10px 30px rgb(0 0 0 / 18%);
}

.menu button {
  display: block;
  width: 100%;
  padding: 0.45rem 0.6rem;
  border: 0;
  border-radius: calc(var(--radius) - 2px);
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.menu button:hover {
  background: var(--color-surface-sunken);
}

.menu__danger {
  color: var(--color-danger);
}

.dept__people {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
}

.person {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  min-width: 0;
}

/* Человека можно унести в другой отдел — курсор об этом и говорит. */
.person--movable {
  cursor: grab;
}

.person__body {
  display: flex;
  flex-direction: column;
  gap: 0.05rem;
  min-width: 0;
  line-height: 1.25;
}

/*
 * Имя и плашка — одной строкой, и ужимается имя, а не плашка: число людей в
 * кусте короткое и должно читаться целиком, а фамилию можно оборвать.
 */
.person__line {
  display: flex;
  align-items: center;
  gap: 0.3rem;
  min-width: 0;
}

.person__name,
.person__title {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.person__name {
  font-size: 0.86rem;
  font-weight: 550;
}

.person__title {
  color: var(--color-text-muted);
  font-size: 0.75rem;
}

/* Число людей в кусте: маленькая плашка со значком «люди», как в образце. */
.chip {
  display: inline-flex;
  flex-shrink: 0;
  align-items: center;
  gap: 0.2rem;
  padding: 0.08rem 0.4rem;
  border-radius: var(--radius-pill);
  background: var(--color-surface-sunken);
  color: var(--color-text-muted);
  font-size: 0.72rem;
  font-variant-numeric: tabular-nums;
  line-height: 1.35;
}

.dept__nobody {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.8rem;
}

/*
 * Две колонки поровну: «Подчинённые» слева, «Заместители» справа. Поровну, а
 * не по содержимому, — иначе длинная фамилия заместителя съезжала бы на
 * соседнюю колонку и подписи в ряду карточек переставали стоять в один столбец.
 */
.dept__facts {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.5rem 0.7rem;
  align-items: start;
}

.fact {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  min-width: 0;
}

.fact__label {
  color: var(--color-text-muted);
  font-size: 0.7rem;
}

/* Число подчинённых — плашкой: это величина, а не подпись, и читается она
   отдельно от «Подчинённых» над ней. */
.fact__pill {
  align-self: flex-start;
  max-width: 100%;
  padding: 0.15rem 0.55rem;
  border-radius: var(--radius-pill);
  border: 1px solid var(--color-border);
  font-size: 0.78rem;
  white-space: nowrap;
}

.fact__people {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  min-width: 0;
  font-size: 0.78rem;
}

/*
 * Аватарки подчинённых внахлёст, как в справочнике: так ряд лиц читается одной
 * группой и занимает вдвое меньше места, чем в строчку.
 */
.faces {
  display: flex;
  align-items: center;
  padding-left: 0.2rem;
}

.faces__one {
  margin-left: -0.35rem;
  border-radius: 50%;
  /* Обводка цветом карточки: без неё соседние лица сливаются в пятно. */
  box-shadow: 0 0 0 2px var(--color-surface);
}

.faces__more {
  margin-left: 0.3rem;
  color: var(--color-text-muted);
  font-size: 0.72rem;
  font-variant-numeric: tabular-nums;
}

.fact__deputy {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/*
 * Подвал прижат к краям карточки: он её ширины, а не ширины текста, — так же
 * читается полоска «N отделов» в структуре, из которой это срисовано.
 */
.dept__foot {
  margin: 0.15rem -0.85rem 0;
  padding: 0.4rem 0.85rem;
  border-top: 1px solid var(--color-border);
  text-align: center;
  font-size: 0.78rem;
  color: var(--color-text-muted);
}

.dept__foot--open {
  background: color-mix(in srgb, var(--color-highlight) 18%, transparent);
  border-radius: 0 0 var(--radius) var(--radius);
}

.dept__toggle {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  border: 0;
  background: transparent;
  color: inherit;
  font: inherit;
  cursor: pointer;
}

.dept__chevron {
  display: inline-block;
  transition: transform 0.15s ease;
}

.dept__chevron--up {
  transform: rotate(180deg);
}

.dept__none {
  font-size: 0.75rem;
}
</style>
