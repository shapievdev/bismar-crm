<script setup lang="ts">
/**
 * Окно раздела настроек.
 *
 * На телефоне разворачивается во весь экран, на широком — карточкой по центру:
 * это одно и то же окно, и разной у него только рамка. Списку строк снаружи
 * нужно ровно это — раздел открывается поверх страницы и закрывается обратно в
 * ту же строку, откуда его открыли.
 *
 * Собрано на `<dialog>`, как и окно вопроса: браузер сам ловит Esc, не пускает
 * щелчки мимо и возвращает внимание туда, откуда окно открыли.
 */
const props = defineProps<{
  open: boolean
  title: string
  hint?: string
}>()

const emit = defineEmits<{ close: [] }>()

const element = ref<HTMLDialogElement | null>(null)

watch(() => props.open, async (open) => {
  if (!open) {
    element.value?.close()

    return
  }

  await nextTick()
  element.value?.showModal()
})

/*
 * Страницу под окном приходится придерживать самим: `showModal` перекрывает её
 * для мыши и клавиатуры, но палец на телефоне всё равно прокручивает список за
 * окном, и, закрыв его, читатель оказывается не там, где был.
 */
watch(() => props.open, (open) => {
  document.body.style.overflow = open ? 'hidden' : ''
})

onBeforeUnmount(() => {
  document.body.style.overflow = ''
})

/**
 * Щелчок мимо окна попадает в сам `<dialog>`: его рамка занимает весь экран, а
 * видимая карточка — это её содержимое. Проверка по цели отличает одно от
 * другого, иначе окно закрывалось бы от щелчка по любому своему полю.
 */
function onBackdrop(event: MouseEvent) {
  if (event.target === element.value) {
    emit('close')
  }
}
</script>

<template>
  <dialog
    ref="element"
    class="sheet"
    @click="onBackdrop"
    @cancel.prevent="emit('close')"
    @close="props.open && emit('close')"
  >
    <div class="sheet__frame">
      <header class="sheet__head">
        <div class="sheet__heading">
          <h2 class="sheet__title">
            {{ title }}
          </h2>
          <p v-if="hint" class="sheet__hint">
            {{ hint }}
          </p>
        </div>

        <button type="button" class="sheet__close" aria-label="Закрыть" @click="emit('close')">
          <svg
            viewBox="0 0 24 24"
            width="18"
            height="18"
            fill="none"
            stroke="currentColor"
            stroke-width="1.8"
            stroke-linecap="round"
            aria-hidden="true"
          >
            <path d="m6 6 12 12M18 6 6 18" />
          </svg>
        </button>
      </header>

      <div class="sheet__body">
        <slot />
      </div>
    </div>
  </dialog>
</template>

<style scoped>
.sheet {
  width: min(34rem, calc(100vw - 2rem));
  max-height: min(44rem, calc(100dvh - 3rem));
  padding: 0;
  border: 0;
  border-radius: var(--radius-lg);
  background: var(--color-surface-raised);
  color: var(--color-text);
  box-shadow: 0 24px 60px rgb(0 0 0 / 28%);
  overflow: hidden;
}

.sheet::backdrop {
  background: rgb(0 0 0 / 45%);
}

/* Шапка остаётся на месте, пока длинная форма едет под ней: на телефоне до
   кнопки закрытия иначе пришлось бы прокручивать обратно вверх. */
.sheet__frame {
  display: flex;
  flex-direction: column;
  max-height: inherit;
}

.sheet__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  padding: 1.3rem 1.4rem 0.9rem;
  border-bottom: 1px solid var(--color-border);
}

.sheet__heading {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  min-width: 0;
}

.sheet__title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 600;
}

.sheet__hint {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.87rem;
}

.sheet__close {
  display: grid;
  place-items: center;
  flex-shrink: 0;
  width: 2.1rem;
  height: 2.1rem;
  padding: 0;
  border: 0;
  border-radius: var(--radius-pill);
  background: var(--color-surface-sunken);
  color: var(--color-text-muted);
  cursor: pointer;
  transition: color 0.15s ease, background-color 0.15s ease;
}

.sheet__close:hover {
  background: var(--color-border-strong);
  color: var(--color-text);
}

.sheet__body {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1.3rem 1.4rem 1.4rem;
  overflow-y: auto;
  /* Докрутив форму до конца, дальше листают её же, а не страницу под окном. */
  overscroll-behavior: contain;
}

/*
 * На телефоне окно занимает экран целиком: раздел настроек там — не карточка
 * поверх списка, а место, куда переходят и откуда возвращаются. Отступы по
 * краям считаются от вырезов, иначе заголовок уходит под чёлку, а кнопка
 * сохранения — под полосу жестов.
 */
@media (max-width: 48rem) {
  .sheet {
    width: 100vw;
    max-width: none;
    height: 100dvh;
    max-height: none;
    margin: 0;
    border-radius: 0;
  }

  .sheet__frame {
    height: 100%;
    max-height: none;
  }

  .sheet__head {
    padding: max(1rem, env(safe-area-inset-top)) 1.15rem 0.9rem;
  }

  .sheet__body {
    flex: 1;
    padding: 1.15rem 1.15rem calc(1.5rem + env(safe-area-inset-bottom, 0px));
  }
}
</style>
