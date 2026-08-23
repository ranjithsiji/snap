<script setup>
import { ref, watch } from 'vue'
import { CdxLookup } from '@wikimedia/codex'
import { api } from '@/api'

/**
 * Autocomplete against Commons, for usernames or category names.
 *
 * Both are things a coordinator would otherwise type from memory, and
 * both fail quietly when mistyped: an unknown username is invited to
 * nothing, and a category that is merely close returns an import of zero
 * files. Categories therefore carry their file count in the menu, which
 * is what makes a wrong-but-plausible choice visible before it is made.
 */
const props = defineProps({
  modelValue: { type: String, default: '' },
  /** 'users' or 'categories'. */
  kind: { type: String, default: 'users' },
  placeholder: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'select'])

const selection = ref(props.modelValue || null)
const menuItems = ref([])
const pending = ref(false)

let debounceTimer = null
// Rises with every request so a slow earlier response cannot overwrite
// the menu for what has since been typed.
let latestRequest = 0

watch(
  () => props.modelValue,
  (value) => {
    selection.value = value || null
  },
)

async function fetchSuggestions(term) {
  const query = term.trim()

  if (query.length < 2) {
    menuItems.value = []

    return
  }

  const requestId = ++latestRequest
  pending.value = true

  try {
    const path = props.kind === 'categories' ? 'categories' : 'users'
    const data = await api.get(`/commons/${path}?q=${encodeURIComponent(query)}`)

    if (requestId !== latestRequest) {
      return
    }

    menuItems.value =
      props.kind === 'categories'
        ? data.categories.map((category) => ({
            label: category.name,
            value: category.name,
            // Surfaced because an empty category is the common mistake,
            // and it looks identical to the right one otherwise.
            description:
              category.files === 0
                ? 'No files'
                : `${category.files.toLocaleString()} file${category.files === 1 ? '' : 's'}`,
          }))
        : data.users.map((name) => ({ label: name, value: name }))
  } catch {
    // A failed lookup must not block typing: the field still accepts
    // whatever was entered by hand.
    if (requestId === latestRequest) {
      menuItems.value = []
    }
  } finally {
    if (requestId === latestRequest) {
      pending.value = false
    }
  }
}

function onInput(value) {
  emit('update:modelValue', value ?? '')

  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => fetchSuggestions(value ?? ''), 250)
}

function onSelect(value) {
  if (value === null) {
    return
  }

  emit('update:modelValue', value)
  emit('select', value)
}
</script>

<template>
  <CdxLookup
    v-model:selected="selection"
    :menu-items="menuItems"
    :disabled="disabled"
    :placeholder="placeholder"
    @input="onInput"
    @update:selected="onSelect"
  >
    <template #no-results>
      {{ pending ? 'Searching Commons…' : 'Nothing on Commons matches.' }}
    </template>
  </CdxLookup>
</template>
