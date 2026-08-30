<script setup lang="ts">
const { can, logout } = useAuth()

/*
 * Счётчик непрочитанного живёт здесь, потому что здесь на него смотрят: узнать
 * о сообщении человек должен на любой странице, а не только открыв мессенджер.
 * Подключение к сокетам заводится один раз отсюда же — рельса есть везде, где
 * человек вошёл.
 */
const messenger = useMessenger()

onMounted(() => messenger.connect())

/*
 * Сколько новостей ждут ознакомления. Считает сервер: значок висит на каждой
 * странице, и тянуть ради него всю ленту незачем.
 *
 * Число обновляется при возвращении на вкладку — тем же способом, что и чат с
 * консультантом: ни опроса, ни сокетов новостям не нужно.
 */
const { fetchPendingCount } = useNewsApi()
const pendingNews = ref(0)

async function refreshPendingNews() {
  try {
    pendingNews.value = (await fetchPendingCount()).data.count
  }
  catch {
    pendingNews.value = 0
  }
}

onMounted(() => {
  void refreshPendingNews()
  document.addEventListener('visibilitychange', onVisible)
})

onBeforeUnmount(() => document.removeEventListener('visibilitychange', onVisible))

function onVisible() {
  if (document.visibilityState === 'visible') {
    void refreshPendingNews()
  }
}
const router = useRouter()
const route = useRoute()

const isLoggingOut = ref(false)

interface RailItem {
  to: string
  label: string
  icon: string
  visible: boolean
  /** Which paths count as being inside this module. */
  matches: (path: string) => boolean
}

/**
 * Navigation for the platform as a whole: one entry per CRM module.
 *
 * Moving between modules is a rarer, heavier move than moving inside one, so
 * it lives here as icons and the top bar is left to the current module's own
 * pages.
 */
const items = computed<RailItem[]>(() => [
  {
    to: '/',
    label: 'Главная',
    icon: 'dashboard',
    visible: true,
    // Новости живут в этом же модуле: рельса ведёт на главную, а лента там же.
    matches: (path: string) => path === '/' || path.startsWith('/news'),
  },
  {
    to: '/lms',
    label: 'База знаний',
    icon: 'library',
    visible: can('courses.view'),
    matches: (path: string) => path.startsWith('/lms'),
  },
  {
    to: '/messenger',
    label: 'Сообщения',
    icon: 'messages',
    visible: true,
    matches: (path: string) => path.startsWith('/messenger'),
  },
  {
    to: '/analytics',
    label: 'Аналитика',
    icon: 'analytics',
    visible: can('analytics.view'),
    matches: (path: string) => path.startsWith('/analytics'),
  },
  {
    // Люди компании — свой модуль, а не страница настроек: заводят их,
    // раздают доступ и увольняют не «между делом», а придя именно за этим.
    // Структура компании открыта всем, поэтому и рельса видна всем; список
    // людей за ней — по праву, и без него модуль открывается структурой.
    to: can('users.view') ? '/staff' : '/staff/structure',
    label: 'Сотрудники',
    icon: 'staff',
    visible: true,
    matches: (path: string) => path.startsWith('/staff'),
  },
  {
    // Everyone has a profile, so this module is never hidden; which pages it
    // offers is decided by ModuleNav from the reader's permissions.
    to: '/settings/profile',
    label: 'Настройки',
    icon: 'settings',
    visible: true,
    matches: (path: string) => path.startsWith('/settings'),
  },
].filter(item => item.visible))

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
</script>

<template>
  <aside class="rail" aria-label="Разделы платформы">
    <NuxtLink
      v-for="item in items"
      :key="item.to"
      :to="item.to"
      class="rail__button"
      :class="{ 'rail__button--active': item.matches(route.path) }"
      :title="item.label"
      :aria-label="item.label"
      :aria-current="item.matches(route.path) ? 'page' : undefined"
    >
      <!-- Непрочитанное: цифра рядом со значком, как в любом мессенджере. -->
      <span v-if="item.icon === 'messages' && messenger.unreadTotal.value" class="rail__badge">
        {{ messenger.unreadTotal.value > 99 ? '99+' : messenger.unreadTotal.value }}
      </span>

      <!-- Тем же значком: новость, с которой обязали ознакомиться, ждёт
           внимания ровно так же, как непрочитанное сообщение. -->
      <span v-if="item.icon === 'dashboard' && pendingNews" class="rail__badge">
        {{ pendingNews > 99 ? '99+' : pendingNews }}
      </span>

      <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <!-- Домик: отсюда начинают, сюда возвращаются. -->
        <template v-if="item.icon === 'dashboard'">
          <path d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
        </template>

        <!-- Академическая шапочка: учат, а не выдают книги на дом. -->
        <template v-else-if="item.icon === 'library'">
          <path d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443m-7.007 11.55A5.981 5.981 0 0 0 6.75 15.75v-1.5" />
        </template>

        <!-- Облачко реплики: разговор, а не почта. -->
        <template v-else-if="item.icon === 'messages'">
          <path d="M12 20.25c4.97 0 9-3.694 9-8.25s-4.03-8.25-9-8.25S3 7.444 3 12c0 2.104.859 4.023 2.273 5.48.432.447.74 1.04.586 1.641a4.483 4.483 0 0 1-.923 1.785A5.969 5.969 0 0 0 6 21c1.282 0 2.47-.402 3.445-1.087.81.22 1.668.337 2.555.337Z" />
        </template>

        <!-- Растущий ряд столбцов: аналитика — про сравнение величин. -->
        <template v-else-if="item.icon === 'analytics'">
          <path d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
        </template>

        <!-- Трое рядом: люди компании, а не один человек в профиле. -->
        <template v-else-if="item.icon === 'staff'">
          <path d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
        </template>

        <template v-else-if="item.icon === 'settings'">
          <path d="M10.343 3.94c.09-.542.56-.94 1.11-.94h1.093c.55 0 1.02.398 1.11.94l.149.894c.07.424.384.764.78.93.398.164.855.142 1.205-.108l.737-.527a1.125 1.125 0 0 1 1.45.12l.773.774c.39.389.44 1.002.12 1.45l-.527.737c-.25.35-.272.806-.107 1.204.165.397.505.71.93.78l.893.15c.543.09.94.559.94 1.109v1.094c0 .55-.397 1.02-.94 1.11l-.894.149c-.424.07-.764.383-.929.78-.165.398-.143.854.107 1.204l.527.738c.32.447.269 1.06-.12 1.45l-.774.773a1.125 1.125 0 0 1-1.449.12l-.738-.527c-.35-.25-.806-.272-1.203-.107-.398.165-.71.505-.781.929l-.149.894c-.09.542-.56.94-1.11.94h-1.094c-.55 0-1.019-.398-1.11-.94l-.148-.894c-.071-.424-.384-.764-.781-.93-.398-.164-.854-.142-1.204.108l-.738.527c-.447.32-1.06.269-1.45-.12l-.773-.774a1.125 1.125 0 0 1-.12-1.45l.527-.737c.25-.35.272-.806.108-1.204-.165-.397-.506-.71-.93-.78l-.894-.15c-.542-.09-.94-.56-.94-1.109v-1.094c0-.55.398-1.02.94-1.11l.894-.149c.424-.07.765-.383.93-.78.165-.398.143-.854-.108-1.204l-.526-.738a1.125 1.125 0 0 1 .12-1.45l.773-.773a1.125 1.125 0 0 1 1.45-.12l.737.527c.35.25.807.272 1.204.107.397-.165.71-.505.78-.929l.15-.894Z" />
          <path d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
        </template>
      </svg>
    </NuxtLink>

    <button
      type="button"
      class="rail__button rail__button--foot"
      :disabled="isLoggingOut"
      title="Выйти"
      aria-label="Выйти"
      @click="handleLogout"
    >
      <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <path d="M15 4h2.5A2.5 2.5 0 0 1 20 6.5v11a2.5 2.5 0 0 1-2.5 2.5H15" />
        <path d="M10 8.5 6.5 12 10 15.5M6.5 12H16" />
      </svg>
    </button>
  </aside>
</template>

<style scoped>
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

/*
 * On narrow screens the rail lies down under the header instead of eating a
 * column of an already narrow page.
 */
@media (max-width: 60rem) {
  .rail {
    position: static;
    flex-direction: row;
    gap: 0.5rem;
    overflow-x: auto;
    padding-bottom: 0.25rem;
    scrollbar-width: none;
  }

  .rail::-webkit-scrollbar {
    display: none;
  }

  .rail__button--foot {
    margin-top: 0;
    margin-left: auto;
  }
}
</style>
