<script setup lang="ts">
import { computed } from 'vue'
import { drag } from '@/composables/useJobDrag'
import { priorityColor } from '@/utils/domain'

const time = (date: Date) => date.toLocaleTimeString('de-AT', { hour: '2-digit', minute: '2-digit' })
const label = computed(() =>
  drag.target ? `${time(drag.target.startsAt)} – ${time(drag.target.endsAt)}` : 'Hier nicht möglich',
)

const style = computed(() => {
  const target = drag.target
  const landing = drag.dropping && target !== null
  const x = landing ? target.left : drag.x
  const y = landing ? target.top : drag.y
  return {
    transform: `translate(${x}px, ${y}px) ${landing ? '' : 'scale(1.03) rotate(1.5deg)'}`,
    width: `${landing ? target.width : drag.width}px`,
    height: `${drag.height}px`,
    '--accent': drag.job ? priorityColor(drag.job.priority) : 'var(--primary)',
  }
})
</script>

<template>
  <Teleport to="body">
    <div v-if="drag.active && drag.job" class="ghost" :class="{ 'ghost--landing': drag.dropping }" :style="style">
      <strong>{{ drag.job.title }}</strong>
      <span class="ghost__time" :class="{ 'ghost__time--none': !drag.target }">{{ label }}</span>
    </div>
  </Teleport>
</template>

<style scoped>
.ghost {
  position: fixed;
  z-index: 1000;
  top: 0;
  left: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 10px 12px 10px 14px;
  overflow: hidden;
  border-left: 4px solid var(--accent);
  border-radius: var(--radius);
  background: var(--surface);
  box-shadow: 0 14px 30px rgb(15 23 42 / 25%);
  pointer-events: none;
  animation: lift 0.15s ease-out;
}

.ghost--landing {
  box-shadow: 0 2px 6px rgb(15 23 42 / 15%);
  transition: transform 0.18s cubic-bezier(0.2, 0.8, 0.2, 1), width 0.18s, box-shadow 0.18s;
}

.ghost strong {
  overflow: hidden;
  font-size: 12px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ghost__time {
  margin-top: auto;
  color: var(--primary);
  font-size: 11px;
  font-weight: 700;
}

.ghost__time--none {
  color: var(--danger);
}

@keyframes lift {
  from {
    opacity: 0.7;
    transform: scale(1);
  }
}
</style>
