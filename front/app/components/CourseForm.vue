<script setup lang="ts">
import type { SelectOption } from '~/components/ui/Select.vue'
import type { ValidationErrors } from '~/composables/useAuth'
import type { Category, CoursePayload, CourseStatus, CourseVisibility, StatusOption } from '~/types/lms'

const props = defineProps<{
  statuses: StatusOption[]
  categories: Category[]
  errors: ValidationErrors
  isSubmitting: boolean
  submitLabel: string
  /**
   * Может ли этот человек закрыть курс. Решает это автор — у нового курса им
   * становится тот, кто его заводит, поэтому на создании выбор есть всегда.
   */
  canManageAccess: boolean
  /**
   * Статус, в котором материал сохранён. У создаваемого его нет.
   *
   * Нужен, чтобы показать статус тому, кто не вправе его назначить: у
   * опубликованного материала поле иначе выглядит пустым, будто статус потерян,
   * и первое же сохранение уводит его в черновик.
   */
  savedStatus?: CourseStatus | null
}>()

const emit = defineEmits<{ submit: [payload: CoursePayload] }>()

const model = defineModel<CoursePayload>({ required: true })

const { can } = useAuth()

/**
 * Публикация — отдельное право, и без него «Опубликован» не предлагается: выбор,
 * который сервер отклонит, предлагать незачем. С
 * одной оговоркой: уже опубликованный материал остаётся в списке, потому что
 * снимать его с публикации никто не просил. Сервер судит так же — право нужно
 * на перевод в этот статус, а не на пребывание в нём.
 */
const statusOptions = computed<SelectOption<CourseStatus>[]>(() =>
  props.statuses
    .filter(status => status.value !== 'published'
      || can('courses.publish')
      || props.savedStatus === 'published')
    .map(status => ({ value: status.value, label: status.label })),
)

/**
 * Подпись под каждым вариантом обязательна: «открытый» и «приватный» — слова
 * знакомые, а вот кому именно виден приватный курс, из них не следует.
 */
const visibilityOptions: SelectOption<CourseVisibility>[] = [
  { value: 'public', label: 'Открытый', hint: 'Виден всем, кто может читать базу знаний' },
  { value: 'private', label: 'Приватный', hint: 'Виден автору и тем, кого он добавил' },
]
</script>

<template>
  <form class="course-form" novalidate @submit.prevent="emit('submit', model)">
    <div class="course-form__main">
      <FormField id="title" v-model="model.title" label="Название" :errors="errors.title" />

      <div class="field">
        <label for="summary">Краткое описание</label>
        <textarea id="summary" v-model="model.summary" rows="2" maxlength="500" />
        <p v-if="errors.summary?.length" class="field__error">
          {{ errors.summary[0] }}
        </p>
      </div>

      <div class="field">
        <label for="description">Описание</label>
        <textarea id="description" v-model="model.description" rows="8" />
        <p v-if="errors.description?.length" class="field__error">
          {{ errors.description[0] }}
        </p>
      </div>
    </div>

    <!--
      Свойства курса — не то же, что его текст: их выбирают один раз и
      мельком, поэтому они собраны панелью справа, а не растянуты под описанием.
      Кнопки живут там же — решение сохранить или удалить принимают, глядя
      именно на них.
    -->
    <aside class="course-form__side card">
      <div class="field">
        <label for="category">Категория</label>
        <CategoryTreeSelect id="category" v-model="model.category_id" :categories="categories" />
        <p v-if="errors.category_id?.length" class="field__error">
          {{ errors.category_id[0] }}
        </p>
      </div>

      <div class="field">
        <label for="status">Статус</label>
        <UiSelect id="status" v-model="model.status" :options="statusOptions" />
        <p v-if="errors.status?.length" class="field__error">
          {{ errors.status[0] }}
        </p>
      </div>

      <div v-if="canManageAccess" class="field">
        <label for="visibility">Доступ</label>
        <UiSelect id="visibility" v-model="model.visibility" :options="visibilityOptions" />
        <p v-if="model.visibility === 'private'" class="field__hint">
          Кого пускать в курс — ниже, под формой.
        </p>
        <p v-if="errors.visibility?.length" class="field__error">
          {{ errors.visibility[0] }}
        </p>
      </div>

      <div class="actions">
        <button type="submit" class="button-primary" :disabled="isSubmitting">
          {{ isSubmitting ? 'Сохраняем…' : submitLabel }}
        </button>
        <slot name="secondary-actions" />
      </div>
    </aside>
  </form>
</template>

<style scoped>
/*
 * Текст слева, свойства панелью справа.
 *
 * Ширина текстовых полей ограничена: строка на всю страницу неудобна и при
 * наборе, и при чтении. Панель встаёт к правому краю — там, где её ждут
 * остальные панели страницы, — а между ними остаётся воздух, а не пустота
 * справа от формы.
 */
.course-form {
  display: grid;
  grid-template-columns: minmax(0, 42rem) minmax(0, 20rem);
  justify-content: space-between;
  align-items: start;
  gap: 1.5rem;
}

.course-form__main,
.course-form__side {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  min-width: 0;
}

.course-form__side {
  padding: 1.15rem 1.25rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.field label {
  font-size: 0.875rem;
  font-weight: 500;
}

.field textarea,
.field select {
  padding: 0.55rem 0.7rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface);
  color: var(--color-text);
  font: inherit;
  resize: vertical;
}

.field__error {
  margin: 0;
  color: var(--color-danger);
  font-size: 0.825rem;
}

.field__hint {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.825rem;
}

/*
 * В колонке кнопки идут друг под другом и во всю её ширину: в ряд они не встают,
 * а «Сохранить» рядом с «Удалить» — соседство, которого лучше избегать.
 */
.actions {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 0.6rem;
  margin-top: 0.25rem;
  padding-top: 1rem;
  border-top: 1px solid var(--color-border);
}

/* Узкое окно и телефон: панель уходит под текст и равняется по нему. */
@media (max-width: 64rem) {
  .course-form {
    grid-template-columns: minmax(0, 1fr);
    max-width: 42rem;
  }

  /* Панель здесь во всю ширину текста, поэтому кнопки берут ширину по себе. */
  .actions {
    align-items: flex-start;
  }
}
</style>
