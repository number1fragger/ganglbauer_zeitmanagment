<script setup lang="ts">
import { computed } from 'vue'
import type { ThemePreference } from '@/api/types'
import { useThemeStore } from '@/stores/theme'
import AppIcon from './AppIcon.vue'

defineProps<{ compact?: boolean }>()

const theme = useThemeStore()

const options: { value: ThemePreference; label: string; icon: 'sun' | 'moon' | 'monitor' }[] = [
  { value: 'light', label: 'Hell', icon: 'sun' },
  { value: 'dark', label: 'Dunkel', icon: 'moon' },
  { value: 'system', label: 'System', icon: 'monitor' },
]

const current = computed(() => options.find((o) => o.value === theme.preference) ?? options[2]!)
const next = computed(() => options[(options.indexOf(current.value) + 1) % options.length]!)
</script>

<template>
  <!-- Kompakt: ein Knopf, der reihum wechselt (Sonne/Mond/Bildschirm) -->
  <button
    v-if="compact"
    class="theme-button"
    :aria-label="`Farbschema: ${current.label}. Wechseln zu ${next.label}`"
    :data-tip="`Farbschema: ${current.label}`"
    @click="theme.set(next.value)"
  >
    <AppIcon :name="theme.effective === 'dark' ? 'moon' : 'sun'" :size="18" />
  </button>

  <div v-else class="theme-switch" role="radiogroup" aria-label="Farbschema">
    <button
      v-for="option in options"
      :key="option.value"
      role="radio"
      :aria-checked="theme.preference === option.value"
      :aria-label="option.label"
      :data-tip="option.label"
      @click="theme.set(option.value)"
    >
      <AppIcon :name="option.icon" :size="15" />
    </button>
  </div>
</template>

<style scoped>
.theme-button {
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  border: 0;
  border-radius: var(--radius);
  background: none;
  color: var(--muted);
}

.theme-button:hover {
  background: var(--surface-muted);
  color: var(--text);
}

.theme-switch {
  display: flex;
  padding: 3px;
  border-radius: var(--radius);
  background: var(--surface-muted);
}

.theme-switch button {
  display: grid;
  flex: 1;
  place-items: center;
  height: 28px;
  border: 0;
  border-radius: var(--radius-sm);
  background: none;
  color: var(--muted);
}

.theme-switch button:hover {
  color: var(--text);
}

.theme-switch button[aria-checked='true'] {
  background: var(--surface);
  color: var(--primary);
  box-shadow: var(--shadow-sm);
}
</style>
