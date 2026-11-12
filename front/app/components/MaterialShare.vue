<script setup lang="ts">
import type { ChatPerson, Conversation, MaterialRef } from '~/types/chat'

const props = defineProps<{ material: MaterialRef, title: string, path: string }>()
const api = useChatApi()
const dialog = useTemplateRef<HTMLDialogElement>('dialog')
const opened = ref(false)
const internal = ref(false)
const origin = ref('')
const nativeShareAvailable = ref(false)
const url = computed(() => `${origin.value}${props.path}`)
const text = computed(() => `${props.title}\n${url.value}`)
const telegram = computed(() => `https://t.me/share/url?${new URLSearchParams({ url: url.value, text: props.title })}`)
const whatsapp = computed(() => `https://wa.me/?${new URLSearchParams({ text: text.value })}`)
const notice = ref('')
const error = ref('')
const query = ref('')
const conversations = ref<Conversation[]>([])
const contacts = ref<ChatPerson[]>([])
const loading = ref(false)
const sending = ref(false)
const destination = ref<{ kind: 'chat' | 'person', id: number, name: string } | null>(null)
const comment = ref('')
const sentTo = ref<number | null>(null)
let searchId = 0
let timer: ReturnType<typeof setTimeout> | undefined

onMounted(() => {
  origin.value = window.location.origin
  nativeShareAvailable.value = typeof navigator.share === 'function'
})
onBeforeUnmount(() => { clearTimeout(timer); searchId++ })

function open() {
  notice.value = ''
  error.value = ''
  internal.value = false
  destination.value = null
  comment.value = ''
  sentTo.value = null
  opened.value = true
  dialog.value?.showModal()
}

function close() {
  if (sending.value) return
  dialog.value?.close()
  opened.value = false
  searchId++
  clearTimeout(timer)
}

async function copy(bitrix = false) {
  notice.value = ''
  error.value = ''
  try {
    await navigator.clipboard.writeText(bitrix ? text.value : url.value)
    notice.value = bitrix ? 'Название и ссылка скопированы. Вставьте их в нужный чат Битрикс24.' : 'Ссылка скопирована.'
  }
  catch {
    error.value = 'Не удалось скопировать автоматически. Выделите ссылку в поле ниже и скопируйте вручную.'
  }
}

async function nativeShare() {
  error.value = ''
  notice.value = ''
  try {
    await navigator.share({ title: props.title, text: props.title, url: url.value })
  }
  catch (caught) {
    if ((caught as Error).name !== 'AbortError') error.value = 'Не удалось открыть меню приложений. Можно скопировать ссылку.'
  }
}

const chats = computed(() => conversations.value.filter(chat => !chat.is_system)
  .filter(chat => !query.value.trim() || matchesTyped(chat.title, query.value.trim())))

async function loadContacts() {
  const current = ++searchId
  loading.value = true
  error.value = ''
  try {
    const result = await api.searchContacts(query.value.trim())
    if (current === searchId && opened.value) contacts.value = result.data
  }
  catch {
    if (current === searchId) error.value = 'Не удалось загрузить сотрудников. Повторите поиск.'
  }
  finally {
    if (current === searchId) loading.value = false
  }
}

async function chooseInternal() {
  sentTo.value = null
  notice.value = ''
  destination.value = null
  internal.value = true
  query.value = ''
  conversations.value = []
  contacts.value = []
  try {
    conversations.value = (await api.fetchConversations()).data
  }
  catch {
    error.value = 'Не удалось загрузить чаты. Можно выбрать сотрудника.'
  }
  await loadContacts()
}

watch(query, () => {
  clearTimeout(timer)
  searchId++
  contacts.value = []
  timer = setTimeout(() => { if (internal.value && opened.value) void loadContacts() }, 250)
})

async function send() {
  if (!destination.value || sending.value) return
  const to = destination.value
  sending.value = true
  error.value = ''
  notice.value = ''
  try {
    await api.fetchAbout(props.material)
    const chatId = to.kind === 'chat' ? to.id : (await api.startDirect(to.id)).data.id
    await api.sendMessage(chatId, comment.value.trim() || 'Делюсь учебным материалом', [], {}, { about: props.material })
    sentTo.value = chatId
    notice.value = `Материал отправлен: ${to.name}.`
    destination.value = null
  }
  catch (caught) {
    error.value = messageFromError(caught, 'Не удалось отправить материал. Попробуйте ещё раз.')
  }
  finally {
    sending.value = false
  }
}
</script>

<template>
  <button type="button" class="button-secondary button-sm" @click="open">Поделиться</button>
  <Teleport to="body">
    <dialog ref="dialog" class="material-share" aria-label="Поделиться учебным материалом" @cancel.prevent="close" @click.self="close">
      <div v-if="opened" class="share-panel">
        <header>
          <h2>Поделиться материалом</h2>
          <button type="button" class="button-ghost button-sm" :disabled="sending" @click="close">Закрыть</button>
        </header>
        <p class="share-title">{{ title }}</p>
        <p class="faint">Получателю нужен вход в платформу и доступ к материалу.</p>
        <div class="share-actions">
          <button type="button" class="button-primary button-sm" :disabled="sending" @click="chooseInternal">В сообщениях платформы</button>
          <button type="button" class="button-secondary button-sm" @click="copy()">Копировать ссылку</button>
          <a :href="telegram" target="_blank" rel="noopener noreferrer" class="button-secondary button-sm">Telegram</a>
          <a :href="whatsapp" target="_blank" rel="noopener noreferrer" class="button-secondary button-sm">WhatsApp</a>
          <button type="button" class="button-secondary button-sm" @click="copy(true)">Битрикс24 — скопировать</button>
          <button v-if="nativeShareAvailable" type="button" class="button-secondary button-sm" @click="nativeShare">Другие приложения…</button>
        </div>
        <label class="share-field">
          <span>Ссылка на материал</span>
          <input :value="url" class="input" readonly @focus="($event.target as HTMLInputElement).select()">
        </label>
        <p v-if="notice" role="status">{{ notice }}</p>
        <NuxtLink v-if="sentTo" :to="`/messenger?id=${sentTo}`" @click="close">Открыть переписку</NuxtLink>
        <p v-if="error" class="field-error" role="alert">{{ error }}</p>
        <section v-if="internal && !sentTo" class="share-internal" aria-label="Выбор получателя">
          <label class="share-field">
            <span>Найти сотрудника или чат</span>
            <input v-model="query" class="input" type="search" placeholder="Фамилия или название чата" :disabled="sending">
          </label>
          <div class="share-recipients">
            <h3 v-if="chats.length">Чаты</h3>
            <button v-for="chat in chats" :key="`chat-${chat.id}`" type="button" class="recipient" :aria-pressed="destination?.kind === 'chat' && destination.id === chat.id" :disabled="sending" @click="destination = {kind:'chat', id:chat.id, name:chat.title}">
              {{ chat.title }} <span class="faint">{{ chat.kind === 'direct' ? 'Личный чат' : 'Группа' }}</span>
            </button>
            <h3 v-if="contacts.length">Сотрудники</h3>
            <p v-if="loading" role="status">Ищем сотрудников…</p>
            <button v-for="person in contacts" :key="`person-${person.id}`" type="button" class="recipient" :aria-pressed="destination?.kind === 'person' && destination.id === person.id" :disabled="sending" @click="destination = {kind:'person', id:person.id, name:person.name}">
              {{ person.name }}
            </button>
            <p v-if="!loading && !contacts.length && !chats.length" class="faint">Получателей не найдено.</p>
          </div>
          <p v-if="destination">Получатель: <b>{{ destination.name }}</b></p>
          <label class="share-field">
            <span>Комментарий (необязательно)</span>
            <textarea v-model="comment" class="input" rows="2" maxlength="4000" :disabled="sending" />
          </label>
          <button type="button" class="button-primary" :disabled="!destination || sending" @click="send">{{ sending ? 'Отправляем…' : 'Отправить материал' }}</button>
        </section>
      </div>
    </dialog>
  </Teleport>
</template>

<style scoped>
.material-share { width: min(36rem, calc(100vw - 2rem)); max-height: calc(100dvh - 2rem); padding: 0; border: 1px solid var(--color-border); border-radius: var(--radius); background: var(--color-surface); color: var(--color-text); }
.material-share::backdrop { background: rgb(0 0 0 / 45%); }
.share-panel { padding: 1.25rem; display: grid; gap: 1rem; }
header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
h2, h3, p { margin: 0; }
h2 { font-size: 1.15rem; }
h3 { font-size: .95rem; margin-top: .5rem; }
.share-title { font-weight: 600; overflow-wrap: anywhere; }
.share-actions { display: flex; flex-wrap: wrap; gap: .5rem; }
.share-field, .share-internal { display: grid; gap: .5rem; }
.share-field span { font-size: .875rem; }
.share-recipients { display: grid; gap: .35rem; max-height: 13rem; overflow-y: auto; }
.recipient { border: 1px solid var(--color-border); border-radius: var(--radius-sm); background: transparent; color: inherit; padding: .6rem; text-align: left; cursor: pointer; font: inherit; }
.recipient span { display: block; font-size: .8rem; }
.recipient[aria-pressed="true"] { border-color: var(--color-accent); background: var(--control-surface); }
.recipient:disabled { opacity: .6; cursor: default; }
</style>
