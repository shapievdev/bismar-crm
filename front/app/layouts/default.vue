<script setup lang="ts">
const { user, isAuthenticated } = useAuth()

/*
 * Страница, которая меряет себя по экрану, а не растёт вместе с содержимым
 * (мессенджер). Нижний отступ оболочки под такой страницей снимается: всё,
 * что осталось ниже, делает документ длиннее экрана, а на телефоне это видно
 * как поле ввода, выехавшее за нижний край под клавиатуру.
 */
const route = useRoute()
const fills = computed(() => route.meta.fills === true)
</script>

<template>
  <div class="shell" :class="{ 'shell--fills': fills }">
    <header class="topbar">
      <div class="topbar__inner">
        <NuxtLink to="/" class="brand" aria-label="Bismar">
          <BrandMark :size="44" />
        </NuxtLink>

        <ModuleNav v-if="isAuthenticated" />

        <!-- The avatar alone: the name is on the profile page, and repeating it
             in every header only tells you what you already know. -->
        <NuxtLink
          v-if="isAuthenticated"
          to="/settings/profile"
          class="account"
          title="Профиль"
          :aria-label="`Профиль: ${user?.name ?? ''}`"
        >
          <UserAvatar :name="user?.name" :src="user?.avatar_url" :size="36" />
        </NuxtLink>
      </div>
    </header>

    <div class="body" :class="{ 'body--railed': isAuthenticated, 'body--fills': fills }">
      <SideRail v-if="isAuthenticated" />

      <main class="main">
        <slot />
      </main>
    </div>

    <!-- Вопросы к человеку задаются здесь: одно окно на приложение, чтобы
         экранам не приходилось заводить своё и чтобы браузерных не осталось. -->
    <AppDialog />
  </div>
</template>

<style scoped>
.shell {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

/*
 * Страница ровно в экран, без прокрутки документа.
 *
 * `dvh`, а не `vh`: на телефоне адресная строка прячется при прокрутке и
 * возвращается обратно, `vh` меряет по её отсутствию и потому промахивается —
 * снизу оставалась пустота. `dvh` меняется вместе с ней.
 *
 * Высоту считает браузер, а не скрипт. Прежде её выставлял `fit()` в пикселях
 * на каждое изменение видимой области, и на iOS это превращалось в гонку: при
 * прокрутке адресная строка едет, событие приходит на каждом кадре, скрипт
 * переписывает высоту — лента дёргалась. Одна строчка CSS делает то же самое
 * без единого события.
 */
.shell--fills {
  height: 100dvh;
  min-height: 0;
  /* Прокручивается лента внутри, а не сам документ: поле ввода обязано
     остаться на нижней кромке. */
  overflow: hidden;
}

/*
 * Растянуть строку сетки до низа.
 *
 * Одного `align-items: stretch` мало, и на этом я уже обжёгся: он растягивает
 * содержимое внутри строки, а сами строки остаются по содержимому. Страница
 * получалась в экран, а страница внутри неё — по написанному, и прокручивать
 * было нечего. Размер задаётся строке: `minmax(0, 1fr)`, где `0` разрешает ей
 * стать меньше содержимого — без него внутренняя прокрутка не включится.
 */
.shell--fills .body {
  min-height: 0;
  align-items: stretch;
  grid-template-rows: minmax(0, 1fr);
}

.shell--fills .main {
  min-height: 0;
  height: 100%;
}

.topbar {
  position: sticky;
  top: 0;
  z-index: 10;
  height: var(--header-height);
  background: color-mix(in srgb, var(--color-bg) 85%, transparent);
  backdrop-filter: blur(12px);
}

.topbar__inner {
  display: flex;
  align-items: center;
  gap: 1.25rem;
  height: 100%;
  max-width: 82rem;
  margin: 0 auto;
  padding: 0 1.75rem;
}

/*
 * Neutral on purpose. The tile takes the text colour and the glyph the page
 * colour, so the mark reads as brand rather than as state — lime is reserved
 * for the active pill, and a lime logo beside it would say the same thing
 * twice.
 */
.brand {
  display: flex;
  align-items: center;
  color: var(--color-text);
  --brand-glyph: var(--color-bg);
  text-decoration: none;
}

.account {
  display: flex;
  align-items: center;
  flex-shrink: 0;
  margin-left: auto;
  color: inherit;
  text-decoration: none;
}

@media (max-width: 56rem) {
  .topbar__inner {
    /* The brand and account stay put; only the destinations scroll. */
    gap: 0.6rem;
    padding: 0 1rem;
  }
}

/*
 * One column by default. The rail's column only exists when the rail does —
 * otherwise a guest's login card would be dropped into the narrow slot meant
 * for it.
 */
.body {
  flex: 1;
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 1.5rem;
  width: 100%;
  max-width: 82rem;
  margin: 0 auto;
  padding: 1.5rem 1.75rem 5rem;
  align-items: start;
}

.body--railed {
  grid-template-columns: 2.9rem minmax(0, 1fr);
}

/*
 * См. комментарий у `fills` выше: под такой страницей не должно оставаться
 * ничего, иначе документ длиннее экрана и низ страницы недосягаем.
 *
 * Оба класса в селекторе намеренно: ниже, в медиазапросе для узких экранов,
 * `.body` заново назначает себе padding целиком. Специфичность у них была бы
 * одинаковой, и побеждал бы тот, что идёт позже, — то есть на телефоне отступ
 * возвращался бы. Ровно это и происходило: на столе всё было верно, а на
 * телефоне страница прокручивалась на высоту отступа.
 */
.body.body--fills {
  padding-bottom: 0;
}

.main {
  min-width: 0;
}

@media (max-width: 60rem) {
  .body,
  .body--railed {
    grid-template-columns: minmax(0, 1fr);
    gap: 1rem;
    padding: 1rem 1rem 4rem;
  }

  /* Здесь рельса разделов — не колонка слева, а строка сверху, и строк
     становится две: ей по содержимому, странице всё остальное. */
  .shell--fills .body--railed {
    grid-template-rows: auto minmax(0, 1fr);
  }
}
</style>