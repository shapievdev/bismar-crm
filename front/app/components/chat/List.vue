<script setup lang="ts">
import type { ChatPerson, Conversation, MessageHit } from '~/types/chat'
import { chatStamp } from '~/utils/chatTime'
import { plainMessageText } from '~/utils/messageText'

/**
 * Левая панель: поиск и список переписок.
 *
 * Поиск здесь отвечает сразу на три разных вопроса — «где тот разговор», «где
 * тот человек» и «где это обсуждали», — и потому ищет в трёх местах. Разговоры
 * отбираются на месте, из того, что уже загружено: список переписок у вкладки на
 * руках, и ходить за ним на сервер ради подстроки в названии незачем. Люди и
 * сообщения приходят запросами, с задержкой, чтобы не спрашивать сервер на
 * каждую букву.
 */
const props = defineProps<{
  conversations: Conversation[]
  activeId: number | null
  me: number | null
  onlineIds: number[]
}>()

const emit = defineEmits<{
  open: [id: number]
  menu: [conversation: Conversation, at: { x: number, y: number }]
  compose: []
  writeTo: [personId: number]
  jump: [conversationId: number, messageId: number]
}>()

const api = useChatApi()

const query = ref('')
const people = ref<ChatPerson[]>([])
const hits = ref<MessageHit[]>([])
const searching = ref(false)

const isSearching = computed(() => query.value.trim().length > 0)

/** Разговоры отбираются на месте — мгновенно, без обращения к серверу. */
const matchedChats = computed(() => {
  const needle = query.value.trim().toLowerCase()

  return needle === ''
    ? props.conversations
    : props.conversations.filter(one => one.title.toLowerCase().includes(needle))
})

const pinnedChats = computed(() => matchedChats.value.filter(one => one.is_pinned))
const restChats = computed(() => matchedChats.value.filter(one => !one.is_pinned))

let timer: ReturnType<typeof setTimeout> | undefined

watch(query, (value) => {
  clearTimeout(timer)

  const needle = value.trim()

  if (needle.length < 2) {
    people.value = []
    hits.value = []
    searching.value = false

    return
  }

  searching.value = true

  timer = setTimeout(async () => {
    try {
      const [contacts, found] = await Promise.all([
        api.searchContacts(needle),
        api.searchEverywhere(needle),
      ])

      people.value = contacts.data.filter(person => person.id !== props.me).slice(0, 5)
      hits.value = found.data
    }
    finally {
      searching.value = false
    }
  }, 250)
})

onBeforeUnmount(() => clearTimeout(timer))

function isOnline(personId: number | null | undefined): boolean {
  return personId !== null && personId !== undefined && props.onlineIds.includes(personId)
}

/** Где это сказано — название разговора берётся из своего же списка. */
function whereOf(hit: MessageHit): string {
  return props.conversations.find(one => one.id === hit.conversation_id)?.title ?? 'Переписка'
}

function clear(): void {
  query.value = ''
  people.value = []
  hits.value = []
}
</script>

<template>
  <aside class="list">
    <header class="list__head">
      <h1 class="page-title list__title">
        Сообщения
      </h1>
      <button type="button" class="button-primary button-sm" @click="emit('compose')">
        Написать
      </button>
    </header>

    <div class="find">
      <svg class="find__glass" viewBox="0 0 24 24" aria-hidden="true">
        <circle cx="11" cy="11" r="6.5" stroke="currentColor" stroke-width="2" fill="none" />
        <path d="M16 16l4.5 4.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
      </svg>

      <input
        v-model="query"
        type="search"
        class="find__field"
        placeholder="Поиск по переписке"
        aria-label="Поиск"
      >

      <button v-if="isSearching" type="button" class="find__clear" aria-label="Очистить" @click="clear">
        ✕
      </button>
    </div>

    <div class="list__body">
      <p v-if="!conversations.length && !isSearching" class="muted list__empty">
        Переписок пока нет. Напишите коллеге — например тому, кто отвечает за курс.
      </p>

      <!-- Закреплённые отбиты от остальных: они стоят наверху не потому, что в
           них только что написали, и смешивать одно с другим нельзя. -->
      <template v-if="pinnedChats.length && !isSearching">
        <p class="list__label faint">
          Закреплённые
        </p>
        <ChatRow
          v-for="conversation in pinnedChats"
          :key="conversation.id"
          :conversation="conversation"
          :active="conversation.id === activeId"
          :online="isOnline(conversation.companion?.id)"
          :me="me"
          @open="emit('open', conversation.id)"
          @menu="emit('menu', conversation, $event)"
        />
        <p class="list__label faint">
          Остальные
        </p>
      </template>

      <ChatRow
        v-for="conversation in (isSearching ? matchedChats : restChats)"
        :key="conversation.id"
        :conversation="conversation"
        :active="conversation.id === activeId"
        :online="isOnline(conversation.companion?.id)"
        :me="me"
        @open="emit('open', conversation.id)"
        @menu="emit('menu', conversation, $event)"
      />

      <template v-if="isSearching">
        <template v-if="people.length">
          <p class="list__label faint">
            Сотрудники
          </p>
          <button
            v-for="person in people"
            :key="`person-${person.id}`"
            type="button"
            class="found"
            @click="emit('writeTo', person.id)"
          >
            <UserAvatar :name="person.name" :src="person.avatar_url" :size="34" />
            <span class="found__body">
              <span class="found__title">{{ person.name }}</span>
              <span class="faint found__text">Написать</span>
            </span>
          </button>
        </template>

        <template v-if="hits.length">
          <p class="list__label faint">
            Сообщения
          </p>
          <button
            v-for="hit in hits"
            :key="`hit-${hit.id}`"
            type="button"
            class="found"
            @click="emit('jump', hit.conversation_id, hit.id)"
          >
            <UserAvatar :name="hit.author?.name ?? ''" :src="hit.author?.avatar_url ?? null" :size="34" />
            <span class="found__body">
              <span class="found__title">
                {{ whereOf(hit) }}
                <span class="faint found__when">{{ chatStamp(hit.created_at) }}</span>
              </span>
              <span class="faint found__text">{{ plainMessageText(hit.body ?? '') }}</span>
            </span>
          </button>
        </template>

        <p v-if="!searching && !matchedChats.length && !people.length && !hits.length" class="muted list__empty">
          Ничего не нашлось.
        </p>
      </template>
    </div>
  </aside>
</template>

<style scoped>
/* `min-width: 0` — чтобы колонка списка меряла себя по отведённому месту, а не
   по самой длинной строчке: присланная ссылка иначе растягивает её за край. */
.list {
  display: flex;
  min-width: 0;
  min-height: 0;
  flex-direction: column;
  gap: 0.5rem;
}

/* Заголовок и поиск стоят на месте, а прокручивается только сам список: искать
   в длинной переписке, каждый раз возвращаясь к полю наверх, невозможно. */
.list__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0 0.6rem;
}

.list__title {
  margin: 0;
  font-size: 1.35rem;
}

.list__body {
  display: flex;
  min-width: 0;
  min-height: 0;
  flex: 1;
  flex-direction: column;
  gap: 0.15rem;
  overflow-y: auto;
  padding-right: 0.25rem;
}

.list__label {
  margin: 0.5rem 0 0.1rem;
  padding: 0 0.6rem;
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.list__empty {
  padding: 0 0.6rem;
  font-size: 0.88rem;
  line-height: 1.5;
}

.find {
  position: relative;
  display: flex;
  align-items: center;
  margin: 0 0.6rem;
}

.find__glass {
  position: absolute;
  left: 0.6rem;
  width: 1rem;
  height: 1rem;
  color: var(--color-text-faint);
  pointer-events: none;
}

.find__field {
  width: 100%;
  padding: 0.45rem 2rem 0.45rem 2.1rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-pill);
  background: var(--color-surface);
  color: inherit;
  font: inherit;
  font-size: 0.88rem;
}

.find__field:focus {
  border-color: var(--color-border-strong);
  outline: none;
}

/* Системный крестик поиска в разных браузерах свой и в кружок не помещается. */
.find__field::-webkit-search-cancel-button {
  display: none;
}

.find__clear {
  position: absolute;
  right: 0.5rem;
  padding: 0.1rem 0.3rem;
  border: none;
  background: none;
  color: var(--color-text-faint);
  font: inherit;
  cursor: pointer;
}

.found {
  display: flex;
  width: 100%;
  align-items: center;
  gap: 0.7rem;
  padding: 0.45rem 0.6rem;
  border: none;
  border-radius: var(--radius);
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.found:hover {
  background: var(--control-surface-hover);
}

.found__body {
  display: flex;
  min-width: 0;
  flex: 1;
  flex-direction: column;
}

.found__title {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.5rem;
  overflow: hidden;
  font-size: 0.88rem;
  font-weight: 550;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.found__when {
  flex-shrink: 0;
  font-size: 0.72rem;
}

.found__text {
  overflow: hidden;
  font-size: 0.8rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
