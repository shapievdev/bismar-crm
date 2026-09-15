<script setup lang="ts">
import type { ChatPerson } from '~/types/chat'
import { parseMessageText } from '~/utils/messageText'

/**
 * Сказанное — с разметкой, ссылками и упоминаниями.
 *
 * Показывает дерево кусков, а не строку HTML, и потому подставлять сюда чужой
 * текст безопасно по устройству, а не по внимательности: всякий кусок текста
 * остаётся для Vue текстом, чем бы он ни выглядел.
 *
 * Спойлер закрыт, пока по нему не нажали. В рабочей переписке им прячут не
 * сюрпризы, а то, что не должно бросаться в глаза через плечо, — суммы,
 * фамилии, итоги проверки.
 */
const props = defineProps<{
  body: string
  /** Участники разговора: по ним узнаются упоминания. */
  people?: ChatPerson[]
  /** Кого позвали: своё упоминание выделяется ярче остальных. */
  me?: number | null
}>()

const emit = defineEmits<{ mention: [id: number] }>()

const nodes = computed(() => parseMessageText(props.body, props.people ?? []))

/** Раскрытые спойлеры — по месту в дереве: своего ключа у куска текста нет. */
const shown = ref<Set<string>>(new Set())

function reveal(key: string): void {
  shown.value = new Set([...shown.value, key])
}

// Сменилось сообщение — спойлеры снова закрыты: это уже другой текст.
watch(() => props.body, () => {
  shown.value = new Set()
})
</script>

<template>
  <span class="text">
    <ChatTextNodes
      :nodes="nodes"
      :shown="shown"
      :me="me ?? null"
      path=""
      @reveal="reveal"
      @mention="emit('mention', $event)"
    />
  </span>
</template>

<style scoped>
.text {
  /* Перенос по словам, но длинное слово рвём: ссылка или артикул в одну строку
     иначе растягивают пузырь шире экрана. */
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}
</style>
