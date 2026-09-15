<script setup lang="ts">
import type { MessageAttachment } from '~/types/chat'
import { clockOf } from '~/utils/chatTime'

/**
 * Снимки и записи одного сообщения — сеткой, как в мессенджерах.
 *
 * Три кадра в одном сообщении читаются как одно целое, а не как три сообщения
 * подряд. Раскладка задаётся числом кадров: один — во всю ширину и без обрезки
 * (вертикальный кадр телефона на квадратной плитке потерял бы половину), два —
 * в ряд, три и четыре — большой слева и остальные столбиком, пять — три сверху
 * и два снизу. Больше пяти файлов сервер не принимает, поэтому раскладок ровно
 * столько.
 */
const props = defineProps<{ files: MessageAttachment[] }>()

const emit = defineEmits<{ open: [file: MessageAttachment] }>()

const shape = computed(() => String(Math.min(props.files.length, 5)))

function isImage(file: MessageAttachment): boolean {
  return file.mime_type?.startsWith('image/') === true
}

/**
 * Длительность записи — та, что подписана в углу кадра.
 *
 * Узнаётся в браузере, из самой записи: сервер её не хранит, и добавить это в
 * ответ значит разбирать видео на стороне PHP. Браузер и так тянет заголовок
 * файла, чтобы показать первый кадр, — длительность приходит вместе с ним,
 * бесплатно. Пока заголовок не пришёл, подписи нет: врать про «0:00» хуже, чем
 * промолчать полсекунды.
 */
const durations = ref<Record<number, number>>({})

function noteDuration(file: MessageAttachment, event: Event): void {
  const seconds = (event.target as HTMLVideoElement).duration

  // У потоковой записи длительность бывает бесконечной, у битой — NaN.
  if (Number.isFinite(seconds) && seconds > 0) {
    durations.value = { ...durations.value, [file.id]: seconds }
  }
}
</script>

<template>
  <div class="album" :class="`album--${shape}`">
    <!--
      Вложение остаётся ссылкой намеренно: средний щелчок по снимку по-прежнему
      открывает его отдельной вкладкой, для тех, кому так удобнее. Перехвачено
      только обычное нажатие.
    -->
    <a
      v-for="file in files"
      :key="file.id"
      :href="file.url ?? '#'"
      target="_blank"
      rel="noopener noreferrer"
      class="cell"
      @click.prevent.stop="emit('open', file)"
    >
      <img v-if="isImage(file)" :src="file.url ?? ''" :alt="file.name" class="media" loading="lazy">

      <!-- У записи кадр берётся из неё самой: своих обложек сервер не делает, а
           имя файла в сетке ничего не показывает. -->
      <template v-else>
        <video
          class="media"
          :src="file.url ?? ''"
          preload="metadata"
          muted
          playsinline
          @loadedmetadata="noteDuration(file, $event)"
        />
        <span class="play" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M8 5.5v13l11-6.5z" fill="currentColor" /></svg>
        </span>
        <span v-if="durations[file.id]" class="clock">{{ clockOf(durations[file.id]!) }}</span>
      </template>
    </a>
  </div>
</template>

<style scoped>
/*
 * Своя ширина, а не «сколько дадут».
 *
 * Пузырь из одних снимков меряет себя по содержимому, а у незагруженной
 * картинки содержимого нет: ширина выходила нулевой, за ней — нулевой площадь,
 * а картинку нулевой площади браузер отложенной загрузкой не берёт вовсе. Круг
 * замыкался, и снимки не появлялись никогда.
 *
 * Двадцать рем — ширина, на которой снимок разглядывают, не открывая; в узкий
 * пузырь она ужмётся сама (`max-width`).
 */
.album {
  display: grid;
  gap: 2px;
  overflow: hidden;
  width: 20rem;
  max-width: 100%;
  border-radius: var(--radius-sm);
}

.cell {
  position: relative;
  display: block;
  overflow: hidden;
  background: var(--color-surface-sunken);
  line-height: 0;
}

.media {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 0.35s ease;
}

.cell:hover .media {
  transform: scale(1.03);
}

/* Один кадр показывается целиком: обрезать единственный снимок незачем, а
   высоту ограничиваем, чтобы вертикальный не занял весь экран. */
.album--1 {
  grid-template-columns: 1fr;
}

/*
 * Место под снимок держится до того, как он загрузится.
 *
 * У остальных раскладок высоту задаёт `aspect-ratio`, а у одиночной её взять
 * неоткуда: своих размеров кадра сервер не хранит, и до загрузки картинка имеет
 * нулевую высоту. Мало того что лента от этого дёргается, — браузер вовсе не
 * начинает грузить отложенную картинку, у которой не за что зацепиться, и
 * снимок не появляется никогда. Именно так пропадали одиночные фотографии.
 */
.album--1 .cell {
  min-height: 9rem;
}

.album--1 .media {
  max-height: 22rem;
  object-fit: contain;
  background: var(--color-surface-sunken);
}

.album--2 {
  grid-template-columns: 1fr 1fr;
  aspect-ratio: 2 / 1;
}

.album--3,
.album--4 {
  grid-template-columns: 2fr 1fr;
  aspect-ratio: 3 / 2;
}

.album--3 .cell:first-child,
.album--4 .cell:first-child {
  grid-row: span 2;
}

.album--4 {
  grid-template-rows: 1fr 1fr 1fr;
}

.album--4 .cell:first-child {
  grid-row: span 3;
}

.album--5 {
  grid-template-columns: repeat(6, 1fr);
  aspect-ratio: 3 / 2;
}

.album--5 .cell:nth-child(-n + 3) {
  grid-column: span 2;
}

.album--5 .cell:nth-child(n + 4) {
  grid-column: span 3;
}

.play {
  position: absolute;
  top: 50%;
  left: 50%;
  display: grid;
  place-items: center;
  width: 2.6rem;
  height: 2.6rem;
  border-radius: 50%;
  background: rgb(0 0 0 / 45%);
  color: #fff;
  transform: translate(-50%, -50%);
  backdrop-filter: blur(2px);
}

.play svg {
  width: 1.2rem;
  height: 1.2rem;
}

.clock {
  position: absolute;
  right: 0.35rem;
  bottom: 0.35rem;
  padding: 0.05rem 0.35rem;
  border-radius: var(--radius-pill);
  background: rgb(0 0 0 / 55%);
  color: #fff;
  font-size: 0.7rem;
  font-variant-numeric: tabular-nums;
  line-height: 1.5;
}

@media (prefers-reduced-motion: reduce) {
  .media {
    transition: none;
  }

  .cell:hover .media {
    transform: none;
  }
}
</style>
