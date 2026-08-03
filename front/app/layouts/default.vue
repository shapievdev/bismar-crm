<script setup lang="ts">
const { user, isAuthenticated } = useAuth()

</script>

<template>
  <div class="shell">
    <header class="topbar">
      <div class="topbar__inner">
        <NuxtLink to="/" class="brand" aria-label="Bismar">
          <BrandMark :size="44" />
        </NuxtLink>

        <ModuleNav v-if="isAuthenticated" />

        <NuxtLink v-if="isAuthenticated" to="/settings/profile" class="account" title="Профиль">
          <span class="account__name">{{ user?.name }}</span>
          <UserAvatar :name="user?.name" :src="user?.avatar_url" :size="36" />
        </NuxtLink>
      </div>
    </header>

    <div class="body" :class="{ 'body--railed': isAuthenticated }">
      <SideRail v-if="isAuthenticated" />

      <main class="main">
        <slot />
      </main>
    </div>
  </div>
</template>

<style scoped>
.shell {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
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
  gap: 0.6rem;
  margin-left: auto;
  color: inherit;
  text-decoration: none;
}

.account__name {
  color: var(--color-text-muted);
  font-size: 0.88rem;
}

@media (max-width: 56rem) {
  .account__name {
    display: none;
  }

  .topbar__inner {
    /* The brand and account stay put; only the destinations scroll. */
    gap: 0.75rem;
  }

  .topbar__inner {
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
}
</style>