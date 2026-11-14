<script setup lang="ts">
import type { JourneyStage } from '~/types/onboarding'
const props = withDefaults(defineProps<{ stages: JourneyStage[], selectedId: number | null, title?: string }>(), { title: 'Твой путь в команду' })
const emit = defineEmits<{ select: [id: number] }>()
const pattern = [300, 370, 410, 370, 300, 230, 190, 230]
const target = computed(() => props.stages.find(s => ['available', 'in_progress', 'quiz_failed'].includes(s.status))?.id)
function itemSegments(stage: JourneyStage) {
  const count = stage.items.length
  const step = 360 / Math.max(count, 1)
  const gap = Math.min(10, step * .24)
  const point = (angle: number) => {
    const radians = angle * Math.PI / 180
    return `${60 + 54 * Math.cos(radians)} ${54 + 48 * Math.sin(radians)}`
  }
  return stage.items.map((item, index) => {
    const start = -90 + index * step + gap / 2
    const end = -90 + (index + 1) * step - gap / 2
    return { item, path: `M ${point(start)} A 54 48 0 ${end - start > 180 ? 1 : 0} 1 ${point(end)}` }
  })
}
const nodes = computed(() => props.stages.map((stage, i) => ({ stage, segments: itemSegments(stage), x: pattern[i % pattern.length]!, y: 96 + i * 208 })))
const height = computed(() => Math.max(220, props.stages.length * 208 + 70))
function date(stage: JourneyStage) {
  return stage.opens_at ? new Date(stage.opens_at).toLocaleDateString('ru-RU', {day:'numeric',month:'short'}) : 'По очереди'
}
</script>

<template>
  <section class="adventure" aria-label="Карта адаптации">
    <header class="adventure__head">
      <div><span class="eyebrow">Шаг за шагом</span><h2>{{ title }}</h2></div>
      <span class="adventure__count">{{ stages.filter(s => s.status === 'completed').length }} / {{ stages.length }}</span>
    </header>
    <div class="trail" :style="{height: `${height}px`}">
      <div v-if="target !== nodes[0]?.stage.id" class="trail__start" aria-hidden="true">Здесь начинается история</div>
      <ol class="trail__nodes">
        <li v-for="(node, index) in nodes" :key="node.stage.id" class="stop" :class="[`stop--${node.stage.status}`, {'stop--selected':selectedId === node.stage.id, 'stop--target':target === node.stage.id}]" :style="{left:`${node.x / 6}%`, top:`${node.y}px`}">
          <span v-if="target === node.stage.id" class="stop__callout">ТВОЙ СЛЕДУЮЩИЙ ШАГ</span>
          <button class="stop__button" type="button" :aria-label="`${node.stage.title}. ${node.stage.status_label}. Выполнено ${node.stage.items.filter(item => item.done).length} из ${node.stage.items.length}${node.stage.status === 'locked' ? `. Открытие: ${date(node.stage)}` : ''}`" :aria-current="selectedId === node.stage.id ? 'step' : undefined" @click="emit('select',node.stage.id)">
            <svg v-if="node.segments.length" class="stop__orbit" viewBox="0 0 120 112" fill="none" aria-hidden="true" :style="{ '--segment-width': Math.min(6, 55 / node.segments.length) }">
              <template v-for="segment in node.segments" :key="segment.item.key">
                <ellipse v-if="node.segments.length === 1" cx="60" cy="54" rx="54" ry="48" class="stop__segment" :class="{ 'stop__segment--done': segment.item.done }" />
                <path v-else :d="segment.path" class="stop__segment" :class="{ 'stop__segment--done': segment.item.done }" />
              </template>
            </svg>
            <span class="stop__disc">
            <svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path v-if="node.stage.status === 'completed'" d="m8 16 6 6L25 10" />
              <template v-else-if="node.stage.status === 'locked'"><rect x="8" y="14" width="16" height="13" rx="4"/><path d="M11 14V9a5 5 0 0 1 10 0v5m-5 6v2"/></template>
              <path v-else-if="node.stage.status === 'quiz_failed'" d="M25 12a10 10 0 1 0 0 10m0-16v7h-7"/>
              <template v-else-if="node.stage.is_final_exam"><path d="M10 5h12v9a6 6 0 0 1-12 0V5Zm6 15v7m-5 0h10M10 8H5v4a5 5 0 0 0 5 5m12-9h5v4a5 5 0 0 1-5 5"/></template>
              <path v-else d="m16 5 3.4 7 7.6 1.1-5.5 5.4 1.3 7.5-6.8-3.6L9.2 26l1.3-7.5L5 13.1l7.6-1.1L16 5Z" fill="currentColor" stroke-width="1.5" />
            </svg>
            </span>
          </button>
          <div class="stop__caption">
          <span class="stop__day">{{ node.stage.is_final_exam ? 'ФИНАЛЬНОЕ ИСПЫТАНИЕ' : node.stage.day_offset ? `ДЕНЬ ${node.stage.day_offset}` : `ШАГ ${index + 1}` }}</span>
          <strong class="stop__title">{{ node.stage.title }}</strong>
          <span class="stop__status">{{ node.stage.status_label }}<template v-if="node.stage.status === 'in_progress'"> · {{ node.stage.progress }}%</template><template v-if="node.stage.status === 'locked'"> · {{ date(node.stage) }}</template></span>
          </div>
        </li>
      </ol>
      <div class="trail__finish" aria-hidden="true"><span>✦</span> Всё получится. Шаг за шагом.</div>
    </div>
  </section>
</template>

<style scoped>
.adventure { border: 2px solid var(--color-border); border-radius: 30px; background: var(--color-surface); overflow: hidden; }
.adventure__head { padding: 24px 28px; display:flex; justify-content:space-between; align-items:center; gap:16px; border-bottom:2px solid var(--color-border); }
.eyebrow { font-size:11px; font-weight:700; letter-spacing:.12em; text-transform:uppercase; color:var(--color-text-muted); }
h2 { font-size:20px; margin:6px 0 0; font-weight:700; }
.adventure__count { padding:9px 14px; background:var(--control-surface); border-radius:14px; font-weight:700; white-space:nowrap; }
.trail { position:relative; }
.trail__nodes { margin:0; padding:0; list-style:none; }
.trail__start { position:absolute; top:22px; width:100%; text-align:center; font-size:11px; color:var(--color-text-muted); }
.stop { position:absolute; transform:translate(-50%,-40px); display:flex; flex-direction:column; align-items:center; width:194px; text-align:center; }
.stop__button { --cell:120px; --face:var(--color-surface-sunken); --edge:var(--color-border-strong); position:relative; display:block; width:var(--cell); height:calc(var(--cell) * 112 / 120); padding:0; border:0; background:transparent; color:var(--color-text-muted); cursor:pointer; flex:none; -webkit-tap-highlight-color:transparent; }
.stop__disc { position:absolute; left:50%; top:calc(var(--cell) * 14 / 120); transform:translateX(-50%); width:calc(var(--cell) * 88 / 120); height:calc(var(--cell) * 72 / 120); display:grid; place-items:center; border-radius:50%; background:var(--face); box-shadow:0 10px 0 var(--edge); transition:transform .15s,box-shadow .15s; }
.stop__disc::before { content:''; position:absolute; inset:7px; border-radius:50%; background:linear-gradient(135deg,transparent 22%,rgb(255 255 255 / .18) 22% 38%,transparent 38% 49%,rgb(255 255 255 / .12) 49% 64%,transparent 64%); pointer-events:none; }
.stop__disc>svg { width:34px;height:34px;position:relative; }
.stop__button:hover .stop__disc { transform:translate(-50%,-2px); box-shadow:0 12px 0 var(--edge); }
.stop__button:active .stop__disc { transform:translate(-50%,7px); box-shadow:0 3px 0 var(--edge); }
.stop__button:focus-visible { outline:3px solid var(--color-text);outline-offset:4px;border-radius:50%; }
.stop--completed .stop__button { --face:var(--color-success);--edge:color-mix(in srgb,var(--color-success),#000 24%);color:var(--color-surface-raised); }
.stop--available .stop__button,.stop--in_progress .stop__button,.stop--quiz_failed .stop__button { --face:var(--color-highlight);--edge:color-mix(in srgb,var(--color-highlight-strong),#000 18%);color:var(--color-highlight-text); }
.stop--locked .stop__disc::before { display:none; }
.stop__orbit { position:absolute;inset:0;width:100%;height:100%;overflow:visible;pointer-events:none; }
.stop__segment { stroke:var(--color-border-strong);stroke-width:var(--segment-width,6);stroke-linecap:round; }
.stop__segment--done { stroke:var(--color-success); }
.stop--selected .stop__caption { outline: 1px solid var(--color-border-strong); }
.stop__caption { display:flex; flex-direction:column; align-items:center; margin-top:3px; padding:5px 8px; border-radius:12px; background:var(--color-surface); max-width:194px; }
.stop__day { font-size:9px; font-weight:800; letter-spacing:.09em; color:var(--color-text-muted); }
.stop__title { margin-top:4px; font-size:14px; line-height:1.25; max-width:180px; }
.stop__status { font-size:10px; line-height:1.3; margin-top:5px; color:var(--color-text-muted); max-width:180px; }
.stop__callout { position:absolute; bottom:calc(100% + 15px); white-space:nowrap; padding:8px 11px; border:2px solid var(--color-highlight-strong); border-radius:11px; background:var(--color-highlight); color:var(--color-highlight-text); font-size:9px; font-weight:800; letter-spacing:.04em; box-shadow:0 3px 0 var(--color-highlight-strong); z-index:2; }
.stop__callout::after { content:''; position:absolute; left:calc(50% - 5px); bottom:-7px; width:10px; height:10px; transform:rotate(45deg); background:var(--color-highlight); border-right:2px solid var(--color-highlight-strong); border-bottom:2px solid var(--color-highlight-strong); }
.trail__finish { position:absolute; bottom:19px; width:100%; text-align:center; color:var(--color-text-muted); font-size:11px; }
.trail__finish span { color:var(--color-success); margin-right:7px; }
@media(max-width:600px) { .adventure__head { padding:20px; } h2 { font-size:18px; } .stop { width:148px; } .stop__caption { max-width:148px; } .stop__title { font-size:13px; max-width:148px; } .stop__status { font-size:11px; max-width:148px; } .stop__button { --cell:108px; } }
@media(prefers-reduced-motion:reduce) { .stop__disc { transition:none; } }
</style>
