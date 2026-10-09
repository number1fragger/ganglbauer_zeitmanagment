<script setup lang="ts">
/**
 * Einheitliche Strich-Icons (24er Raster, 2px Linie) statt Emojis.
 * Farbe kommt von currentColor, Groesse ueber die size-Property.
 */
import { icons, type IconName } from './icons'

withDefaults(defineProps<{ name: IconName; size?: number; label?: string }>(), {
  size: 18,
  label: undefined,
})
</script>

<template>
  <svg
    class="icon"
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
    :role="label ? 'img' : undefined"
    :aria-label="label"
    :aria-hidden="label ? undefined : 'true'"
    focusable="false"
    v-html="icons[name]"
  />
</template>

<style scoped>
.icon {
  flex: none;
  vertical-align: middle;
}
</style>
