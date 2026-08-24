<script setup>
import { ref, watch } from 'vue'
import { CdxLookup } from '@wikimedia/codex'
import { api } from '@/api'

/**
 * Autocomplete against Commons, for usernames or category names.
 *
 * Both are things a coordinator would otherwise type from memory, and
 * both fail quietly when mistyped: an unknown username is invited to
 * nothing, and a category that is merely close returns an import of the
 * wrong files — or none. Categories therefore carry their file count in
 * the menu, which is what makes a wrong-but-plausible choice visible
 * before it is made.
 */
const props = defineProps({
  modelValue: { type: String, default: '' },
  /** 'users' or 'categories'. */
  kind: { type: String, default: 'users' },
  placeholder: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'select'])

/**
 * CdxLookup's `selected` is the value chosen from the menu, not the text
 * in the box. Binding it to modelValue and writing back on every
 * keystroke made the component reset the input to what had been typed a
 * moment earlier, so the field could not be typed into at all.
 *
 * It is therefore held separately: `selection` only ever changes when a
 * menu item is picked, while the typed text flows out through
 * update:modelValue.
 */
const selection = ref(null)
const inputValue = ref(props.modelValue ?? '')
const menuItems = ref([])
const pending = ref(false)

let debounceTimer = null
// Rises with every request so a slow earlier response cannot overwrite
// the menu for what has since been typed.
let latestRequest = 0

// Only follows the parent when it genuinely differs — resetting the form,
// or loading a round for editing. Echoing back what was just typed is
// what fought the cursor.
watch(
  () => props.modelValue,
  (value) => {
    if ((value ?? '') !== inputValue.value) {
      inputValue.value = value ?? ''
    }
  },
)

async function fetchSuggestions(term) {
  // Commons stores titles with underscores but the API returns spaces,
  // and a pasted URL fragment carries underscores. Searching on spaces
  // means a pasted "Images_from_Wiki_Loves_Earth" still matches.
  const query = term.trim().replace(/_/g, ' ')

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
  const text = value ?? ''

  // inputValue is not assigned here: v-model:input-value has already set
  // it, and writing it again during the same event is what makes the
  // caret jump.
  //
  // Typing is itself a valid answer — a category can be entered in full
  // without ever opening the menu — so the value is published as typed,
  // with underscores normalised to the spaces Commons reports.
  emit('update:modelValue', text.replace(/_/g, ' '))

  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => fetchSuggestions(text), 250)
}

function onSelect(value) {
  // Cleared selection (the menu closing without a pick) must not wipe
  // what has been typed.
  if (value === null || value === undefined) {
    return
  }

  inputValue.value = value
  emit('update:modelValue', value)
  emit('select', value)
}
</script>

<template>
  <!-- input-value is a v-model prop: bound one-way it would be reset on
       every keystroke and the field could not be typed into. -->
  <CdxLookup
    v-model:selected="selection"
    v-model:input-value="inputValue"
    :menu-items="menuItems"
    :disabled="disabled"
    :placeholder="placeholder"
    @update:input-value="onInput"
    @update:selected="onSelect"
  >
    <template #no-results>
      {{ pending ? 'Searching Commons…' : 'Nothing on Commons matches.' }}
    </template>
  </CdxLookup>
</template>
