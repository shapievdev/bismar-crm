<script setup lang="ts">
/**
 * Разделы на телефоне — плавающая полоса у нижнего края.
 *
 * Внизу, а не сверху: там большой палец, и туда же смотрят все приложения, к
 * которым человек привык. В полосе — три раздела, которыми пользуются каждый
 * день, «ещё» и своё лицо; остальное открывается листом над полосой, потому что
 * шесть кружков в ряд на узком экране перестают быть кнопками.
 */
const { items, isCurrent } = useNavigation()
const { user, logout } = useAuth()
const route = useRoute()

/** Сколько разделов помещается в полосу, не считая «ещё» и профиля. */
const DOCK_LIMIT = 3

const primary = computed(() => items.value.slice(0, DOCK_LIMIT))
const rest = computed(() => items.value.slice(DOCK_LIMIT))

const isOpen = ref(false)

// Ушли на другую страницу — лист закрывается сам: он про выбор, а выбор сделан.
watch(() => route.fullPath, () => {
  isOpen.value = false
})

const isLoggingOut = ref(false)
const router = useRouter()

async function handleLogout() {
  isLoggingOut.value = true

  try {
    await logout()
    await router.push('/login')
  }
  finally {
    isLoggingOut.value = false
    isOpen.value = false
  }
}

/** Непрочитанное сворачивается в «99+»: точное число там всё равно не читают. */
function badge(count?: number): string | null {
  if (!count) {
    return null
  }

  return count > 99 ? '99+' : String(count)
}
</script>

<template>
  <div class="dock-layer">
    <!--
      Лист с остальными разделами. Полупрозрачная подложка гасит страницу и
      закрывает лист по нажатию мимо: так ведут себя все нижние листы, и учить
      этому отдельно не нужно.
    -->
    <Transition name="sheet">
      <div v-if="isOpen" class="sheet" @click.self="isOpen = false">
        <nav class="sheet__items" aria-label="Остальные разделы">
          <NuxtLink
            v-for="item in rest"
            :key="item.to"
            :to="item.to"
            class="row"
            :class="{ 'row--on': isCurrent(item) }"
          >
            <span class="row__icon">
              <AppNavIcon :name="item.icon" :size="20" />
              <span v-if="badge(item.badge)" class="row__badge">{{ badge(item.badge) }}</span>
            </span>
            <span class="row__label">{{ item.label }}</span>
          </NuxtLink>

          <button type="button" class="row" :disabled="isLoggingOut" @click="handleLogout">
            <span class="row__icon">
              <AppNavIcon name="logout" :size="20" />
            </span>
            <span class="row__label">{{ isLoggingOut ? 'Выходим…' : 'Выйти' }}</span>
          </button>
        </nav>
      </div>
    </Transition>

    <nav class="dock" aria-label="Разделы платформы">
      <NuxtLink
        v-for="item in primary"
        :key="item.to"
        :to="item.to"
        class="key"
        :class="{ 'key--on': isCurrent(item) }"
        :title="item.label"
        :aria-label="item.label"
        :aria-current="isCurrent(item) ? 'page' : undefined"
      >
        <AppNavIcon :name="item.icon" :size="20" />
        <span v-if="badge(item.badge)" class="key__badge">{{ badge(item.badge) }}</span>
      </NuxtLink>

      <button
        v-if="rest.length"
        type="button"
        class="key"
        :class="{ 'key--on': isOpen }"
        :aria-expanded="isOpen"
        :aria-label="isOpen ? 'Закрыть меню' : 'Ещё разделы'"
        @click="isOpen = !isOpen"
      >
        <AppNavIcon :name="isOpen ? 'close' : 'more'" :size="20" />
        <!-- Непрочитанное из спрятанных разделов видно и на «ещё»: иначе оно
             ждало бы за кнопкой, о которой человек не думает. -->
        <span v-if="!isOpen && badge(rest.reduce((sum, one) => sum + (one.badge ?? 0), 0))" class="key__dot" />
      </button>

      <!-- Своё лицо — вход в профиль: подпись здесь не нужна, человек узнаёт
           себя быстрее, чем читает. -->
      <NuxtLink
        to="/settings/profile"
        class="key key--face"
        :class="{ 'key--on': route.path.startsWith('/settings') }"
        title="Профиль"
        :aria-label="`Профиль: ${user?.name ?? ''}`"
      >
        <UserAvatar :name="user?.name" :src="user?.avatar_url" :size="34" />
      </NuxtLink>
    </nav>
  </div>
</template>

<style scoped>
/*
 * Полоса живёт только на узком экране: на широком разделы стоят слева рельсой,
 * и вторая навигация поверх страницы там ни к чему.
 */
.dock-layer {
  display: none;
}

@media (max-width: 60rem) {
  .dock-layer {
    display: block;
    position: fixed;
    inset: 0;
    z-index: 40;
    /* Слой не ловит нажатия — их ловят только сама полоса и лист. */
    pointer-events: none;
  }
}

/*
 * Полоса — та же поверхность, что у карточек, а не заливка акцентом.
 *
 * Акцентом её красить нельзя: в тёмной теме акцент — лайм, и выбранный пункт,
 * тоже лаймовый, растворялся в полосе целиком. Поверхность же в обеих темах
 * противопоставлена и странице (тенью), и выбранному кружку (заливкой), и
 * выбранный отмечается ровно так же, как на рельсе слева, — приложение
 * остаётся одним целым.
 */
.dock {
  position: absolute;
  left: 50%;
  bottom: calc(0.75rem + env(safe-area-inset-bottom, 0px));
  transform: translateX(-50%);
  display: flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.4rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-pill);
  background: var(--color-surface);
  box-shadow: 0 14px 34px rgb(0 0 0 / 22%);
  pointer-events: auto;
}

.key {
  position: relative;
  display: grid;
  place-items: center;
  width: 2.9rem;
  height: 2.9rem;
  flex-shrink: 0;
  border: 0;
  border-radius: 50%;
  background: transparent;
  color: var(--color-text-muted);
  cursor: pointer;
  text-decoration: none;
  transition: background-color 0.18s ease, color 0.18s ease, transform 0.18s ease;
}

.key:hover:not(.key--on) {
  background: var(--color-surface-sunken);
  color: var(--color-text);
}

.key:active {
  transform: scale(0.92);
}

/*
 * Выбранный раздел — залитый кружок в цвет акцента, как на рельсе: в светлой
 * теме почти чёрный на белой полосе, в тёмной лаймовый на тёмной. Ни в одной
 * он не сливается ни с полосой, ни с соседями.
 */
.key--on {
  background: var(--color-accent);
  color: var(--color-accent-text);
}

/* Аватарка сама себе фон: круг под ней только обрезал бы её края, а выбранность
   отмечается кольцом — заливка ушла бы под фотографию. */
.key--face {
  background: transparent;
}

.key--face:hover {
  background: transparent;
}

.key--face.key--on {
  background: transparent;
  box-shadow: 0 0 0 2px var(--color-accent);
}

.key__badge {
  position: absolute;
  top: -0.1rem;
  right: -0.1rem;
  min-width: 1.05rem;
  padding: 0 0.25rem;
  border-radius: var(--radius-pill);
  background: var(--color-accent);
  color: var(--color-accent-text);
  font-size: 0.65rem;
  font-weight: 700;
  line-height: 1.05rem;
  text-align: center;
}

/* Значок на выбранном кружке уже был бы того же цвета, что и он сам, — на нём
   метка рисуется наоборот. */
.key--on .key__badge {
  background: var(--color-surface);
  color: var(--color-text);
}

/* За «ещё» может ждать непрочитанное — о нём говорит точка, а не число:
   складывать сообщения с новостями в одну цифру значит соврать. */
.key__dot {
  position: absolute;
  top: 0.35rem;
  right: 0.35rem;
  width: 0.45rem;
  height: 0.45rem;
  border-radius: 50%;
  background: var(--color-accent);
  box-shadow: 0 0 0 2px var(--color-surface);
}

/* ---------- Лист с остальными разделами ---------- */

.sheet {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  padding: 1rem 1rem calc(5.5rem + env(safe-area-inset-bottom, 0px));
  background: color-mix(in srgb, var(--color-bg) 72%, transparent);
  backdrop-filter: blur(6px);
  pointer-events: auto;
}

.sheet__items {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

/*
 * Строка листа: круглый значок и пилюля с названием — те же формы, что и в
 * полосе, только развёрнутые в строку, где есть место для слов.
 */
.row {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0;
  border: 0;
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: left;
  text-decoration: none;
  cursor: pointer;
}

.row__icon {
  position: relative;
  display: grid;
  place-items: center;
  width: 3rem;
  height: 3rem;
  flex-shrink: 0;
  border-radius: 50%;
  background: var(--color-surface);
  color: var(--color-text);
  box-shadow: var(--shadow-sm);
}

.row__badge {
  position: absolute;
  top: -0.1rem;
  right: -0.1rem;
  min-width: 1.05rem;
  padding: 0 0.25rem;
  border-radius: var(--radius-pill);
  background: var(--color-accent);
  color: var(--color-accent-text);
  font-size: 0.65rem;
  font-weight: 700;
  line-height: 1.05rem;
  text-align: center;
}

.row__label {
  flex: 1;
  min-width: 0;
  padding: 0.85rem 1.1rem;
  border-radius: var(--radius-pill);
  background: var(--color-surface);
  box-shadow: var(--shadow-sm);
  font-size: 1.05rem;
  font-weight: 500;
}

/* Выбранная строка — тем же акцентом, что и кружок в полосе: одно правило на
   всё приложение, а не два похожих. */
.row--on .row__label,
.row--on .row__icon {
  background: var(--color-accent);
  color: var(--color-accent-text);
}

.sheet-enter-active,
.sheet-leave-active {
  transition: opacity 0.2s ease;
}

.sheet-enter-active .sheet__items,
.sheet-leave-active .sheet__items {
  transition: transform 0.22s ease;
}

.sheet-enter-from,
.sheet-leave-to {
  opacity: 0;
}

.sheet-enter-from .sheet__items,
.sheet-leave-to .sheet__items {
  transform: translateY(1rem);
}

@media (prefers-reduced-motion: reduce) {
  .key,
  .sheet-enter-active,
  .sheet-leave-active,
  .sheet-enter-active .sheet__items,
  .sheet-leave-active .sheet__items {
    transition: none;
  }
}
</style>
