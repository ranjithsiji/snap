<script setup>
import { ref, watch } from 'vue'
import { CdxMultiselectLookup } from '@wikimedia/codex'
import { api } from '@/api'

/**
 * Several Commons usernames, entered as chips with autocomplete.
 *
 * The same reasoning as CommonsLookup, for fields taking more than one
 * name: a mistyped username is not rejected, it simply matches nobody —
 * so an organizer's uploads are never disqualified and nothing ever says
 * why. Suggesting real accounts is what prevents that.
 *
 * Built on CdxMultiselectLookup rather than CdxChipInput: the plain chip
 * input renders no menu at all, so a lookup bolted onto it queries
 * Commons and then has nowhere to show the answer.
 */
const props = defineProps({
  /** Plain usernames; chips are an implementation detail of the field. */
  modelValue: { type: Array, default: () => [] },
  placeholder: { type: String, default: 'Add a username…' },
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])

const toChips = (names) => names.map((name) => ({ value: name, label: name }))

const chips = ref(toChips(props.modelValue))
const selected = ref([...props.modelValue])
const inputValue = ref('')
const menuItems = ref([])

let debounceTimer = null
// Rises with every request, so a slow earlier response cannot replace
// the menu for what has since been typed.
let latestRequest = 0

// Follows the parent only when the two genuinely differ — on load, and
// after a save returns the stored list. Echoing back what was just typed
// would undo the edit in progress.
watch(
  () => props.modelValue,
  (value) => {
    if (value.join(' ') !== selected.value.join(' ')) {
      chips.value = toChips(value)
      selected.value = [...value]
    }
  },
)

function onChips(value) {
  chips.value = value

  const names = value.map((chip) => chip.value)
  selected.value = names
  emit('update:modelValue', names)
}

function onInput(term) {
  const query = String(term ?? '').trim()

  clearTimeout(debounceTimer)

  if (query.length < 2) {
    menuItems.value = []

    return
  }

  debounceTimer = setTimeout(async () => {
    const requestId = ++latestRequest

    try {
      const data = await api.get(`/commons/users?q=${encodeURIComponent(query)}`)

      if (requestId !== latestRequest) {
        return
      }

      // Names already chosen are dropped rather than offered again and
      // silently ignored on selection.
      menuItems.value = data.users
        .filter((name) => !selected.value.includes(name))
        .map((name) => ({ label: name, value: name }))
    } catch {
      if (requestId === latestRequest) {
        menuItems.value = []
      }
    }
  }, 250)
}
</script>

<template>
  <CdxMultiselectLookup
    v-model:input-chips="chips"
    v-model:selected="selected"
    v-model:input-value="inputValue"
    :menu-items="menuItems"
    :disabled="disabled"
    :placeholder="placeholder"
    @update:input-chips="onChips"
    @update:input-value="onInput"
  >
    <template #no-results>Nothing on Commons matches.</template>
  </CdxMultiselectLookup>
</template>
