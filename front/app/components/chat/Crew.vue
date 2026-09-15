<script setup lang="ts">
import type { ChatPerson, Conversation } from '~/types/chat'

/**
 * Состав группы: кто в ней и кого зовут.
 *
 * Правит только заведший группу; остальные видят список и ничего больше. Выхода
 * здесь нет — он стоит в меню переписки, рядом с удалением: действия над самим
 * разговором собраны в одном месте, а эта панель про состав.
 */
const props = defineProps<{
  conversation: Conversation
  me: number | null
  onlineIds: number[]
}>()

const emit = defineEmits<{
  rename: [title: string]
  invite: [personId: number]
  expel: [personId: number]
}>()

const api = useChatApi()

const title = ref(props.conversation.title)
const query = ref('')
const found = ref<ChatPerson[]>([])

let timer: ReturnType<typeof setTimeout> | undefined

watch(query, (value) => {
  clearTimeout(timer)

  if (value.trim() === '') {
    found.value = []

    return
  }

  timer = setTimeout(async () => {
    const already = new Set((props.conversation.participants ?? []).map(one => one.id))

    found.value = (await api.searchContacts(value.trim())).data.filter(person => !already.has(person.id))
  }, 250)
})

onBeforeUnmount(() => clearTimeout(timer))

function invite(person: ChatPerson): void {
  emit('invite', person.id)
  query.value = ''
  found.value = []
}

function isOnline(personId: number): boolean {
  return props.onlineIds.includes(personId)
}
</script>

<template>
  <div class="crew">
    <div v-if="conversation.is_owner" class="crew__rename">
      <input v-model="title" class="input" maxlength="120" placeholder="Название группы">
      <button
        type="button"
        class="button-secondary button-sm"
        :disabled="!title.trim() || title.trim() === conversation.title"
        @click="emit('rename', title.trim())"
      >
        Переименовать
      </button>
    </div>

    <ul class="crew__list">
      <li v-for="person in conversation.participants" :key="person.id" class="crew__item">
        <span class="crew__face">
          <UserAvatar :name="person.name" :src="person.avatar_url" :size="28" />
          <span v-if="isOnline(person.id)" class="crew__online" title="В сети" />
        </span>

        <span class="crew__name">{{ person.name }}</span>

        <button
          v-if="conversation.is_owner && person.id !== me"
          type="button"
          class="crew__remove"
          @click="emit('expel', person.id)"
        >
          Убрать
        </button>
      </li>
    </ul>

    <div v-if="conversation.is_owner" class="crew__invite">
      <input v-model="query" type="search" class="input" placeholder="Добавить: фамилия или почта">

      <ul v-if="found.length" class="finder">
        <li v-for="person in found" :key="person.id">
          <button type="button" class="finder__option" @click="invite(person)">
            <UserAvatar :name="person.name" :src="person.avatar_url" :size="26" />
            <span>{{ person.name }}</span>
          </button>
        </li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.crew {
  display: flex;
  max-height: 16rem;
  flex-direction: column;
  gap: 0.5rem;
  overflow-y: auto;
  padding: 0.7rem var(--pane-pad);
  border-bottom: 1px solid var(--color-border);
  background: var(--color-surface);
  animation: drop 0.18s ease-out;
}

@keyframes drop {
  from { opacity: 0; transform: translateY(-0.5rem); }
  to { opacity: 1; transform: none; }
}

.crew__rename {
  display: flex;
  gap: 0.4rem;
}

.crew__list {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.crew__item {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.2rem 0;
}

.crew__face {
  position: relative;
  flex-shrink: 0;
}

.crew__online {
  position: absolute;
  right: -1px;
  bottom: -1px;
  width: 0.55rem;
  height: 0.55rem;
  border: 2px solid var(--color-surface);
  border-radius: 50%;
  background: var(--color-success);
}

.crew__name {
  overflow: hidden;
  flex: 1;
  font-size: 0.86rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.crew__remove {
  padding: 0;
  border: none;
  background: none;
  color: var(--color-danger);
  font: inherit;
  font-size: 0.78rem;
  cursor: pointer;
}

.crew__invite {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}

.finder {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.finder__option {
  display: flex;
  width: 100%;
  align-items: center;
  gap: 0.5rem;
  padding: 0.3rem 0.4rem;
  border: none;
  border-radius: var(--radius-sm);
  background: transparent;
  color: inherit;
  font: inherit;
  font-size: 0.85rem;
  text-align: left;
  cursor: pointer;
}

.finder__option:hover {
  background: var(--control-surface-hover);
}

@media (prefers-reduced-motion: reduce) {
  .crew {
    animation: none;
  }
}
</style>
