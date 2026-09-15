<script setup lang="ts">
import type { ChatPerson } from '~/types/chat'

/**
 * Новая переписка: один выбранный — личная, несколько — группа.
 *
 * Одно окно на два случая, потому что человек решает не «какой завести чат», а
 * «с кем поговорить». Название спрашивается только тогда, когда выбрали больше
 * одного: у переписки на двоих имя — это имя собеседника.
 */
const emit = defineEmits<{
  direct: [personId: number]
  group: [title: string, personIds: number[]]
  close: []
}>()

const api = useChatApi()

const query = ref('')
const contacts = ref<ChatPerson[]>([])
const chosen = ref<ChatPerson[]>([])
const title = ref('')

let timer: ReturnType<typeof setTimeout> | undefined

onMounted(async () => {
  contacts.value = (await api.searchContacts()).data
})

watch(query, (value) => {
  clearTimeout(timer)
  timer = setTimeout(async () => {
    contacts.value = (await api.searchContacts(value.trim())).data
  }, 250)
})

onBeforeUnmount(() => clearTimeout(timer))

function toggle(person: ChatPerson): void {
  chosen.value = chosen.value.some(one => one.id === person.id)
    ? chosen.value.filter(one => one.id !== person.id)
    : [...chosen.value, person]
}

function isChosen(person: ChatPerson): boolean {
  return chosen.value.some(one => one.id === person.id)
}

const canStart = computed(() => chosen.value.length === 1
  || (chosen.value.length > 1 && title.value.trim().length > 0))

function start(): void {
  if (!canStart.value) {
    return
  }

  if (chosen.value.length === 1) {
    emit('direct', chosen.value[0]!.id)

    return
  }

  emit('group', title.value.trim(), chosen.value.map(one => one.id))
}
</script>

<template>
  <div class="sheet" @click.self="emit('close')">
    <div class="sheet__panel card">
      <header class="sheet__head">
        <h2 class="sheet__title">
          Новая переписка
        </h2>
        <button type="button" class="button-ghost button-sm" @click="emit('close')">
          Закрыть
        </button>
      </header>

      <input v-model="query" type="search" class="input" placeholder="Кому: фамилия или почта">

      <!-- Выбранные стоят строкой над списком: в группе на десять человек
           иначе не видно, кого уже позвали. -->
      <ul v-if="chosen.length" class="picked">
        <li v-for="person in chosen" :key="person.id">
          <button type="button" @click="toggle(person)">
            {{ person.short_name }} ✕
          </button>
        </li>
      </ul>

      <p v-if="chosen.length > 1" class="muted sheet__hint">
        Выбрано больше одного — получится группа, ей нужно название.
      </p>

      <input
        v-if="chosen.length > 1"
        v-model="title"
        class="input"
        maxlength="120"
        placeholder="Название группы"
      >

      <ul class="sheet__list">
        <li v-for="person in contacts" :key="person.id">
          <button
            type="button"
            class="option"
            :class="{ 'option--chosen': isChosen(person) }"
            @click="toggle(person)"
          >
            <UserAvatar :name="person.name" :src="person.avatar_url" :size="32" />
            <span class="option__body">
              <span>{{ person.name }}</span>
              <span v-if="person.email" class="faint option__mail">{{ person.email }}</span>
            </span>
            <span v-if="isChosen(person)" class="option__tick">✓</span>
          </button>
        </li>
      </ul>

      <button type="button" class="button-primary" :disabled="!canStart" @click="start">
        {{ chosen.length > 1 ? 'Создать группу' : 'Написать' }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.sheet {
  position: fixed;
  inset: 0;
  z-index: 70;
  display: grid;
  place-items: center;
  padding: 1rem;
  background: rgb(0 0 0 / 40%);
  animation: veil 0.16s ease;
}

@keyframes veil {
  from { opacity: 0; }
  to { opacity: 1; }
}

.sheet__panel {
  display: flex;
  width: min(28rem, 100%);
  max-height: min(36rem, 90vh);
  flex-direction: column;
  gap: 0.6rem;
  animation: rise 0.2s cubic-bezier(0.22, 1, 0.36, 1);
}

@keyframes rise {
  from { opacity: 0; transform: translateY(1rem) scale(0.98); }
  to { opacity: 1; transform: none; }
}

.sheet__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.sheet__title {
  margin: 0;
  font-size: 1.05rem;
}

.sheet__hint {
  margin: 0;
  font-size: 0.82rem;
}

.picked {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.picked button {
  padding: 0.15rem 0.5rem;
  border: none;
  border-radius: var(--radius-pill);
  background: var(--color-accent-soft);
  color: inherit;
  font: inherit;
  font-size: 0.78rem;
  cursor: pointer;
}

.sheet__list {
  display: flex;
  min-height: 0;
  flex: 1;
  flex-direction: column;
  gap: 0.1rem;
  overflow-y: auto;
  margin: 0;
  padding: 0;
  list-style: none;
}

.option {
  display: flex;
  width: 100%;
  align-items: center;
  gap: 0.6rem;
  padding: 0.4rem 0.5rem;
  border: none;
  border-radius: var(--radius-sm);
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.option:hover {
  background: var(--control-surface-hover);
}

.option--chosen {
  background: var(--color-accent-soft);
}

.option__body {
  display: flex;
  min-width: 0;
  flex: 1;
  flex-direction: column;
  font-size: 0.88rem;
}

.option__mail {
  overflow: hidden;
  font-size: 0.75rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.option__tick {
  color: var(--color-accent);
  font-weight: 700;
}

@media (prefers-reduced-motion: reduce) {
  .sheet,
  .sheet__panel {
    animation: none;
  }
}
</style>
