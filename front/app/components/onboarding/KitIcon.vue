<script setup lang="ts">
import type { KitIcon } from '~/types/onboarding'
const props = defineProps<{ icon: KitIcon }>()
const paths: Record<KitIcon, string> = {
  access: 'm5 12 4 4L19 6M21 12a9 9 0 1 1-6-8.5',
  shirt: 'm8 3-4 2-3 5 4 2 1-3v12h12V9l1 3 4-2-3-5-4-2a4 4 0 0 1-8 0Z',
  barcode: 'M3 4v16M6 4v16M10 4v16M12 4v16M16 4v16M19 4v16M21 4v16',
  badge: 'M5 4h14v17H5ZM9 4V2h6v4H9ZM9 11h6M8 16h8',
  key: 'M14 9a5 5 0 1 1-5-5 5 5 0 0 1 5 5ZM13 13l8 8M16 16l3-3M18 18l3-3',
  book: 'M12 5v15M12 5C8 2 5 2 2 3v16c4-1 7-1 10 1 3-2 6-2 10-1V3c-3-1-6-1-10 2Z',
  tool: 'm14 6 4-4a6 6 0 0 1-7 8L3 18a2 2 0 0 0 3 3l8-8a6 6 0 0 0 8-7l-4 4Z',
  device: 'M3 3h18v13H3ZM8 21h8M12 16v5M7 12h10',
  bag: 'M4 7h16v14H4ZM8 7V5a4 4 0 0 1 8 0v2M8 11v2M16 11v2',
  star: 'm12 2 3 6 7 1-5 5 1 8-6-4-6 4 1-8-5-5 7-1Z',
}
</script>
<template>
  <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path :d="paths[props.icon] ?? paths.badge" /></svg>
</template>
