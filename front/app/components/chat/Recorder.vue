<script setup lang="ts">
import type { Recording } from '~/composables/useVoiceRecorder'
import { useVoiceRecorder } from '~/composables/useVoiceRecorder'
import { clockOf } from '~/utils/chatTime'
import { VOICE_BARS } from '~/utils/voice'

/**
 * Запись голосового: таймер, живая волна, «отменить» и «отправить».
 *
 * Нажал — пишет, нажал ещё раз — отправилось. Не удержание, хотя в телеграме на
 * телефоне именно оно: удержание требует, чтобы палец всё это время оставался на
 * той же кнопке, а кнопка здесь появляется только с началом записи — полосу
 * ввода подменяет эта. Держать палец на элементе, которого в момент нажатия ещё
 * не было, нельзя, а подделывать это значит городить общий обработчик на две
 * разметки ради жеста, который мышью всё равно не повторить.
 *
 * Живая волна — не украшение: без неё человек не видит, слышно ли его, и узнаёт
 * о выключенном микрофоне, только отправив тишину.
 */
const emit = defineEmits<{ done: [recording: Recording], cancel: [] }>()

const { isRecording, elapsed, levels, failure, start, stop, cancel } = useVoiceRecorder()

/** Насколько утянули кнопку влево: смахивание отменяет, как в телеграме. */
const pulled = ref(0)

const willCancel = computed(() => pulled.value > 70)

/** Волна рисуется хвостом: в полоску помещается столько же, сколько уедет. */
const tail = computed(() => levels.value.slice(-VOICE_BARS))

onMounted(async () => {
  if (!await start()) {
    // Отказали в микрофоне — сообщение об этом покажется здесь же, и закрывать
    // полосу сразу нельзя: человек не успеет его прочесть.
  }
})

async function finish(): Promise<void> {
  if (willCancel.value) {
    drop()

    return
  }

  const recording = await stop()

  pulled.value = 0

  // Меньше полусекунды — это не сообщение, а случайное касание кнопки.
  recording ? emit('done', recording) : emit('cancel')
}

function drop(): void {
  cancel()
  pulled.value = 0
  emit('cancel')
}

let startX = 0

function onTouchStart(event: TouchEvent): void {
  startX = event.touches[0]?.clientX ?? 0
}

function onTouchMove(event: TouchEvent): void {
  const touch = event.touches[0]

  if (touch && isRecording.value) {
    pulled.value = Math.max(0, startX - touch.clientX)
  }
}

function onTouchEnd(): void {
  if (willCancel.value) {
    drop()
  }

  pulled.value = 0
}
</script>

<template>
  <div class="rec" :class="{ 'rec--cancelling': willCancel }">
    <template v-if="failure">
      <p class="rec__failure">
        {{ failure }}
      </p>
      <button type="button" class="rec__drop" aria-label="Закрыть" @click="emit('cancel')">
        ✕
      </button>
    </template>

    <template v-else>
      <span class="rec__dot" aria-hidden="true" />

      <span class="rec__clock">{{ clockOf(elapsed / 1000) }}</span>

      <span class="wave" aria-hidden="true">
        <span
          v-for="(height, index) in tail"
          :key="index"
          class="wave__bar"
          :style="{ height: `${Math.max(10, height)}%` }"
        />
      </span>

      <span class="rec__hint faint">← смахните, чтобы отменить</span>

      <button type="button" class="rec__drop" aria-label="Отменить запись" @click="drop">
        ✕
      </button>

      <button
        type="button"
        class="rec__send"
        :style="{ transform: pulled ? `translateX(${-Math.min(pulled, 90)}px)` : undefined }"
        aria-label="Отправить запись"
        @click="finish"
        @touchstart.passive="onTouchStart"
        @touchmove.passive="onTouchMove"
        @touchend="onTouchEnd"
      >
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path
            d="M3.4 20.4l17.45-7.48a1 1 0 0 0 0-1.84L3.4 3.6a.993.993 0 0 0-1.39.91L2 9.12c0 .5.37.93.87.99L17 12 2.87 13.88c-.5.07-.87.5-.87 1l.01 4.61c0 .71.73 1.2 1.39.91z"
            fill="currentColor"
          />
        </svg>
      </button>
    </template>
  </div>
</template>

<style scoped>
.rec {
  display: flex;
  width: 100%;
  align-items: center;
  gap: 0.55rem;
  padding: 0.35rem 0.5rem;
  border-radius: var(--radius-pill);
  background: var(--color-surface-sunken);
  transition: background-color 0.2s ease;
}

/* Дотянули до отмены — полоса краснеет: понять это надо, не отрывая взгляда от
   того места, куда смотрит палец. */
.rec--cancelling {
  background: var(--color-danger-soft);
}

.rec__dot {
  width: 0.55rem;
  height: 0.55rem;
  flex-shrink: 0;
  border-radius: 50%;
  background: var(--color-danger);
  animation: beat 1.1s ease-in-out infinite;
}

@keyframes beat {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.25; }
}

.rec__clock {
  flex-shrink: 0;
  font-size: 0.85rem;
  font-variant-numeric: tabular-nums;
}

.wave {
  display: flex;
  height: 1.5rem;
  min-width: 0;
  flex: 1;
  align-items: center;
  gap: 2px;
}

.wave__bar {
  flex: 1;
  min-width: 2px;
  border-radius: var(--radius-pill);
  background: var(--color-text-muted);
}

.rec__hint {
  flex-shrink: 0;
  font-size: 0.72rem;
}

/* На столе смахивать нечем: там отменяют крестиком. */
@media (hover: hover) {
  .rec__hint {
    display: none;
  }
}

.rec__drop,
.rec__send {
  display: grid;
  flex-shrink: 0;
  place-items: center;
  width: 2.1rem;
  height: 2.1rem;
  padding: 0;
  border: none;
  border-radius: 50%;
  background: transparent;
  color: var(--color-text-muted);
  font: inherit;
  cursor: pointer;
}

.rec__send {
  background: var(--color-accent);
  color: var(--color-accent-text);
  transition: transform 0.18s cubic-bezier(0.22, 1, 0.36, 1);
}

.rec__send svg {
  width: 1.1rem;
  height: 1.1rem;
}

.rec__failure {
  margin: 0;
  flex: 1;
  padding: 0.2rem 0.4rem;
  color: var(--color-danger);
  font-size: 0.82rem;
}

@media (prefers-reduced-motion: reduce) {
  .rec,
  .rec__send {
    transition: none;
  }

  .rec__dot {
    animation: none;
  }
}
</style>
