<script setup lang="ts">
import type { FavouriteKind } from '~/types/lms'

/**
 * Звезда: отложить материал себе или снять закладку.
 *
 * Одна кнопка на все виды — курс, урок, документ, справочник, — потому что и
 * действие одно: у избранного нет ни состояний, ни степеней, только «отложено»
 * и «нет». Вид с номером нужны ей лишь затем, чтобы собрать адрес.
 *
 * Нажатие видно сразу, не дожидаясь сервера: откладывают материал между делом,
 * посреди чтения, и ждать ответа ради переключения звезды незачем. Сервер
 * отвечает тем же положением, а если не ответил — звезда возвращается на место
 * и говорит, что не вышло.
 */
const props = withDefaults(defineProps<{
  kind: FavouriteKind
  /** Номер самого материала, а не закладки. */
  materialId: number
  modelValue?: boolean
  /** Со словом рядом — там, где кнопка стоит в ряду с другими. */
  withLabel?: boolean
}>(), {
  modelValue: false,
  withLabel: false,
})

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  /** Сняли закладку — списку избранного это повод убрать строку. */
  'forgotten': []
}>()

const { keepFavourite, forgetFavourite } = useFavouritesApi()

/*
 * Своё положение, а не одно лишь свойство: звезда переключается в руках, а
 * родитель узнаёт об этом событием. Свойство при этом остаётся хозяином —
 * перечитанная страница возвращает кнопку в то положение, которое знает сервер.
 */
const isKept = ref(props.modelValue)

watch(() => props.modelValue, value => (isKept.value = value))

const isBusy = ref(false)
const failed = ref(false)

const label = computed(() => (isKept.value ? 'В избранном' : 'В избранное'))

const title = computed(() => {
  if (failed.value) {
    return 'Не удалось сохранить — нажмите ещё раз'
  }

  return isKept.value ? 'Убрать из избранного' : 'Отложить в избранное'
})

async function toggle() {
  if (isBusy.value) {
    return
  }

  const next = !isKept.value

  isKept.value = next
  isBusy.value = true
  failed.value = false
  emit('update:modelValue', next)

  try {
    if (next) {
      await keepFavourite(props.kind, props.materialId)
    }
    else {
      await forgetFavourite(props.kind, props.materialId)
      emit('forgotten')
    }
  }
  catch {
    // Вернуть на место и сказать об этом: молча оставленная звезда обещала бы
    // закладку, которой нет, и человек не нашёл бы материал там, где искал.
    isKept.value = !next
    failed.value = true
    emit('update:modelValue', !next)
  }
  finally {
    isBusy.value = false
  }
}
</script>

<template>
  <button
    type="button"
    :class="[
      withLabel ? 'button-secondary button-sm' : 'button-icon',
      'favourite',
      { 'favourite--kept': isKept, 'favourite--failed': failed },
    ]"
    :title="title"
    :aria-label="title"
    :aria-pressed="isKept"
    @click.stop.prevent="toggle"
  >
    <!-- Залитая звезда — отложено, контурная — нет. Цветом одного заливки мало:
         в чёрно-белой палитре «жёлтая против серой» не читается вовсе. -->
    <svg
      viewBox="0 0 24 24"
      width="17"
      height="17"
      :fill="isKept ? 'currentColor' : 'none'"
      stroke="currentColor"
      stroke-width="1.8"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
    >
      <path d="m12 3.6 2.6 5.27 5.82.85-4.21 4.1.99 5.78L12 16.87l-5.2 2.73.99-5.78-4.21-4.1 5.82-.85z" />
    </svg>

    <span v-if="withLabel">{{ label }}</span>
  </button>
</template>

<style scoped>
/*
 * Отложенное — цветом темы: своего у звезды нет, иначе она спорила бы с
 * палитрой, которую человек выбрал сам.
 *
 * Положение под курсором названо отдельно, и это не придирка к стилям:
 * `.button-icon:hover` перебивает один класс по весу селектора, и залитая
 * звезда теряла цвет ровно в тот миг, когда на неё навели, — то есть всегда,
 * сразу после нажатия. Та же оговорка стоит в main.css у `.button-icon--dark`.
 */
.favourite--kept,
.favourite--kept:hover:not(:disabled) {
  color: var(--color-accent);
}

.favourite--failed,
.favourite--failed:hover:not(:disabled) {
  color: var(--color-danger);
}

/* Заливка догоняет нажатие, а не появляется рывком: это единственное движение
   на странице, которым кнопка отвечает за себя. */
.favourite svg {
  transition: fill 0.15s ease;
}

@media (prefers-reduced-motion: reduce) {
  .favourite svg {
    transition: none;
  }
}
</style>
