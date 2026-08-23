<script setup>
import { computed, ref } from 'vue'
import {
  CdxButton,
  CdxField,
  CdxInfoChip,
  CdxMessage,
  CdxSelect,
  CdxTextArea,
  CdxTextInput,
} from '@wikimedia/codex'
import { api } from '@/api'
import { useSession } from '@/stores/session'

/**
 * One disputed image: what each juror proposed, the arguments made about
 * it, and the panel's agreement with those arguments.
 *
 * Points are deliberately per-juror rather than free-form voting, so the
 * tally reflects how many people back an argument, not how loudly.
 */
const props = defineProps({
  meetingId: { type: String, required: true },
  image: { type: Object, required: true },
  readonly: { type: Boolean, default: false },
})

const emit = defineEmits(['updated'])

const session = useSession()

const open = ref(false)
const body = ref('')
const suggested = ref('')
const supports = ref(null)
const busy = ref(false)
const error = ref(null)

const myOpinion = computed(() =>
  props.image.opinions.find((o) => o.author === session.user?.username),
)

// Whose proposal an opinion can side with.
const supportOptions = computed(() => [
  { label: 'No one in particular', value: null },
  ...props.image.proposals.map((p) => ({
    label: `${p.juror} (#${p.position})`,
    value: p.juror,
  })),
])

function startEditing() {
  if (myOpinion.value) {
    body.value = myOpinion.value.body
    suggested.value = myOpinion.value.suggestedPosition
      ? String(myOpinion.value.suggestedPosition)
      : ''
    supports.value = myOpinion.value.supports
  }

  open.value = true
}

async function submit() {
  if (!body.value.trim()) return

  busy.value = true
  error.value = null

  try {
    await api.post(`/meetings/${props.meetingId}/images/${props.image.imageId}/opinions`, {
      body: body.value,
      suggestedPosition: suggested.value ? Number(suggested.value) : 0,
      supports: supports.value,
    })

    // Reload the matrix so the new opinion and its tally appear.
    const refreshed = await api.get(`/meetings/${props.meetingId}/proposals`)
    emit('updated', refreshed.images)

    open.value = false
    body.value = ''
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

async function endorse(opinion, value) {
  if (props.readonly) return

  busy.value = true

  try {
    const data = await api.post(`/meetings/opinions/${opinion.id}/endorse`, { value })
    emit('updated', data.images)
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

/** Whether the signed-in juror has already taken a side on an argument. */
function myVote(opinion) {
  return opinion.voters?.[session.user?.username] ?? 0
}
</script>

<template>
  <div class="card">
    <div class="meeting-row">
      <img :src="image.thumbUrl" :alt="image.title" class="meeting-thumb" />

      <div class="meeting-body">
        <div class="row wrap">
          <strong class="meeting-rank">#{{ image.position }}</strong>
          <span class="muted">{{ image.title }}</span>
          <CdxInfoChip status="warning">{{ image.spread }} places apart</CdxInfoChip>
        </div>

        <div class="proposal-row">
          <span v-for="proposal in image.proposals" :key="proposal.juror" class="proposal-chip">
            {{ proposal.juror }}: <strong>#{{ proposal.position }}</strong>
          </span>
        </div>
      </div>
    </div>

    <CdxMessage v-if="error" type="error" inline style="margin-top: 0.75rem">
      {{ error }}
    </CdxMessage>

    <!-- Arguments, best supported first -->
    <div v-if="image.opinions.length > 0" class="opinion-list">
      <div v-for="opinion in image.opinions" :key="opinion.id" class="opinion">
        <div class="opinion-points">
          <button
            type="button"
            class="point-btn"
            :class="{ on: myVote(opinion) > 0 }"
            :disabled="readonly || busy"
            aria-label="Agree"
            @click="endorse(opinion, 1)"
          >
            ▲
          </button>

          <span class="point-score" :class="{ positive: opinion.score > 0, negative: opinion.score < 0 }">
            {{ opinion.score > 0 ? '+' : '' }}{{ opinion.score }}
          </span>

          <button
            type="button"
            class="point-btn"
            :class="{ on: myVote(opinion) < 0 }"
            :disabled="readonly || busy"
            aria-label="Disagree"
            @click="endorse(opinion, -1)"
          >
            ▼
          </button>
        </div>

        <div class="opinion-body">
          <div class="row wrap" style="gap: 0.375rem">
            <strong>{{ opinion.author }}</strong>
            <CdxInfoChip v-if="opinion.suggestedPosition">
              argues for #{{ opinion.suggestedPosition }}
            </CdxInfoChip>
            <CdxInfoChip v-if="opinion.supports">backs {{ opinion.supports }}</CdxInfoChip>
          </div>

          <p style="margin: 0.25rem 0 0; white-space: pre-wrap">{{ opinion.body }}</p>

          <p class="muted" style="margin: 0.25rem 0 0; font-size: 0.75rem">
            {{ opinion.agree }} agree · {{ opinion.disagree }} disagree
          </p>
        </div>
      </div>
    </div>

    <p v-else class="muted" style="margin: 0.75rem 0 0">
      No one has given an opinion on this disagreement yet.
    </p>

    <div v-if="!readonly" class="row row-end" style="margin-top: 0.75rem">
      <CdxButton @click="startEditing">
        {{ myOpinion ? 'Edit my opinion' : 'Add my opinion' }}
      </CdxButton>
    </div>

    <!-- Opinion editor -->
    <div v-if="open" class="opinion-form">
      <CdxField>
        <template #label>Your opinion</template>
        <template #description>
          Explain where you think this image belongs and why. This does not change your own
          ranking.
        </template>
        <CdxTextArea v-model="body" rows="3" />
      </CdxField>

      <div class="grid-2" style="gap: 1rem">
        <CdxField>
          <template #label>Position you argue for</template>
          <template #description>Optional.</template>
          <CdxTextInput v-model="suggested" input-type="number" min="1" />
        </CdxField>

        <CdxField>
          <template #label>Whose proposal you back</template>
          <CdxSelect v-model:selected="supports" :menu-items="supportOptions" />
        </CdxField>
      </div>

      <div class="row row-end">
        <CdxButton @click="open = false">Cancel</CdxButton>
        <CdxButton
          action="progressive"
          weight="primary"
          :disabled="busy || !body.trim()"
          @click="submit"
        >
          {{ myOpinion ? 'Update opinion' : 'Post opinion' }}
        </CdxButton>
      </div>
    </div>
  </div>
</template>
