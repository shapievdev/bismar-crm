<script setup lang="ts">
/**
 * Разделы платформы — колонка значков слева.
 *
 * Переход между разделами — движение более редкое и тяжёлое, чем переход внутри
 * одного, поэтому он живёт здесь значками, а верхняя полоса оставлена страницам
 * текущего раздела.
 *
 * Список разделов общий с доком на телефоне (useNavigation): два списка
 * расходятся ровно в тот день, когда раздел добавляют в один и забывают про
 * другой.
 */
const { items, isCurrent } = useNavigation()
const { logout } = useAuth()
const router = useRouter()

const isLoggingOut = ref(false)

async function handleLogout() {
  isLoggingOut.value = true

  try {
    await logout()
    await router.push('/login')
  }
  finally {
    isLoggingOut.value = false
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
  <aside class="rail" aria-label="Разделы платформы">
    <NuxtLink
      v-for="item in items"
      :key="item.to"
      :to="item.to"
      class="rail__button"
      :class="{ 'rail__button--active': isCurrent(item) }"
      :title="item.label"
      :aria-label="item.label"
      :aria-current="isCurrent(item) ? 'page' : undefined"
    >
      <!-- Непрочитанное: цифра рядом со значком, как в любом мессенджере. -->
      <span v-if="badge(item.badge)" class="rail__badge">{{ badge(item.badge) }}</span>

      <AppNavIcon :name="item.icon" />
    </NuxtLink>

    <button
      type="button"
      class="rail__button rail__button--foot"
      :disabled="isLoggingOut"
      title="Выйти"
      aria-label="Выйти"
      @click="handleLogout"
    >
      <AppNavIcon name="logout" />
    </button>
  </aside>
</template>

<style scoped>
.rail {
  position: sticky;
  top: calc(var(--header-height) + 1rem);
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  padding-top: 0.25rem;
}

.rail__button {
  position: relative;
  display: grid;
  place-items: center;
  width: 2.9rem;
  height: 2.9rem;
  flex-shrink: 0;
  border: 0;
  border-radius: var(--radius-pill);
  background: var(--color-surface-raised);
  color: var(--color-text-muted);
  cursor: pointer;
  text-decoration: none;
  transition: background-color 0.15s ease, color 0.15s ease;
}

.rail__button:hover:not(:disabled) {
  color: var(--color-text);
  background: var(--color-surface-sunken);
}

.rail__button--active {
  background: var(--color-accent);
  color: var(--color-accent-text);
}

.rail__button--active:hover {
  background: var(--color-accent-hover);
  color: var(--color-accent-text);
}

/* Sits apart from the modules above it: this one leaves the application. */
.rail__button--foot {
  margin-top: 1.4rem;
}

.rail__button:disabled {
  opacity: 0.45;
  cursor: default;
}

.rail__badge {
  position: absolute;
  top: -0.15rem;
  right: -0.15rem;
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

/*
 * На узком экране рельсы нет вовсе: там разделы живут в плавающей полосе у
 * нижнего края (MobileDock), где до них дотягивается большой палец.
 */
@media (max-width: 60rem) {
  .rail {
    display: none;
  }
}
</style>
