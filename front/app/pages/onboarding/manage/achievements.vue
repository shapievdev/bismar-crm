<script setup lang="ts">
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'
import type { Achievement } from '~/types/achievements'

/**
 * Каталог достижений: значки, которые шаги маршрута раздают сами.
 *
 * Выдачи на экране нет и быть не должно — достижение зарабатывают закрытым
 * шагом, а не вручают кнопкой. Здесь только справочник: завести, нарисовать,
 * убрать из выбора.
 *
 * Удаление называется «спрятать» намеренно: у тех, кто значок уже получил, он
 * остаётся — заслугу не отменяют правкой справочника.
 */
definePageMeta({ middleware: 'auth', permission: 'achievements.manage' })
useHead({ title: 'Достижения' })

const {
  fetchAchievements,
  createAchievement,
  updateAchievement,
  uploadImage,
  removeImage,
  hideAchievement,
  restoreAchievement,
} = useAchievementsApi()
const { confirm } = useAppDialog()

/** Какой список показан: рабочий или спрятанное. */
const showingHidden = ref(false)

const { data, pending, error, refresh } = await useAsyncData(
  'achievements.catalogue',
  () => fetchAchievements(showingHidden.value),
  { watch: [showingHidden] },
)

const achievements = computed<Achievement[]>(() => data.value?.data ?? [])

/* ---------- Форма ---------- */

/** null — форма закрыта, 0 — заводим новое, иначе номер правимого. */
const editing = ref<number | null>(null)
const isSaving = ref(false)
const formError = ref<string | null>(null)
const errors = ref<ValidationErrors>({})

const draft = reactive({ title: '', description: '', position: 0 })

/**
 * Картинка в форме: выбранный файл и то, что показать.
 *
 * Файл не уходит на сервер сразу — у нового достижения ещё нет номера, а
 * адрес загрузки от него и отсчитывается. Поэтому картинку запоминаем и
 * отправляем вторым шагом, сразу за сохранением: для человека это одно
 * действие, «завёл значок с картинкой», а не два похода в форму.
 *
 * `preview` — ссылка на локальный файл (object URL) у выбранного, иначе
 * загруженная ранее. Локальные ссылки освобождаются: браузер держит файл в
 * памяти, пока их не отозвать.
 */
const imageFile = ref<File | null>(null)
const localPreview = ref<string | null>(null)
const storedImage = ref<string | null>(null)

/** Убрать ли уже загруженную картинку при сохранении. */
const droppingImage = ref(false)

const preview = computed(() => localPreview.value ?? (droppingImage.value ? null : storedImage.value))

function releasePreview(): void {
  if (localPreview.value) {
    URL.revokeObjectURL(localPreview.value)
    localPreview.value = null
  }
}

function resetImage(stored: string | null): void {
  releasePreview()
  imageFile.value = null
  storedImage.value = stored
  droppingImage.value = false
}

function chooseImage(event: Event): void {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]

  // Поле очищается сразу: иначе второй выбор того же файла не вызовет события.
  input.value = ''

  if (!file) {
    return
  }

  releasePreview()
  imageFile.value = file
  localPreview.value = URL.createObjectURL(file)
  droppingImage.value = false
}

function forgetImage(): void {
  releasePreview()
  imageFile.value = null
  // Снимаем только то, что уже лежит в хранилище: отменённый выбор файла
  // ничего не удаляет.
  droppingImage.value = storedImage.value !== null
}

onBeforeUnmount(releasePreview)

function startCreating(): void {
  editing.value = 0
  formError.value = null
  errors.value = {}
  Object.assign(draft, { title: '', description: '', position: 0 })
  resetImage(null)
}

function startEditing(achievement: Achievement): void {
  editing.value = achievement.id
  formError.value = null
  errors.value = {}
  Object.assign(draft, {
    title: achievement.title,
    description: achievement.description ?? '',
    position: achievement.position,
  })
  resetImage(achievement.image_url)
}

function stopEditing(): void {
  editing.value = null
  resetImage(null)
}

async function save(): Promise<void> {
  if (editing.value === null) {
    return
  }

  isSaving.value = true
  formError.value = null
  errors.value = {}

  const payload = {
    title: draft.title.trim(),
    description: draft.description.trim() || null,
    position: draft.position,
  }

  /*
   * Номер сохранённого — за пределами try: если достижение завелось, а
   * картинка не прошла (файл велик, формат не тот), форма обязана остаться
   * открытой уже на правке. Иначе повторное «Сохранить» ушло бы заводить
   * второе достижение и упёрлось бы в занятое название.
   */
  let savedId: number | null = null

  try {
    const saved = editing.value === 0
      ? await createAchievement(payload)
      : await updateAchievement(editing.value, payload)

    savedId = saved.data.id

    // Картинка — вторым запросом, по номеру сохранённого: у нового
    // достижения номера до этой минуты не было.
    if (imageFile.value) {
      await uploadImage(savedId, imageFile.value)
    }
    else if (droppingImage.value) {
      await removeImage(savedId)
    }

    stopEditing()
    await refresh()
  }
  catch (exception) {
    if (exception instanceof ApiValidationError) {
      errors.value = exception.errors

      if (savedId !== null) {
        formError.value = exception.errors.image?.[0]
          ?? 'Достижение сохранено, а картинку загрузить не вышло — попробуйте другой файл.'
      }
    }
    else {
      formError.value = savedId === null
        ? 'Не удалось сохранить достижение.'
        : 'Достижение сохранено, а картинку загрузить не вышло.'
    }

    // Заведённое — уже в списке, и форма переезжает на его правку.
    if (savedId !== null) {
      editing.value = savedId
      releasePreview()
      imageFile.value = null
      droppingImage.value = false
    }

    await refresh()
  }
  finally {
    isSaving.value = false
  }
}

/* ---------- Картинка в строке списка ---------- */

/** Чью картинку сейчас убираем — чтобы кнопка гасла на своей строке. */
const uploadingFor = ref<number | null>(null)

async function dropImage(achievement: Achievement): Promise<void> {
  uploadingFor.value = achievement.id

  try {
    await removeImage(achievement.id)
    await refresh()
  }
  catch {
    formError.value = 'Не удалось убрать картинку.'
  }
  finally {
    uploadingFor.value = null
  }
}

/* ---------- Спрятать и вернуть ---------- */

async function hide(achievement: Achievement): Promise<void> {
  const owners = achievement.people_count ?? 0

  const agreed = await confirm({
    title: `Убрать «${achievement.title}» из выбора?`,
    message: owners > 0
      ? `Шаги перестанут его выдавать, но у ${owners} ${pluralise(owners, 'сотрудника', 'сотрудников', 'сотрудников')} `
        + 'оно останется в профиле: заслугу не отменяют правкой справочника.'
      : 'Шаги перестанут его выдавать. Вернуть его можно во вкладке спрятанных.',
    confirmLabel: 'Убрать',
    danger: true,
  })

  if (!agreed) {
    return
  }

  try {
    await hideAchievement(achievement.id)
    await refresh()
  }
  catch {
    formError.value = 'Не удалось убрать достижение.'
  }
}

async function restore(achievement: Achievement): Promise<void> {
  try {
    await restoreAchievement(achievement.id)
    await refresh()
  }
  catch {
    formError.value = 'Не удалось вернуть достижение.'
  }
}

function ownersLabel(count: number): string {
  return count === 0
    ? 'пока ни у кого'
    : `у ${count} ${pluralise(count, 'сотрудника', 'сотрудников', 'сотрудников')}`
}
</script>

<template>
  <section>
    <header class="page-header">
      <div class="page-header__row">
        <div>
          <h1>Достижения</h1>
          <p class="muted">
            Значки, которые сотрудник получает сам, закрыв шаг маршрута. Что за какой шаг
            дают, выбирают в этапе — здесь только справочник.
          </p>
        </div>

        <button type="button" class="button-primary" @click="startCreating">
          Новое достижение
        </button>
      </div>

      <div class="page-header__tools">
        <button
          type="button"
          class="button-ghost"
          @click="showingHidden = !showingHidden"
        >
          {{ showingHidden ? 'К рабочему списку' : 'Показать спрятанные' }}
        </button>
      </div>
    </header>

    <p v-if="formError" class="auth-alert" role="alert">
      {{ formError }}
    </p>

    <section v-if="editing !== null" class="card panel">
      <h2 class="panel__title">
        {{ editing === 0 ? 'Новое достижение' : 'Название и описание' }}
      </h2>

      <div class="field">
        <label class="field-label" for="achievement-title">Название</label>
        <input
          id="achievement-title"
          v-model="draft.title"
          class="input"
          type="text"
          placeholder="Первый выход в зал"
        >
        <p v-if="errors.title?.length" class="field-error">
          {{ errors.title[0] }}
        </p>
      </div>

      <div class="field">
        <label class="field-label" for="achievement-description">За что</label>
        <input
          id="achievement-description"
          v-model="draft.description"
          class="input"
          type="text"
          placeholder="Необязательно — например, «отработал первую смену рядом с наставником»"
        >
        <p v-if="errors.description?.length" class="field-error">
          {{ errors.description[0] }}
        </p>
      </div>

      <!--
        Картинка выбирается здесь же, вместе с названием: значок без неё —
        половина дела, и отправлять человека за ней во второй заход незачем.
        Файл уйдёт на сервер сразу за сохранением.
      -->
      <div class="field">
        <span class="field-label">Картинка</span>

        <div class="image">
          <span class="image__frame">
            <img v-if="preview" class="image__picture" :src="preview" alt="">
            <span v-else class="image__blank" aria-hidden="true">?</span>
          </span>

          <div class="image__actions">
            <label class="button-secondary button-file">
              {{ preview ? 'Выбрать другую' : 'Выбрать файл' }}
              <input
                type="file"
                accept="image/png,image/jpeg,image/webp"
                @change="chooseImage"
              >
            </label>

            <button v-if="preview" type="button" class="button-ghost" @click="forgetImage">
              Убрать
            </button>

            <p class="field-hint">
              PNG, JPG или WebP, до 4 МБ. Лучше с прозрачным фоном: значок рисуется
              поверх страницы как есть, его не обрезают ни в круг, ни в квадрат.
            </p>
          </div>
        </div>

        <p v-if="errors.image?.length" class="field-error">
          {{ errors.image[0] }}
        </p>
      </div>

      <div class="field">
        <label class="field-label" for="achievement-position">Место в списке</label>
        <input
          id="achievement-position"
          v-model.number="draft.position"
          class="input input--narrow"
          type="number"
          min="0"
          max="1000"
        >
        <p class="field-hint">
          Чем меньше число, тем выше значок в каталоге. Одинаковые идут по алфавиту.
        </p>
      </div>

      <div class="panel__actions">
        <button
          type="button"
          class="button-primary"
          :disabled="isSaving || !draft.title.trim()"
          @click="save"
        >
          Сохранить
        </button>
        <button type="button" class="button-ghost" :disabled="isSaving" @click="stopEditing">
          Отмена
        </button>
      </div>
    </section>

    <p v-if="pending && !data" class="muted">
      Загрузка…
    </p>

    <p v-else-if="error" class="auth-alert" role="alert">
      Не удалось загрузить достижения.
    </p>

    <UiEmptyState
      v-else-if="!achievements.length"
      :title="showingHidden ? 'Спрятанных нет' : 'Достижений пока нет'"
      :description="showingHidden
        ? 'Убранные из выбора значки появятся здесь — отсюда их и возвращают.'
        : 'Заведите значок и повесьте его на шаг маршрута: сотрудник получит его, закрыв шаг.'"
    />

    <ul v-else class="list">
      <li v-for="item in achievements" :key="item.id" class="card row">
        <AchievementsMedal :achievement="item" size="lg" :muted="item.is_hidden" />

        <div class="row__body">
          <p class="row__title">
            {{ item.title }}
          </p>
          <p v-if="item.description" class="row__text">
            {{ item.description }}
          </p>
          <p class="row__meta">
            {{ ownersLabel(item.people_count ?? 0) }}
            <template v-if="!item.image_url">
              · картинка не загружена
            </template>
          </p>
        </div>

        <div class="row__actions">
          <template v-if="!item.is_hidden">
            <button type="button" class="button-ghost" @click="startEditing(item)">
              Поправить
            </button>

            <button
              v-if="item.image_url"
              type="button"
              class="button-ghost"
              :disabled="uploadingFor === item.id"
              @click="dropImage(item)"
            >
              Убрать картинку
            </button>

            <button type="button" class="button-danger" @click="hide(item)">
              Спрятать
            </button>
          </template>

          <button v-else type="button" class="button-ghost" @click="restore(item)">
            Вернуть в выбор
          </button>
        </div>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.page-header {
  margin-bottom: 1.5rem;
}

.page-header__row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
}

.page-header__tools {
  margin-top: 1rem;
}

.page-header h1 {
  margin: 0 0 0.25rem;
  font-size: 1.5rem;
}

.muted {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.panel {
  display: flex;
  flex-direction: column;
  gap: 0.9rem;
  padding: 1.4rem 1.5rem;
  margin-bottom: 1.25rem;
}

.panel__title {
  margin: 0;
  font-size: 1.05rem;
}

.panel__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.field-hint {
  margin: 0;
  color: var(--color-text-faint);
  font-size: 0.82rem;
}

.list {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.row {
  display: flex;
  align-items: flex-start;
  gap: 1rem;
  padding: 1rem;
}

.row__body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.row__title {
  margin: 0;
  font-weight: 600;
}

.row__text {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.row__meta {
  margin: 0;
  color: var(--color-text-faint);
  font-size: 0.82rem;
}

.row__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  justify-content: flex-end;
}

/* Выбор файла — подписью, а не системной кнопкой: та рисуется браузером
   по-своему и в ряду с остальными выбивается. */
.button-file {
  position: relative;
  overflow: hidden;
  cursor: pointer;
}

.button-file input {
  position: absolute;
  inset: 0;
  opacity: 0;
  cursor: pointer;
}

.image {
  display: flex;
  align-items: center;
  gap: 1rem;
}

/* Клетка размером с будущий значок — чтобы заранее видеть, насколько он
   мелкий. Подложка в клетку тоже нужна: значки прозрачные, и на белом листе
   светлую картинку было бы не разглядеть, — но в самом интерфейсе её нет,
   там значок стоит на странице как есть. */
.image__frame {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 4.5rem;
  height: 4.5rem;
  padding: 0.25rem;
  border-radius: var(--radius-sm);
  background: var(--control-surface);
  color: var(--color-text-faint);
  font-size: 1.6rem;
  font-weight: 700;
}

/* contain: форму значка не обрезаем, вписываем целиком. */
.image__picture {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
}

.image__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.input--narrow {
  max-width: 7rem;
}

@media (max-width: 48rem) {
  .page-header__row {
    flex-direction: column;
  }
}

@media (max-width: 40rem) {
  .row {
    flex-wrap: wrap;
  }

  .row__actions {
    width: 100%;
    justify-content: flex-start;
  }
}
</style>
