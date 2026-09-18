<script setup lang="ts">
import type { LinkCard } from '~/types/chat'

/**
 * Карточка ссылки под репликой.
 *
 * Отвечает на вопрос «что там», не заставляя открывать. В рабочей переписке
 * ссылками кидаются постоянно — на товар, на статью, на объявление, — и без
 * карточки каждая из них это переход туда и обратно.
 *
 * Появляется, когда приедет, и не занимает места до тех пор: карточка читается
 * с чужого сайта, и держать под каждой ссылкой серый прямоугольник в ожидании
 * значит рвать ленту на ровном месте.
 */
const props = defineProps<{ url: string }>()

const previews = useLinkPreviews()

const card = ref<LinkCard | null>(previews.known(props.url) ?? null)

/** Не загрузилась картинка — карточка остаётся, просто без неё. */
const imageFailed = ref(false)

watch(() => props.url, load, { immediate: true })

async function load(url: string): Promise<void> {
  imageFailed.value = false

  const already = previews.known(url)

  if (already !== undefined) {
    card.value = already

    return
  }

  const found = await previews.load(url)

  // Пока ходили, реплику могли перерисовать под другую ссылку.
  if (props.url === url) {
    card.value = found
  }
}
</script>

<template>
  <Transition name="unfold">
    <a
      v-if="card"
      :href="card.url"
      target="_blank"
      rel="noopener noreferrer"
      class="card"
      @click.stop
    >
      <img
        v-if="card.image && !imageFailed"
        :src="card.image"
        :alt="card.title"
        class="card__image"
        loading="lazy"
        referrerpolicy="no-referrer"
        @error="imageFailed = true"
      >

      <span class="card__body">
        <span class="card__host">{{ card.site_name ?? card.host }}</span>
        <span class="card__title">{{ card.title }}</span>
        <span v-if="card.description" class="card__text">{{ card.description }}</span>
      </span>
    </a>
  </Transition>
</template>

<style scoped>
/*
 * Полоса слева, как у цитаты, и по той же причине: и то и другое — чужая речь,
 * приведённая внутри своей. Цвет берётся у текста пузыря, поэтому карточка
 * одинаково читается и на своём пузыре, и на чужом.
 */
.card {
  display: flex;
  overflow: hidden;
  margin-top: 0.35rem;
  flex-direction: column;
  border-left: 2px solid currentcolor;
  border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
  background: rgba(var(--tint-rgb), 0.1);
  color: inherit;
  text-decoration: none;
}

.card:hover {
  background: rgba(var(--tint-rgb), 0.16);
}

/*
 * Картинка во всю ширину и приплюснутая.
 *
 * Обложки у сайтов бывают какие угодно, вплоть до вертикальных, и показывать их
 * как есть значит отдавать карточке полпузыря. Полоса в две с половиной ширины
 * говорит ровно столько, сколько нужно, чтобы узнать страницу.
 */
.card__image {
  display: block;
  width: 100%;
  max-height: 9rem;
  aspect-ratio: 5 / 2;
  object-fit: cover;
}

.card__body {
  display: flex;
  min-width: 0;
  flex-direction: column;
  gap: 0.05rem;
  padding: 0.35rem 0.55rem;
}

.card__host {
  font-size: 0.7rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  opacity: 0.7;
}

.card__title {
  display: -webkit-box;
  overflow: hidden;
  font-size: 0.85rem;
  font-weight: 600;
  line-height: 1.3;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
}

.card__text {
  display: -webkit-box;
  overflow: hidden;
  font-size: 0.78rem;
  line-height: 1.35;
  opacity: 0.8;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 3;
}

/* Карточка приезжает позже самой реплики — и разворачивается, а не возникает:
   так лента не дёргается под читающим. */
.unfold-enter-active {
  transition: opacity 0.2s ease, transform 0.24s cubic-bezier(0.22, 1, 0.36, 1);
}

.unfold-enter-from {
  opacity: 0;
  transform: translateY(-0.3rem) scale(0.98);
}

@media (prefers-reduced-motion: reduce) {
  .unfold-enter-active {
    transition: none;
  }
}
</style>
