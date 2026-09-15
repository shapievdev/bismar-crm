<script setup lang="ts">
import type { MessageAttachment } from '~/types/chat'

/**
 * Снимок или запись во весь экран, не выходя из разговора.
 *
 * Раньше вложение было ссылкой и уводило в новую вкладку: человек терял место в
 * разговоре и возвращался кнопкой браузера. В мессенджере смотрят не выходя, и
 * из открытого снимка листают соседние — поэтому просматриваемое ищется не в
 * одном сообщении, а во всей загруженной ленте.
 */
const props = defineProps<{
  /** Всё, что можно посмотреть, — в том порядке, в каком оно в ленте. */
  files: MessageAttachment[]
  /** Какое вложение показываем сейчас. */
  fileId: number
}>()

const emit = defineEmits<{ close: [], step: [by: number] }>()

const at = computed(() => props.files.findIndex(one => one.id === props.fileId))
const current = computed(() => props.files[at.value] ?? null)

function onKey(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    emit('close')
  }
  else if (event.key === 'ArrowLeft') {
    emit('step', -1)
  }
  else if (event.key === 'ArrowRight') {
    emit('step', 1)
  }
}

onMounted(() => document.addEventListener('keydown', onKey))
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))

/** Пролистывание пальцем: то же, что стрелками, и то же, что во всякой галерее. */
let startX = 0

function onTouchStart(event: TouchEvent): void {
  startX = event.touches[0]?.clientX ?? 0
}

function onTouchEnd(event: TouchEvent): void {
  const dx = (event.changedTouches[0]?.clientX ?? startX) - startX

  if (Math.abs(dx) > 60) {
    emit('step', dx < 0 ? 1 : -1)
  }
}
</script>

<template>
  <div
    v-if="current"
    class="viewer"
    @click.self="emit('close')"
    @touchstart.passive="onTouchStart"
    @touchend="onTouchEnd"
  >
    <button type="button" class="viewer__close" aria-label="Закрыть" @click="emit('close')">
      ✕
    </button>

    <button
      v-if="at > 0"
      type="button"
      class="viewer__step viewer__step--back"
      aria-label="Предыдущее"
      @click.stop="emit('step', -1)"
    >
      ‹
    </button>

    <!-- Ключ по номеру вложения: без него при листании браузер оставляет
         прежний кадр, пока грузится следующий, и снимок «залипает». -->
    <img
      v-if="current.mime_type?.startsWith('image/')"
      :key="current.id"
      :src="current.url ?? ''"
      :alt="current.name"
      class="viewer__media"
    >
    <!-- `controls` и ничего сверх: свой проигрыватель здесь ничего не добавит, а
         системный умеет полный экран и картинку-в-картинке. -->
    <video
      v-else
      :key="current.id"
      :src="current.url ?? ''"
      class="viewer__media"
      controls
      autoplay
      playsinline
    />

    <button
      v-if="at < files.length - 1"
      type="button"
      class="viewer__step viewer__step--next"
      aria-label="Следующее"
      @click.stop="emit('step', 1)"
    >
      ›
    </button>

    <a :href="current.url ?? '#'" target="_blank" rel="noopener noreferrer" class="viewer__name">
      {{ current.name }}
      <span v-if="files.length > 1" class="viewer__of">{{ at + 1 }} из {{ files.length }}</span>
    </a>
  </div>
</template>

<style scoped>
/*
 * Гибкой раскладкой, а не сеткой, и это не вкусовщина.
 *
 * В сетке строка меряется по содержимому, а `max-height: 100%` у снимка меряется
 * по строке — получается круг, который браузер разрывает, попросту не ограничивая
 * высоту. Вертикальный снимок с телефона при этом уходил за нижний край экрана и
 * выглядел обрезанным сверху и снизу. У гибкой раскладки высота известна заранее
 * — от `inset: 0`, — и `100%` считается от неё.
 */
.viewer {
  position: fixed;
  inset: 0;
  z-index: 90;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  padding: 3.5rem 1rem;
  background: rgb(0 0 0 / 88%);
  animation: veil 0.16s ease;
}

@keyframes veil {
  from { opacity: 0; }
  to { opacity: 1; }
}

.viewer__media {
  max-width: 100%;
  /* И `max-height`, и `height: auto`: без второго снимок, у которого высота
     задана самим файлом, не ужимается, а обрезается. */
  max-height: 100%;
  width: auto;
  height: auto;
  border-radius: var(--radius-sm);
  object-fit: contain;
  animation: zoom 0.2s cubic-bezier(0.22, 1, 0.36, 1);
}

@keyframes zoom {
  from { opacity: 0; transform: scale(0.96); }
  to { opacity: 1; transform: none; }
}

.viewer__close,
.viewer__step {
  position: absolute;
  display: grid;
  place-items: center;
  width: 2.6rem;
  height: 2.6rem;
  border: none;
  border-radius: 50%;
  background: rgb(255 255 255 / 12%);
  color: #fff;
  font-size: 1.3rem;
  cursor: pointer;
  transition: background-color 0.15s ease;
}

.viewer__close:hover,
.viewer__step:hover {
  background: rgb(255 255 255 / 22%);
}

.viewer__close {
  top: 1rem;
  right: 1rem;
}

.viewer__step {
  top: 50%;
  font-size: 1.8rem;
  transform: translateY(-50%);
}

.viewer__step--back {
  left: 1rem;
}

.viewer__step--next {
  right: 1rem;
}

.viewer__name {
  position: absolute;
  bottom: 1rem;
  left: 50%;
  display: flex;
  max-width: 80%;
  align-items: baseline;
  gap: 0.6rem;
  overflow: hidden;
  padding: 0.25rem 0.8rem;
  border-radius: var(--radius-pill);
  background: rgb(255 255 255 / 12%);
  color: #fff;
  font-size: 0.82rem;
  text-decoration: none;
  text-overflow: ellipsis;
  white-space: nowrap;
  transform: translateX(-50%);
}

.viewer__of {
  flex-shrink: 0;
  font-variant-numeric: tabular-nums;
  opacity: 0.7;
}

@media (prefers-reduced-motion: reduce) {
  .viewer,
  .viewer__media {
    animation: none;
  }

  .viewer__close,
  .viewer__step {
    transition: none;
  }
}
</style>
