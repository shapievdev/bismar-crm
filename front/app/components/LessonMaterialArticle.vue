<script setup lang="ts">
import type { JSONContent } from '@tiptap/core'
import type { LessonAttachment, LessonMaterial } from '~/types/lms'
import { withResolvedMedia } from '~/utils/editor/attachments'

/**
 * Приложенный к уроку документ — развёрнутым, а не ссылкой.
 *
 * Урок объясняет, как делать, а правило говорит, как положено, и читают их
 * вместе. Ссылка уводила читателя со страницы посреди урока, и вернуться к
 * нему после трёх переходов удавалось не всегда — теперь правило раскрывается
 * здесь же.
 *
 * Статья приходит по раскрытию, отдельным запросом: приложить можно двадцать
 * регламентов, а прочитан будет один, — и раскрытое остаётся загруженным,
 * чтобы свернуть и развернуть обратно ничего не стоило.
 *
 * Свёрнутость держит сам браузер (`details`), поэтому с клавиатуры и голосом
 * это работает без нашей помощи.
 *
 * Материал остаётся самостоятельным: ссылка ведёт на его страницу, где живут
 * отметка об ознакомлении, проверка и файлы. Здесь только статья.
 */
const props = defineProps<{
  lessonId: number
  material: LessonMaterial
}>()

const { fetchLessonMaterialArticle } = useLmsApi()

const copy = computed(() =>
  useMaterialSection(props.material.kind === 'handbook' ? 'handbooks' : 'documents'))

const state = ref<'idle' | 'loading' | 'ready' | 'failed'>('idle')
const content = ref<JSONContent | null>(null)
const attachments = ref<LessonAttachment[]>([])

/**
 * Адреса вложенных картинок и видео подставляются на пути к экрану: статья
 * хранит их номера, а подписанные ссылки живут час.
 */
const article = computed(() => withResolvedMedia(content.value, attachments.value))

async function load() {
  if (state.value === 'loading' || state.value === 'ready') {
    return
  }

  state.value = 'loading'

  try {
    const { data } = await fetchLessonMaterialArticle(props.lessonId, props.material.slug)

    content.value = data.content_json ?? null
    attachments.value = data.attachments ?? []
    state.value = 'ready'
  }
  catch {
    state.value = 'failed'
  }
}

/**
 * Событие `toggle` приходит и на сворачивание — забирать статью нужно только
 * на раскрытии, и только в первый раз.
 */
function onToggle(event: Event) {
  if ((event.target as HTMLDetailsElement).open) {
    void load()
  }
}
</script>

<template>
  <details class="material" @toggle="onToggle">
    <summary class="material__head">
      <span class="material__kind">{{ copy.materialLabel }}</span>

      <span class="material__title">{{ material.title }}</span>

      <span v-if="!material.is_published" class="badge badge--warning">Черновик</span>
    </summary>

    <p v-if="material.summary" class="material__summary">
      {{ material.summary }}
    </p>

    <p v-if="state === 'loading'" class="faint material__note">
      Загружаем…
    </p>

    <p v-else-if="state === 'failed'" class="material__note" role="alert">
      Не удалось загрузить статью.
      <button type="button" class="button-ghost button-sm" @click="load">
        Ещё раз
      </button>
    </p>

    <!-- Статья рисуется тем же составом блоков, что и на своей странице: схема
         редактора одна на всё приложение, и разойтись показ с ней не может. -->
    <div v-else-if="article" class="prose material__article">
      <ClientOnly>
        <EditorRichTextRenderer :content="article" />
      </ClientOnly>
    </div>

    <p v-else-if="state === 'ready'" class="faint material__note">
      Статьи у материала пока нет — на его странице лежат только файлы.
    </p>

    <NuxtLink :to="material.path" class="material__open">
      Открыть {{ copy.materialLabel.toLocaleLowerCase('ru') }} отдельно →
    </NuxtLink>
  </details>
</template>

<style scoped>
.material {
  padding: 0.9rem 1.15rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
}

/* Заголовок — он же и переключатель: по нему нажимают, чтобы раскрыть, и
   курсор об этом говорит. Стрелку рисуем свою, потому что у стандартной
   треугольник встаёт в отдельную колонку и разъезжается с многострочным
   названием. */
.material__head {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 0.5rem;
  cursor: pointer;
  list-style: none;
}

.material__head::-webkit-details-marker {
  display: none;
}

.material__head::after {
  content: '▸';
  margin-left: auto;
  color: var(--color-text-muted);
  font-size: 0.8rem;
}

.material[open] .material__head::after {
  content: '▾';
}

.material__kind {
  color: var(--color-text-muted);
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.material__title {
  font-size: 1rem;
  font-weight: 550;
}

.material__summary {
  margin: 0.5rem 0 0;
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.material__note {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  margin: 0.85rem 0 0;
  font-size: 0.9rem;
}

/* Статья отделена линией, а не отступом: без неё правило читалось бы
   продолжением урока, а это чужой текст со своим адресом. */
.material__article {
  margin-top: 0.85rem;
  padding-top: 0.85rem;
  border-top: 1px solid var(--color-border);
}

.material__open {
  display: inline-block;
  margin-top: 0.85rem;
  color: var(--color-accent);
  font-size: 0.9rem;
  text-decoration: none;
}

.material__open:hover {
  text-decoration: underline;
}
</style>
