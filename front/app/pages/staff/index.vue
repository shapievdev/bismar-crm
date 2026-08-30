<script setup lang="ts">
import type { User } from '~/types/auth'
import { formatDate } from '~/utils/numbers'
import { maskPhone } from '~/utils/phone'

definePageMeta({ middleware: 'auth', permission: 'users.view' })
useHead({ title: 'Сотрудники' })

const { fetchUsers } = useAdminApi()
const { can } = useAuth()

const canManage = computed(() => can('users.manage'))

/**
 * Список только читают: завести сотрудника и править его отправляют на
 * отдельные экраны. Формы, раскрывавшиеся над таблицей, уводили внимание с
 * того, ради чего сюда приходят, — с самого списка.
 */
const { data, pending, error } = await useAsyncData('staff.list', () => fetchUsers())

const users = computed(() => data.value?.data ?? [])

/** «Уволен 30 августа 2026» — метка в строке списка. */
function dismissalLabel(user: User): string {
  return user.dismissed_at ? `Уволен ${formatDate(user.dismissed_at)}` : ''
}

/**
 * Почта и телефон одной строкой: разными они заняли бы у списка ещё этаж, а
 * читают их вместе — это способы дозвониться до человека.
 */
function contactsLabel(user: User): string {
  return [user.email, user.phone ? maskPhone(user.phone) : null]
    .filter(Boolean)
    .join(' · ')
}

function permissionsLabel(user: User): string {
  const count = user.own_permissions.length

  return count === 0
    ? 'без прав'
    : `${count} ${pluralise(count, 'право', 'права', 'прав')}`
}
</script>

<template>
  <section>
    <header class="page-header">
      <div class="page-header__row">
        <div>
          <h1>Сотрудники</h1>
          <p class="muted">
            {{ canManage
              ? 'Откройте карточку, чтобы посмотреть человека, поправить его данные или отметить доступ.'
              : 'Только просмотр: у вас нет права управлять сотрудниками.' }}
          </p>
        </div>

        <NuxtLink v-if="canManage" to="/staff/new" class="button-primary">
          Новый сотрудник
        </NuxtLink>
      </div>
    </header>

    <p v-if="pending" class="muted">
      Загрузка…
    </p>

    <p v-else-if="error" class="auth-alert" role="alert">
      Не удалось загрузить сотрудников.
    </p>

    <div v-else class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Сотрудник</th>
            <th>Доступ</th>
          </tr>
        </thead>

        <tbody>
          <tr v-for="row in users" :key="row.id">
            <td :class="{ 'user--dismissed': row.dismissed_at }">
              <NuxtLink :to="`/staff/${row.id}`" class="user__name">
                {{ row.name }}
              </NuxtLink>
              <div v-if="row.job_title" class="muted">
                {{ row.job_title }}
              </div>
              <div class="muted">
                {{ contactsLabel(row) }}
              </div>
            </td>

            <td>
              <!-- Уволенный не спорит об уровне доступа: пока он не в строю,
                   ни то, ни другое ничего ему не открывает. -->
              <span v-if="row.dismissed_at" class="badge badge--warning">
                {{ dismissalLabel(row) }}
              </span>

              <template v-else>
                <!-- Lime marks the rare standing, not the ordinary one: it is
                     what tells you at a glance who runs the place. -->
                <span class="badge" :class="{ 'badge--highlight': row.level !== 'user' }">
                  {{ row.level_label }}
                </span>
                <span v-if="row.level === 'user'" class="muted permissions-count">
                  {{ permissionsLabel(row) }}
                </span>
              </template>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
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

.page-header h1 {
  margin: 0 0 0.25rem;
  font-size: 1.5rem;
}

.muted {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.table-wrap {
  overflow-x: auto;
  background: var(--color-surface);
  border-radius: var(--radius);
}

table {
  width: 100%;
  border-collapse: collapse;
}

th,
td {
  padding: 0.85rem 1rem;
  text-align: left;
  /* Middle, so a one-line cell lines up with the two-line name beside it. */
  vertical-align: middle;
  border-bottom: 1px solid var(--color-border);
}

th {
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-text-muted);
}

tbody tr:last-child td {
  border-bottom: none;
}

/* Имя — вход в карточку: подчёркивание появляется под указателем, чтобы
   таблица не рябила линиями в спокойном состоянии. */
.user__name {
  display: inline-block;
  font-weight: 500;
  color: inherit;
  text-decoration: none;
}

.user__name:hover {
  text-decoration: underline;
}

.permissions-count {
  margin-left: 0.5rem;
}

/*
 * Уволенный из списка не пропадает — он здесь ради возвращения в строй, — но и
 * не спорит за внимание с работающими: приглушено имя, а метка остаётся в
 * полную силу.
 */
.user--dismissed .user__name {
  color: var(--color-text-muted);
}

@media (max-width: 48rem) {
  .page-header__row {
    flex-direction: column;
  }

  th,
  td {
    padding: 0.6rem 0.7rem;
  }
}
</style>
