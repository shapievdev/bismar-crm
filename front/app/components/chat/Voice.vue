<script setup lang="ts">
import type { MessageAttachment } from '~/types/chat'
import { clockOf } from '~/utils/chatTime'
import { paddedWaveform } from '~/utils/voice'

/**
 * Голосовое сообщение: волна, кнопка и время.
 *
 * Волна здесь не украшение. По ней видно, где в записи речь, а где пауза, — и
 * потому по ней перематывают: нажатие по столбику переносит туда же, куда
 * нажали. В записи на две минуты это единственный способ найти нужное, не
 * прослушав всё.
 *
 * Проигрывается своим тегом `<audio>` без `controls`: системный проигрыватель
 * занимает полпузыря и выглядит в каждом браузере по-своему, а нужны от него
 * три вещи — играть, знать, где мы, и уметь переносить.
 */
const props = defineProps<{
  file: MessageAttachment
  /** Играет ли сейчас именно это: в ленте звучит не больше одной записи. */
  playing: boolean
}>()

const emit = defineEmits<{ play: [], pause: [] }>()

const audio = ref<HTMLAudioElement | null>(null)
const at = ref(0)
const speed = ref(1)

const bars = computed(() => paddedWaveform(props.file.waveform))

/** Длительность берём присланную: ждать заголовка файла ради подписи незачем. */
const total = computed(() => (props.file.duration_ms ?? 0) / 1000)

/** Сколько проиграно, 0–1: по нему закрашивается волна. */
const done = computed(() => (total.value > 0 ? Math.min(1, at.value / total.value) : 0))

/** Пока не играли — показываем всю длину, в ходе — сколько осталось позади. */
const label = computed(() => clockOf(at.value > 0 ? at.value : total.value))

watch(() => props.playing, (isPlaying) => {
  if (isPlaying) {
    void audio.value?.play()
  }
  else {
    audio.value?.pause()
  }
})

function toggle(): void {
  props.playing ? emit('pause') : emit('play')
}

/**
 * Перемотка нажатием по волне.
 *
 * Считается от самой полосы, а не от столбика: между столбиками есть просветы,
 * и попавший в просвет палец не должен ничего не делать.
 */
function seek(event: MouseEvent): void {
  const strip = event.currentTarget as HTMLElement
  const box = strip.getBoundingClientRect()
  const share = Math.max(0, Math.min(1, (event.clientX - box.left) / box.width))

  at.value = share * total.value

  if (audio.value) {
    audio.value.currentTime = at.value
  }
}

/** Скорость переключается по кругу: полтора и два — для длинных надиктовок. */
function cycleSpeed(): void {
  speed.value = speed.value === 1 ? 1.5 : (speed.value === 1.5 ? 2 : 1)

  if (audio.value) {
    audio.value.playbackRate = speed.value
  }
}

function onTime(): void {
  at.value = audio.value?.currentTime ?? 0
}

/** Доиграла — возвращаемся в начало: второй раз слушают с начала, а не с конца. */
function onEnded(): void {
  at.value = 0
  emit('pause')
}
</script>

<template>
  <div class="voice" :class="{ 'voice--playing': playing }">
    <audio
      ref="audio"
      :src="file.url ?? ''"
      preload="none"
      @timeupdate="onTime"
      @ended="onEnded"
    />

    <button
      type="button"
      class="voice__play"
      :aria-label="playing ? 'Остановить' : 'Прослушать'"
      @click.stop="toggle"
    >
      <svg v-if="playing" viewBox="0 0 24 24" aria-hidden="true">
        <rect x="7" y="5" width="3.5" height="14" rx="1.2" fill="currentColor" />
        <rect x="13.5" y="5" width="3.5" height="14" rx="1.2" fill="currentColor" />
      </svg>
      <svg v-else viewBox="0 0 24 24" aria-hidden="true">
        <path d="M8 5.5v13l11-6.5z" fill="currentColor" />
      </svg>
    </button>

    <div class="voice__body">
      <!-- Волна — кнопка: по ней перематывают, и потому она должна быть
           доступна не только пальцу, но и клавиатуре. -->
      <button
        type="button"
        class="wave"
        aria-label="Перемотать"
        @click.stop="seek"
      >
        <span
          v-for="(height, index) in bars"
          :key="index"
          class="wave__bar"
          :class="{ 'wave__bar--done': index / bars.length < done }"
          :style="{ height: `${Math.max(8, height)}%` }"
        />
      </button>

      <div class="voice__line">
        <span class="voice__clock">{{ label }}</span>

        <button
          v-if="total > 20"
          type="button"
          class="voice__speed"
          :aria-label="`Скорость ${speed}×`"
          @click.stop="cycleSpeed"
        >
          {{ speed }}×
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.voice {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  min-width: 13rem;
  max-width: 17rem;
}

.voice__play {
  display: grid;
  flex-shrink: 0;
  place-items: center;
  width: 2.35rem;
  height: 2.35rem;
  padding: 0;
  border: none;
  border-radius: 50%;
  background: color-mix(in srgb, currentcolor 15%, transparent);
  color: inherit;
  cursor: pointer;
  transition: transform 0.12s ease, background-color 0.15s ease;
}

.voice__play:hover {
  background: color-mix(in srgb, currentcolor 24%, transparent);
}

.voice__play:active {
  transform: scale(0.93);
}

.voice__play svg {
  width: 1.1rem;
  height: 1.1rem;
}

.voice__body {
  display: flex;
  min-width: 0;
  flex: 1;
  flex-direction: column;
  gap: 0.2rem;
}

.wave {
  display: flex;
  align-items: center;
  gap: 2px;
  width: 100%;
  height: 1.85rem;
  padding: 0;
  border: none;
  background: none;
  cursor: pointer;
}

.wave__bar {
  flex: 1;
  min-width: 2px;
  border-radius: var(--radius-pill);
  background: color-mix(in srgb, currentcolor 28%, transparent);
  transition: background-color 0.1s linear;
}

/* Пройденное закрашено плотнее: это и есть указатель места — отдельной черты,
   которая на волне только мешает, здесь нет. */
.wave__bar--done {
  background: currentcolor;
}

.voice__line {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.voice__clock {
  font-size: 0.75rem;
  font-variant-numeric: tabular-nums;
  opacity: 0.75;
}

.voice__speed {
  padding: 0.02rem 0.35rem;
  border: none;
  border-radius: var(--radius-pill);
  background: color-mix(in srgb, currentcolor 14%, transparent);
  color: inherit;
  font: inherit;
  font-size: 0.7rem;
  font-weight: 600;
  cursor: pointer;
}

@media (prefers-reduced-motion: reduce) {
  .voice__play,
  .wave__bar {
    transition: none;
  }

  .voice__play:active {
    transform: none;
  }
}
</style>
