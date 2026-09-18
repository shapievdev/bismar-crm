<script setup lang="ts">
const { user, isAuthenticated } = useAuth()

/*
 * Подключение к сокетам и счётчик новостей заводятся здесь, а не в рельсе:
 * на телефоне рельсы нет вовсе, а знать о сообщении человек должен на любом
 * экране.
 */
const messenger = useMessenger()
const { refreshBadges } = useNavigation()

onMounted(() => {
  if (!isAuthenticated.value) {
    return
  }

  messenger.connect()
  void refreshBadges()
  document.addEventListener('visibilitychange', onVisible)
})

onBeforeUnmount(() => document.removeEventListener('visibilitychange', onVisible))

// Вернулись на вкладку — перечитываем счётчик: новостям ни опроса, ни сокетов
// не нужно, а число к этому времени могло измениться.
function onVisible() {
  if (document.visibilityState === 'visible') {
    void refreshBadges()
  }
}

/*
 * Страница, которая меряет себя по экрану, а не растёт вместе с содержимым
 * (мессенджер). Нижний отступ оболочки под такой страницей снимается: всё,
 * что осталось ниже, делает документ длиннее экрана, а на телефоне это видно
 * как поле ввода, выехавшее за нижний край под клавиатуру.
 */
const route = useRoute()
const fills = computed(() => route.meta.fills === true)

/**
 * Страница может попросить убрать нижнюю полосу разделов — так делает открытый
 * разговор на телефоне. Убирает её сама полоса (и только на узком экране), а
 * оболочке остаётся вернуть себе место, которое под неё было отведено.
 */
const { hideDock } = useShellChrome()
</script>

<template>
  <div class="shell" :class="{ 'shell--fills': fills, 'shell--no-dock': hideDock }">
    <header class="topbar">
      <div class="topbar__inner">
        <!-- Логотип уходит на телефоне: полоса сверху нужна там под названия
             страниц раздела, а не под знак, по которому никто не нажимает. -->
        <NuxtLink to="/" class="brand" aria-label="Bismar">
          <BrandMark :size="44" />
        </NuxtLink>

        <ModuleNav v-if="isAuthenticated" />

        <!-- The avatar alone: the name is on the profile page, and repeating it
             in every header only tells you what you already know. -->
        <NuxtLink
          v-if="isAuthenticated"
          to="/profile"
          class="account"
          title="Профиль"
          :aria-label="`Профиль: ${user?.name ?? ''}`"
        >
          <UserAvatar :name="user?.name" :src="user?.avatar_url" :size="36" />
        </NuxtLink>
      </div>
    </header>

    <div class="body" :class="{ 'body--railed': isAuthenticated, 'body--fills': fills }">
      <!--
        Показывать рельсу или нет — решение оболочки, а не самой рельсы: она не
        знает, есть ли на этом экране полоса внизу, а оболочка знает про обе.
      -->
      <div v-if="isAuthenticated" class="rail-slot">
        <SideRail />
      </div>

      <main class="main">
        <!-- Спрашиваем про уведомления на любом экране: это про приложение
             целиком, а не про страницу, на которую человек зашёл. -->
        <PushPrompt v-if="isAuthenticated" />

        <slot />
      </main>
    </div>

    <!-- Разделы на телефоне: плавающая полоса у нижнего края, где до них
         дотягивается большой палец. -->
    <MobileDock v-if="isAuthenticated" />

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

/*
 * Над страницей, меряющей себя по экрану, может встать полоса — предложение
 * включить уведомления. Она забирает свою высоту, а странице достаётся то, что
 * осталось.
 *
 * Колонкой, а не просто `height: 100%` у обоих: при нём страница брала целый
 * экран вдобавок к полосе, и поле ввода мессенджера оказывалось ниже нижней
 * кромки — а прокрутки документа здесь нет, и добраться до него было нельзя.
 */
.shell--fills .main {
  display: flex;
  min-height: 0;
  height: 100%;
  flex-direction: column;
}

.shell--fills .main > * {
  min-height: 0;
}

/* Сама страница — последней: полоса стоит перед ней и только когда она есть. */
.shell--fills .main > :last-child {
  flex: 1;
}

.topbar {
  position: sticky;
  top: 0;
  z-index: 10;
  height: var(--header-height);
  background: rgba(var(--color-bg-rgb), 0.85);
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

/* Обёртка ничего не рисует — она лишь занимает колонку и умеет исчезнуть. */
.rail-slot {
  min-width: 0;
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
    /* Снизу — место плавающей полосе разделов: без него последняя карточка
       страницы уходит под неё и до неё не дотянуться. */
    padding: 1rem 1rem var(--dock-space);
  }

  /*
   * Страница ровно в экран (мессенджер, структура): документ не прокручивается,
   * и отступ снизу ей не поставить — место доку отдаёт сама страница.
   */
  .body.body--fills {
    padding-bottom: 0;
  }

  .shell--fills .main {
    padding-bottom: var(--dock-space);
  }

  /* Полоса ушла — место под неё держать не за чем: поле ввода становится на
     нижнюю кромку экрана. Граница та же, на которой полоса и прячется. */
  @media (max-width: 47.9rem) {
    .shell--fills.shell--no-dock .main {
      padding-bottom: 0;
    }
  }

  /* Рельсы на телефоне нет: разделы выбирают в полосе у нижнего края, и второй
     ряд тех же значков сверху — просто шум. */
  .rail-slot {
    display: none;
  }

  /* Логотип и аватар сверху не нужны: раздел выбирают внизу, а лицо человека
     уже стоит в полосе — второй раз оно ничего не сообщает. */
  .brand,
  .account {
    display: none;
  }

  /*
   * Высота — по содержимому. Без логотипа и аватара прежние 4,25 rem оставляли
   * над вкладками пустую плиту, а на страницах без вкладок — просто полосу
   * ниоткуда. Страницам «ровно в экран» это не мешает: там высоту делит flex,
   * а не вычитание из «--header-height».
   */
  .topbar {
    height: auto;
  }

  .topbar__inner {
    height: auto;
    padding: 0.5rem 1rem;
  }

  /*
   * Пустой полосе неоткуда взяться высоте: страница начинается сразу.
   *
   * `:empty` этого не ловил, и полоса всё это время занимала сантиметр экрана
   * ни на чём: логотип с аватаром на телефоне спрятаны, но из разметки никуда
   * не делись, и пустой она для браузера не была. Смотрим на то, ради чего она
   * на телефоне и существует, — на названия разделов.
   */
  .topbar__inner:not(:has(.module-nav)) {
    padding: 0;
  }
}
</style>