<script setup lang="ts">
import type { ChatMessage } from '~/types/chat'

/**
 * Уведомление в ленте — системная отметка, у которой есть что показать.
 *
 * Обычная отметка («Иванов добавил Петрова») рисуется узкой серой пилюлей
 * посреди ленты: она про разговор, и читают её мельком. Уведомление —
 * наоборот, оно и есть содержимое: за ним стоит заработанный значок, и
 * пилюлей в два слова его не покажешь.
 *
 * Карточка берётся из `about` готовой — той же, что у материала, с которого
 * пишут. Разница одна: у достижения есть картинка.
 */
const props = defineProps<{ message: ChatMessage }>()

const about = computed(() => props.message.about)
</script>

<template>
  <component
    :is="about?.url ? resolveComponent('NuxtLink') : 'div'"
    class="notice"
    :to="about?.url ?? undefined"
  >
    <!-- Значок как есть: без рамки и обрезки — у достижений своя форма. -->
    <img
      v-if="about?.image_url"
      class="notice__picture"
      :src="about.image_url"
      :alt="about.title"
      loading="lazy"
    >

    <span class="notice__body">
      <span class="notice__text">{{ message.body }}</span>

      <span v-if="about?.context" class="notice__context">{{ about.context }}</span>

      <span v-else-if="about" class="notice__context">{{ about.kind_label }}</span>
    </span>
  </component>
</template>

<style scoped>
.notice {
  display: flex;
  align-items: center;
  gap: 0.9rem;
  /* По центру ленты и не во всю ширину: уведомление не чья-то реплика, и
     прижимать его к краю, как пузырь, было бы неправдой. */
  width: min(26rem, 100%);
  margin: 0.6rem auto;
  padding: 0.85rem 1rem;
  border-radius: var(--radius);
  background: var(--color-surface-sunken);
  color: inherit;
  text-decoration: none;
}

.notice:hover {
  background: var(--control-surface-hover);
}

.notice__picture {
  flex: none;
  width: 3rem;
  height: 3rem;
  object-fit: contain;
}

.notice__body {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  min-width: 0;
}

.notice__text {
  font-weight: 600;
  font-size: 0.92rem;
  line-height: 1.3;
}

.notice__context {
  color: var(--color-text-muted);
  font-size: 0.8rem;
  line-height: 1.3;
}
</style>
