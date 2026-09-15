<script setup lang="ts">
import type { TextNode } from '~/utils/messageText'
import { ownPath } from '~/utils/messageText'

/**
 * Один уровень дерева кусков — и рекурсивно все, что внутри.
 *
 * Отдельным компонентом, потому что разметка вкладывается: «**очень _важно_**»
 * — это жирное, внутри которого курсив, и показать такое можно только тем же
 * способом, каким оно разобрано.
 *
 * Путь нужен спойлерам: у куска текста нет своего номера, а закрытым должен
 * оставаться именно тот, по которому не нажимали.
 */
defineProps<{
  nodes: TextNode[]
  shown: Set<string>
  me: number | null
  path: string
}>()

const emit = defineEmits<{ reveal: [key: string], mention: [id: number] }>()

const MARK_TAG = {
  bold: 'strong',
  italic: 'em',
  strike: 's',
  code: 'code',
  spoiler: 'span',
} as const
</script>

<template>
  <template v-for="(node, at) in nodes" :key="`${path}.${at}`">
    <template v-if="node.kind === 'text'">{{ node.text }}</template>

    <pre v-else-if="node.kind === 'pre'" class="block"><code>{{ node.text }}</code></pre>

    <!-- Свой адрес ведёт по приложению, а не открывает новую вкладку: уйти из
         разговора и вернуться в него кнопкой браузера — не то же, что перейти. -->
    <NuxtLink
      v-else-if="node.kind === 'link' && ownPath(node.href)"
      :to="ownPath(node.href)!"
      class="link"
    >
      {{ node.text }}
    </NuxtLink>

    <a
      v-else-if="node.kind === 'link'"
      :href="node.href"
      target="_blank"
      rel="noopener noreferrer"
      class="link"
    >{{ node.text }}</a>

    <button
      v-else-if="node.kind === 'mention'"
      type="button"
      class="mention"
      :class="{ 'mention--me': node.id === me }"
      @click.stop="node.id && emit('mention', node.id)"
    >{{ node.text }}</button>

    <!-- Спойлер закрыт, пока по нему не нажали: текст под рябью не читается ни
         человеку за плечом, ни тому, кто листает ленту мимо. -->
    <button
      v-else-if="node.kind === 'mark' && node.mark === 'spoiler' && !shown.has(`${path}.${at}`)"
      type="button"
      class="spoiler"
      aria-label="Показать скрытое"
      @click.stop="emit('reveal', `${path}.${at}`)"
    >
      <span class="spoiler__text">
        <ChatTextNodes
          :nodes="node.children"
          :shown="shown"
          :me="me"
          :path="`${path}.${at}`"
          @reveal="emit('reveal', $event)"
          @mention="emit('mention', $event)"
        />
      </span>
    </button>

    <component
      :is="MARK_TAG[node.mark]"
      v-else-if="node.kind === 'mark'"
      :class="{ code: node.mark === 'code', revealed: node.mark === 'spoiler' }"
    >
      <ChatTextNodes
        :nodes="node.children"
        :shown="shown"
        :me="me"
        :path="`${path}.${at}`"
        @reveal="emit('reveal', $event)"
        @mention="emit('mention', $event)"
      />
    </component>
  </template>
</template>

<style scoped>
.link {
  color: inherit;
  text-decoration: underline;
  text-underline-offset: 2px;
}

.link:hover {
  text-decoration-thickness: 2px;
}

/* Моноширинный кусок внутри строки: подложка едва заметна, чтобы не спорить с
   пузырём, но достаточна, чтобы отделить код от слов вокруг. */
.code {
  padding: 0.05em 0.3em;
  border-radius: 0.35em;
  background: color-mix(in srgb, currentcolor 12%, transparent);
  font-family: ui-monospace, 'SF Mono', 'Cascadia Mono', 'Roboto Mono', monospace;
  font-size: 0.9em;
}

/* Блок кода прокручивается вбок сам: рвать строки в нём нельзя — это меняет то,
   что написано. */
.block {
  margin: 0.4rem 0;
  padding: 0.55rem 0.7rem;
  overflow-x: auto;
  border-radius: var(--radius-sm);
  background: color-mix(in srgb, currentcolor 10%, transparent);
  font-family: ui-monospace, 'SF Mono', 'Cascadia Mono', 'Roboto Mono', monospace;
  font-size: 0.85em;
  line-height: 1.5;
  white-space: pre;
}

.block code {
  font: inherit;
}

.mention {
  padding: 0;
  border: none;
  background: none;
  color: inherit;
  font: inherit;
  font-weight: 600;
  cursor: pointer;
  opacity: 0.85;
}

.mention:hover {
  text-decoration: underline;
}

/* Позвали тебя — видно издалека: это то самое, ради чего в группу и заходят. */
.mention--me {
  padding: 0.05em 0.3em;
  border-radius: 0.4em;
  background: color-mix(in srgb, var(--color-highlight) 45%, transparent);
  color: var(--color-highlight-text);
  opacity: 1;
}

/*
 * Скрытое засыпано рябью, а сам текст обесцвечен.
 *
 * Рябь рисует кнопка, а прозрачным становится вложенный кусок: краска берётся у
 * `currentcolor`, и попроси мы её на том же элементе, где цвет уже снят, брать
 * её было бы неоткуда.
 *
 * Именно рябь, а не размытие: размытое на крупном шрифте читается, а здесь
 * читать нечего — букв под ней просто нет.
 */
.spoiler {
  padding: 0 0.15em;
  border: none;
  border-radius: 0.35em;
  background:
    radial-gradient(circle at 20% 30%, currentcolor 0.5px, transparent 0.6px),
    radial-gradient(circle at 70% 60%, currentcolor 0.5px, transparent 0.6px);
  background-size: 4px 4px, 5px 5px;
  color: inherit;
  font: inherit;
  cursor: pointer;
}

.spoiler__text {
  color: transparent;
  user-select: none;
}

.revealed {
  font-style: normal;
}
</style>
