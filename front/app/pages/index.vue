<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
useHead({ title: 'Панель' })

const { user } = useAuth()
</script>

<template>
  <section>
    <!-- By given name: a greeting that opens with a surname reads as a summons. -->
    <h1>Здравствуйте, {{ user?.first_name }}</h1>
    <p class="muted">
      Вы вошли как {{ user?.email }}. Здесь появится рабочая панель CRM.
    </p>
  </section>
</template>

<style scoped>
h1 {
  margin: 0 0 0.5rem;
  font-size: 1.5rem;
}

.muted {
  margin: 0;
  color: var(--color-text-muted);
}
</style>