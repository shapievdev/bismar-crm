<script setup lang="ts">
import type { ChatPerson, MessageAttachment, ThreadMessage } from '~/types/chat'
import { useBubbleGestures } from '~/composables/useBubbleGestures'
import { chatTime } from '~/utils/chatTime'
import { formatBytes } from '~/utils/bytes'
import { firstLinkIn } from '~/utils/messageText'

/**
 * Одна реплика в ленте.
 *
 * Всё, что о ней известно, приходит сверху: пузырь ничего не спрашивает у
 * сервера и ничего не решает о разговоре. Он показывает и сообщает наружу, чего
 * от него хотят, — так его можно ставить и в ленту, и в полосу закреплённого, и
 * в предпросмотр пересылки.
 */
const props = defineProps<{
  message: ThreadMessage
  /** Своя реплика прижимается вправо, как во всяком чате. */
  mine: boolean
  /** В группе над чужой репликой стоит имя; в личной переписке оно лишнее. */
  showAuthor: boolean
  /** Дочитал ли собеседник до этого места — вторая галочка. */
  seen: boolean
  /** Участники: по ним узнаются упоминания в тексте. */
  people: ChatPerson[]
  me: number | null
  /** Подсвечена ли — перескочили к ней с цитаты, поиска или полосы. */
  found: boolean
  /** Идёт ли режим выделения: в нём нажатие по пузырю выбирает, а не открывает. */
  selecting: boolean
  selected: boolean
  /** Играет ли голосовое именно этой реплики. */
  playingVoice: number | null
}>()

const emit = defineEmits<{
  reply: []
  menu: [at: { x: number, y: number }]
  react: [emoji: string]
  toggleSelect: []
  jump: [messageId: number]
  openFile: [file: MessageAttachment]
  mention: [personId: number]
  playVoice: [attachmentId: number]
  pauseVoice: []
  retry: []
  cancel: []
}>()

/* ---------- Что в реплике лежит ---------- */

function isViewable(file: MessageAttachment): boolean {
  return file.mime_type?.startsWith('image/') === true || file.mime_type?.startsWith('video/') === true
}

/** Надиктованное: показывается волной, а не строкой с именем файла. */
const voice = computed(() => props.message.attachments?.find(file => file.is_voice) ?? null)

const media = computed(() => (props.message.attachments ?? []).filter(file => !file.is_voice && isViewable(file)))

/** Всё остальное — документы, архивы: они остаются строкой с именем. */
const papers = computed(() => (props.message.attachments ?? []).filter(file => !file.is_voice && !isViewable(file)))

/**
 * Реплика из одних снимков.
 *
 * Такой пузырь показывается без полей: снимок занимает его целиком, а время и
 * галочки ложатся поверх. Появились рядом слова, документ, цитата или подпись
 * «переслано» — полям снова есть что держать, и пузырь возвращается к обычному
 * виду.
 */
const isMediaOnly = computed(() => media.value.length > 0
  && !props.message.body
  && !props.message.reply_to
  && !props.message.forwarded
  && !props.message.about
  && papers.value.length === 0
  && voice.value === null)

/**
 * Ссылка, под которой встанет карточка.
 *
 * У ещё не отправленной реплики её нет: карточку читает сервер, а сообщения он
 * пока не видел, — да и спрашивать о ней в тот момент, когда байты ещё летят,
 * значит занимать соединение не тем.
 */
const link = computed(() => (props.message.sending || !props.message.body
  ? null
  : firstLinkIn(props.message.body)))

/* ---------- Жесты ---------- */

const { pull, willReply, handlers } = useBubbleGestures({
  onReply: () => emit('reply'),
  onMenu: at => emit('menu', at),
  enabled: () => !props.selecting && !props.message.sending,
})

/**
 * Что написано на уходящей реплике.
 *
 * Сто процентов — не «готово»: байты ушли, а сервер ещё раскладывает их по
 * хранилищу, и для крупного файла это отдельное ожидание. Поэтому на этом месте
 * не «100 %», а прямая речь о том, что происходит.
 */
const sendingLabel = computed(() => {
  if (!props.message.files?.length) {
    return 'Отправляется…'
  }

  const percent = Math.round(props.message.progress ?? 0)

  return percent >= 100 ? 'Сохраняем…' : `Отправляется… ${percent} %`
})

function onClick(): void {
  if (props.selecting) {
    emit('toggleSelect')
  }
}

/** Двойное нажатие ставит самый частый отклик — как в телеграме. */
function onDoubleClick(): void {
  if (!props.selecting && !props.message.sending) {
    emit('react', '👍')
  }
}
</script>

<template>
  <div
    class="line"
    :class="{ 'line--mine': mine, 'line--selecting': selecting, 'line--selected': selected }"
    :data-message="message.id"
  >
    <!-- Знак ответа выезжает из-под пузыря, пока его тянут вправо: он
         показывает, что будет, если отпустить. -->
    <span class="swipe" :class="{ 'swipe--ready': willReply }" :style="{ opacity: Math.min(1, pull / 40) }">
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path
          d="M9 14 4 9l5-5M4 9h7a8 8 0 0 1 8 8v3"
          stroke="currentColor"
          stroke-width="2"
          stroke-linecap="round"
          stroke-linejoin="round"
          fill="none"
        />
      </svg>
    </span>

    <label v-if="selecting" class="pick">
      <input type="checkbox" :checked="selected" @change="emit('toggleSelect')">
    </label>

    <div
      class="bubble"
      :class="{
        'bubble--mine': mine,
        'bubble--found': found,
        'bubble--photo': isMediaOnly,
        'bubble--sending': message.sending && !message.error,
        'bubble--failed': Boolean(message.error),
      }"
      :style="{ transform: pull ? `translateX(${pull}px)` : undefined }"
      v-on="handlers"
      @click="onClick"
      @dblclick="onDoubleClick"
    >
      <span v-if="showAuthor" class="bubble__author">
        {{ message.author?.name ?? 'Бывший сотрудник' }}
      </span>

      <!-- Откуда переслано. Стоит выше всего остального: сперва «кто это
           сказал», потом сказанное. -->
      <span v-if="message.forwarded" class="forwarded">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path
            d="M15 5l6 5-6 5v-3H9a5 5 0 0 0-5 5v-2a7 7 0 0 1 7-7h4z"
            fill="currentColor"
          />
        </svg>
        Переслано от {{ message.forwarded.author_name }}
      </span>

      <!-- Цитата: на что отвечали. Удалённая говорит об этом прямо, иначе ответ
           висел бы без того, с чем соглашались. -->
      <button
        v-if="message.reply_to"
        type="button"
        class="quote"
        :class="{ 'quote--gone': message.reply_to.deleted }"
        @click.stop="emit('jump', message.reply_to.id)"
      >
        <span class="quote__author">{{ message.reply_to.author?.name ?? 'Бывший сотрудник' }}</span>
        <span class="quote__text">
          {{ message.reply_to.deleted ? 'Сообщение удалено' : message.reply_to.excerpt }}
        </span>
      </button>

      <!-- С какого материала написали. Читается раньше самого текста: «здесь не
           хватило ответа» без названия того, где не хватило, заставляет автора
           переспрашивать. -->
      <NuxtLink v-if="message.about" :to="message.about.url ?? '/lms'" class="about" @click.stop>
        <span class="about__head">
          {{ message.about.kind_label }}
          <template v-if="message.about.reason_label">· {{ message.about.reason_label }}</template>
        </span>
        <span class="about__title">{{ message.about.title }}</span>
        <span v-if="message.about.context" class="about__context">{{ message.about.context }}</span>
      </NuxtLink>

      <ChatVoice
        v-if="voice"
        :file="voice"
        :playing="playingVoice === voice.id"
        @play="emit('playVoice', voice.id)"
        @pause="emit('pauseVoice')"
      />

      <p v-if="message.body" class="bubble__text">
        <ChatText
          :body="message.body"
          :people="people"
          :me="me"
          @mention="emit('mention', $event)"
        />
      </p>

      <!-- Карточка ссылки стоит под словами: сперва то, что сказал человек,
           потом то, чем назвал себя сайт. -->
      <ChatLinkCard v-if="link" :url="link" />

      <ChatAlbum v-if="media.length" :files="media" @open="emit('openFile', $event)" />

      <a
        v-for="file in papers"
        :key="file.id"
        :href="file.url ?? '#'"
        target="_blank"
        rel="noopener noreferrer"
        class="file"
        @click.stop
      >
        <UiFileIcon :name="file.name" :mime-type="file.mime_type" />
        <span class="file__body">
          <span class="file__name">{{ file.name }}</span>
          <span class="faint file__size">{{ formatBytes(file.size) }}</span>
        </span>
      </a>

      <!-- Пока реплика уходит, на месте времени — сколько байт ушло, и кнопка
           «отменить»: время у неё пока и не наступило. Сорвалась — причина и
           «повторить» тем же составом. -->
      <span v-if="message.sending" class="bubble__meta bubble__await">
        <template v-if="message.error">
          <span class="bubble__why">{{ message.error }}</span>
          <button type="button" class="bubble__retry" @click.stop="emit('retry')">Повторить</button>
          <button type="button" class="bubble__retry" @click.stop="emit('cancel')">Убрать</button>
        </template>
        <template v-else>
          {{ sendingLabel }}
          <button type="button" class="bubble__retry" @click.stop="emit('cancel')">Отменить</button>
        </template>
      </span>

      <span v-else class="bubble__meta">
        <svg v-if="message.pinned_at" class="bubble__pin" viewBox="0 0 24 24" aria-label="Закреплено" role="img">
          <path
            d="M9 3h6l-1 6 3 3v2H7v-2l3-3-1-6zM12 14v7"
            stroke="currentColor"
            stroke-width="1.8"
            stroke-linecap="round"
            stroke-linejoin="round"
            fill="none"
          />
        </svg>

        <!-- «изменено» стоит раньше времени: время относится к тому, когда
             сказали, а правка — к тому, что теперь написано. -->
        <span v-if="message.edited_at" :title="`Изменено ${chatTime(message.edited_at)}`">изменено</span>
        {{ chatTime(message.created_at) }}

        <!-- Одна галочка — отправлено, две — собеседник дочитал до этого места.
             Обводка берёт цвет текста, поэтому знак виден и на своём пузыре, и
             на чужом. -->
        <svg v-if="mine && seen" class="tick" viewBox="0 0 16 16" fill="none" aria-label="Прочитано" role="img">
          <path
            d="M14.5 4L7.5 12L4.5 9M4.5 12L1.5 9M11.5 4L7.25 8.875"
            stroke="currentColor"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
        <svg v-else-if="mine" class="tick" viewBox="0 0 20 20" fill="none" aria-label="Отправлено" role="img">
          <path d="M16 4L7.6 14L4 10.25" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </span>

      <!-- Полоса ухода байтов. Только у того, где есть что грузить: у текста она
           мигнула бы и исчезла. -->
      <span
        v-if="message.sending && !message.error && message.files?.length"
        class="bubble__progress"
        :style="{ '--sent': `${message.progress ?? 0}%` }"
      />

      <!-- Кнопка действий — для мыши. Пальцу то же самое даёт долгое нажатие, а
           отнимать у него долгое нажатие ради меню нельзя: оно уже занято
           выделением текста, которым сообщение копируют. -->
      <button
        v-if="!message.sending && !selecting"
        type="button"
        class="bubble__more"
        :aria-label="`Действия с сообщением от ${chatTime(message.created_at)}`"
        @click.stop="emit('menu', { x: 0, y: 0 })"
      >
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <circle cx="12" cy="5" r="1.7" fill="currentColor" />
          <circle cx="12" cy="12" r="1.7" fill="currentColor" />
          <circle cx="12" cy="19" r="1.7" fill="currentColor" />
        </svg>
      </button>
    </div>

    <ChatReactions
      v-if="message.reactions?.length"
      class="line__reactions"
      :reactions="message.reactions"
      :me="me"
      @react="emit('react', $event)"
    />
  </div>
</template>

<style scoped>
.line {
  position: relative;
  display: flex;
  max-width: min(78%, 34rem);
  flex-direction: column;
  align-items: flex-start;
  align-self: flex-start;
}

.line--mine {
  align-items: flex-end;
  align-self: flex-end;
}

/* В режиме выделения строка растягивается на всю ширину: галочка стоит с краю
   и не должна ездить вместе с пузырём. */
.line--selecting {
  max-width: none;
  flex-direction: row;
  align-items: center;
  gap: 0.6rem;
  align-self: stretch;
  padding: 0.1rem 0.2rem;
  border-radius: var(--radius);
  cursor: pointer;
  transition: background-color 0.15s ease;
}

.line--selected {
  background: var(--color-accent-soft);
}

.line--selecting.line--mine {
  flex-direction: row-reverse;
}

.pick {
  display: flex;
  flex-shrink: 0;
  align-items: center;
}

/* Знак ответа лежит под пузырём и открывается по мере того, как его тянут. */
.swipe {
  position: absolute;
  top: 50%;
  left: -2.1rem;
  display: grid;
  place-items: center;
  width: 1.7rem;
  height: 1.7rem;
  border-radius: 50%;
  background: var(--color-surface-sunken);
  color: var(--color-text-muted);
  opacity: 0;
  transform: translateY(-50%);
  pointer-events: none;
}

.swipe--ready {
  background: var(--color-accent);
  color: var(--color-accent-text);
}

.swipe svg {
  width: 0.95rem;
  height: 0.95rem;
}

.bubble {
  position: relative;
  max-width: 100%;
  padding: 0.5rem 0.7rem;
  border-radius: var(--radius);
  background: var(--color-surface-sunken);
  /* Тянется пальцем и возвращается сам: анимация возврата важнее самого
     смещения — без неё пузырь отскакивает рывком. */
  transition: transform 0.22s cubic-bezier(0.22, 1, 0.36, 1), box-shadow 0.2s ease;
}

/*
 * Свой пузырь залит цветом действия, и написанное в нём читается наоборот —
 * светлым по тёмному или тёмным по светлому, смотря по палитре.
 *
 * Вместе с цветом текста задаётся и краска подложек внутри: цитата, файл,
 * кусок кода и кружок проигрывания красятся ею, и в чужом пузыре она другая.
 * Раньше они брали её у `currentcolor` сами — но каналов у него не взять, а
 * без них нет и полупрозрачного оттенка. Теперь цвет едет рядом числами и тем
 * же путём — по наследству, так что вложенные ничего не спрашивают.
 */
.bubble--mine {
  background: var(--color-accent);
  color: var(--color-accent-text);
  --tint-rgb: var(--color-accent-text-rgb);
}

/* Перескочили сюда — подсветка гаснет сама: она отвечает на «куда меня
   перенесло», а дальше только мешает читать. */
.bubble--found {
  animation: found 1.6s ease;
}

@keyframes found {
  0%,
  60% { box-shadow: 0 0 0 3px var(--color-highlight); }
  100% { box-shadow: 0 0 0 3px transparent; }
}

.bubble--photo {
  padding: 0;
  overflow: hidden;
  background: transparent;
}

/* Уходящее чуть бледнее: видно, что оно ещё не там. */
.bubble--sending {
  opacity: 0.72;
}

.bubble--failed {
  outline: 1px solid var(--color-danger);
}

.bubble__author {
  display: block;
  margin-bottom: 0.15rem;
  font-size: 0.78rem;
  font-weight: 600;
  opacity: 0.8;
}

.bubble__text {
  margin: 0;
  font-size: 0.92rem;
  line-height: 1.45;
}

.forwarded {
  display: flex;
  align-items: center;
  gap: 0.3rem;
  margin-bottom: 0.25rem;
  font-size: 0.76rem;
  font-style: italic;
  opacity: 0.75;
}

.forwarded svg {
  width: 0.85rem;
  height: 0.85rem;
}

.quote {
  display: block;
  width: 100%;
  margin-bottom: 0.35rem;
  padding: 0.25rem 0.5rem;
  border: none;
  border-left: 2px solid currentcolor;
  border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
  background: rgba(var(--tint-rgb), 0.1);
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.quote:hover {
  background: rgba(var(--tint-rgb), 0.16);
}

.quote__author {
  display: block;
  font-size: 0.75rem;
  font-weight: 600;
}

.quote__text {
  display: -webkit-box;
  overflow: hidden;
  font-size: 0.78rem;
  opacity: 0.8;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
}

.quote--gone .quote__text {
  font-style: italic;
}

.about {
  display: block;
  margin-bottom: 0.35rem;
  padding: 0.35rem 0.55rem;
  border-radius: var(--radius-sm);
  background: rgba(var(--tint-rgb), 0.12);
  color: inherit;
  text-decoration: none;
}

.about__head {
  display: block;
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  opacity: 0.7;
}

.about__title {
  display: block;
  font-size: 0.82rem;
  font-weight: 600;
}

.about__context {
  display: block;
  font-size: 0.75rem;
  opacity: 0.75;
}

.file {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-top: 0.3rem;
  padding: 0.35rem 0.45rem;
  border-radius: var(--radius-sm);
  background: rgba(var(--tint-rgb), 0.1);
  color: inherit;
  text-decoration: none;
}

.file:hover {
  background: rgba(var(--tint-rgb), 0.16);
}

.file__body {
  display: flex;
  min-width: 0;
  flex-direction: column;
}

.file__name {
  overflow: hidden;
  font-size: 0.82rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.file__size {
  font-size: 0.72rem;
}

.bubble__meta {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 0.3rem;
  margin-top: 0.15rem;
  font-size: 0.68rem;
  line-height: 1.4;
  opacity: 0.7;
}

/* На пузыре из одних снимков подписи ложатся поверх кадра: под ним иначе
   осталась бы полоса подложки, которой нечего обрамлять. */
.bubble--photo .bubble__meta {
  position: absolute;
  right: 0.4rem;
  bottom: 0.35rem;
  margin: 0;
  padding: 0.05rem 0.4rem;
  border-radius: var(--radius-pill);
  background: rgb(0 0 0 / 45%);
  color: #fff;
  opacity: 1;
}

.bubble__pin {
  width: 0.72rem;
  height: 0.72rem;
}

.tick {
  width: 0.85rem;
  height: 0.85rem;
}

.bubble__await {
  gap: 0.45rem;
}

.bubble__why {
  color: var(--color-danger);
}

.bubble__retry {
  padding: 0;
  border: none;
  background: none;
  color: inherit;
  font: inherit;
  text-decoration: underline;
  cursor: pointer;
}

.bubble__progress {
  position: absolute;
  right: 0;
  bottom: 0;
  left: 0;
  height: 2px;
  border-radius: var(--radius-pill);
  background: rgba(var(--tint-rgb), 0.2);
}

.bubble__progress::after {
  content: '';
  display: block;
  width: var(--sent);
  height: 100%;
  border-radius: var(--radius-pill);
  background: currentcolor;
  transition: width 0.2s linear;
}

/* Кнопка действий появляется по наведению: постоянно висящее многоточие у
   каждого пузыря превращает ленту в частокол. */
.bubble__more {
  position: absolute;
  top: 0.1rem;
  display: grid;
  place-items: center;
  width: 1.5rem;
  height: 1.5rem;
  padding: 0;
  border: none;
  border-radius: 50%;
  background: var(--color-surface-raised);
  color: var(--color-text-muted);
  box-shadow: var(--shadow-sm);
  opacity: 0;
  cursor: pointer;
  transition: opacity 0.15s ease;
}

.bubble__more svg {
  width: 0.9rem;
  height: 0.9rem;
}

.bubble:not(.bubble--mine) .bubble__more {
  right: -1.8rem;
}

.bubble--mine .bubble__more {
  left: -1.8rem;
}

.bubble:hover .bubble__more,
.bubble__more:focus-visible {
  opacity: 1;
}

/* На телефоне её нет вовсе: там действия открывает долгое нажатие, а кнопка у
   края экрана всё равно не нажимается. */
@media (hover: none) {
  .bubble__more {
    display: none;
  }
}

.line__reactions {
  padding: 0 0.2rem;
}

@media (prefers-reduced-motion: reduce) {
  .bubble,
  .bubble__progress::after,
  .line--selecting {
    transition: none;
  }

  .bubble--found {
    animation: none;
    box-shadow: 0 0 0 3px var(--color-highlight);
  }
}
</style>
