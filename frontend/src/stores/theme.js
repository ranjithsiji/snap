import { defineStore } from 'pinia'
import { ref, watch } from 'vue'

/**
 * Light or dark, remembered across visits.
 *
 * Judging photographs is the whole point of the tool, and a dark
 * surround is the conventional choice for looking at images — but the
 * admin screens are dense text, where light is easier. So it is the
 * viewer's choice rather than ours, defaulting to whatever the operating
 * system already says.
 */
const STORAGE_KEY = 'snap-theme'

export const useTheme = defineStore('theme', () => {
  const stored = localStorage.getItem(STORAGE_KEY)
  const prefersDark =
    typeof window.matchMedia === 'function' &&
    window.matchMedia('(prefers-color-scheme: dark)').matches

  const mode = ref(stored ?? (prefersDark ? 'dark' : 'light'))

  function apply(value) {
    document.documentElement.setAttribute('data-theme', value)
  }

  function toggle() {
    mode.value = mode.value === 'dark' ? 'light' : 'dark'
  }

  watch(
    mode,
    (value) => {
      apply(value)
      localStorage.setItem(STORAGE_KEY, value)
    },
    { immediate: true },
  )

  return { mode, toggle }
})
