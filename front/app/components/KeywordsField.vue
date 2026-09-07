<script setup lang="ts">
/**
 * Ключевые слова материала — слова, которыми его будут искать.
 *
 * Не одна строка через запятую: набранное сразу превращается в отдельные
 * значки, и человек видит, что «касса, ккм» — это два слова, а не одно длинное.
 * Убрать можно любое, не переписывая остальные.
 *
 * Разделителей два — Enter и запятая: первый привычен по любому полю тегов,
 * вторая — по тому, как эти же слова пишут в тексте. Пробел разделителем не
 * сделан намеренно: «холодная вода» — одно ключевое слово, а не два.
 */
const model = defineModel<string[]>({ required: true })

const props = withDefaults(defineProps<{
  id?: string
  /** Столько же, сколько принимает сервер: App\Support\Lms\Keywords. */
  limit?: number
  maxLength?: number
  errors?: string[]
}>(), { limit: 20, maxLength: 60 })

const draft = ref('')

const isFull = computed(() => model.value.length >= props.limit)

function add(raw: string) {
  const keyword = raw.replace(/\s+/g, ' ').trim()

  if (keyword === '' || keyword.length > props.maxLength || isFull.value) {
    return
  }

  // Сверяется без учёта регистра, а хранится как набрали: сервер поступает так
  // же, и «1С» не должно превратиться в «1с» по дороге.
  if (model.value.some(one => one.toLowerCase() === keyword.toLowerCase())) {
    draft.value = ''

    return
  }

  model.value = [...model.value, keyword]
  draft.value = ''
}

function remove(keyword: string) {
  model.value = model.value.filter(one => one !== keyword)
}

/**
 * Enter и запятая заканчивают слово; Backspace на пустом поле снимает
 * последнее — так ведут себя все поля такого рода, и рука делает это раньше,
 * чем глаз находит крестик.
 */
function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Enter' || event.key === ',') {
    event.preventDefault()
    add(draft.value)

    return
  }

  if (event.key === 'Backspace' && draft.value === '' && model.value.length) {
    remove(model.value[model.value.length - 1]!)
  }
}

/** Вставили список из документа — разбираем его по запятым и переводам строк. */
function onPaste(event: ClipboardEvent) {
  const text = event.clipboardData?.getData('text') ?? ''

  if (!/[,\n]/.test(text)) {
    return
  }

  event.preventDefault()
  text.split(/[,\n]/).forEach(add)
}

const inputId = useId()
const fieldId = computed(() => props.id ?? inputId)
</script>

<template>
  <div class="field">
    <label :for="fieldId">
      Ключевые слова <span class="field__optional">— по ним материал найдут поиском</span>
    </label>

    <ul v-if="model.length" class="chips">
      <li v-for="keyword in model" :key="keyword" class="chip">
        {{ keyword }}
        <button type="button" class="chip__remove" :aria-label="`Убрать «${keyword}»`" @click="remove(keyword)">
          ×
        </button>
      </li>
    </ul>

    <input
      :id="fieldId"
      v-model="draft"
      type="text"
      :maxlength="maxLength"
      :disabled="isFull"
      :placeholder="isFull ? `Больше ${limit} не нужно` : 'Слово и Enter'"
      @keydown="onKeydown"
      @blur="add(draft)"
      @paste="onPaste"
    >

    <p class="field__hint">
      Слова, которых нет в названии, но с которыми сюда придут: «пересорт»,
      «ККМ», «отпуск». Разделяйте запятой или Enter.
    </p>

    <p v-if="errors?.length" class="field__error">
      {{ errors[0] }}
    </p>
  </div>
</template>

<style scoped>
.field {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.field__optional {
  color: var(--color-text-faint);
  font-weight: 400;
}

.field__hint {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.82rem;
}

.field__error {
  margin: 0;
  color: var(--color-danger);
  font-size: 0.82rem;
}

.chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.chip {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.25rem 0.5rem 0.25rem 0.7rem;
  border-radius: var(--radius-pill);
  background: var(--color-surface-sunken);
  font-size: 0.85rem;
}

.chip__remove {
  padding: 0;
  border: 0;
  background: none;
  color: var(--color-text-muted);
  font: inherit;
  font-size: 1rem;
  line-height: 1;
  cursor: pointer;
}

.chip__remove:hover {
  color: var(--color-danger);
}

input {
  padding: 0.55rem 0.7rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--control-surface);
  color: var(--color-text);
  font: inherit;
}

input:disabled {
  color: var(--color-text-faint);
}
</style>
