<script setup lang="ts">
import { DONE_COLOR, priorities } from '@/utils/domain'

defineProps<{ withDone?: boolean }>()
</script>

<template>
  <section class="legend">
    <h3 class="legend__title">Priorität</h3>
    <ul>
      <li v-for="p in priorities" :key="p.value">
        <span class="legend__swatch" :style="{ background: p.color }" />{{ p.label.toLowerCase() }}
      </li>
      <li v-if="withDone"><span class="legend__swatch" :style="{ background: DONE_COLOR }" />erledigt</li>
    </ul>
  </section>
</template>

<style scoped>
.legend__title {
  font-size: 12px;
  font-weight: 700;
}

.legend ul {
  display: grid;
  gap: 10px;
  margin: 12px 0 0;
  padding: 0;
  list-style: none;
}

.legend li {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--muted);
  font-size: 11px;
}

.legend__swatch {
  width: 10px;
  height: 10px;
  border-radius: 3px;
}
</style>
