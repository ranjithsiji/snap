<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  CdxButton,
  CdxDialog,
  CdxField,
  CdxInfoChip,
  CdxMessage,
  CdxProgressBar,
  CdxTab,
  CdxTabs,
  CdxTextArea,
  CdxTextInput,
} from '@wikimedia/codex'
import { api } from '@/api'
import { useSession } from '@/stores/session'
import OpinionThread from '@/components/OpinionThread.vue'

/**
 * The final jury meeting.
 *
 * Asynchronous by design: the panel usually decides on a video call and
 * records the outcome here. Every juror's proposed ranking is kept, so
 * disagreements are visible and can be argued out on the record.
 */
const props = defineProps({ id: { type: String, required: true } })
const router = useRouter()
const session = useSession()

const round = ref(null)
const images = ref([])
const discussion = ref([])
const revision = ref(null)
const isFinalized = ref(false)
const canFinalize = ref(false)

const loading = ref(true)
const busy = ref(false)
const error = ref(null)
const notice = ref(null)
const tab = ref('ranking')

const myOrder = ref([])
const proposeOpen = ref(false)
const newPost = ref('')

const conflicts = computed(() => images.value.filter((i) => i.isConflict))

async function load() {
  loading.value = true

  try {
    const [detail, matrix] = await Promise.all([
      api.get(`/meetings/${props.id}`),
      api.get(`/meetings/${props.id}/proposals`),
    ])

    round.value = detail.round
    discussion.value = detail.discussion
    isFinalized.value = detail.isFinalized
    canFinalize.value = detail.canFinalize
    images.value = matrix.images
    revision.value = matrix.revision

    // Seed the juror's draft from the agreed order, so they adjust rather
    // than build a ranking from nothing.
    myOrder.value = matrix.images.map((image) => ({
      ...image,
      myRank: String(myPosition(image) ?? image.position),
    }))
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

onMounted(load)

/** This juror's own proposed position for an image, if they made one. */
function myPosition(image) {
  const mine = image.proposals.find((p) => p.juror === session.user?.username)

  return mine ? mine.position : null
}

/** Inserts at the requested rank and shifts the rest, avoiding duplicates. */
function applyRank(index, raw) {
  const entry = myOrder.value[index]
  const requested = Number(raw)

  if (!Number.isInteger(requested) || requested < 1) {
    entry.myRank = raw
    return
  }

  const target = Math.min(requested, myOrder.value.length)
  const others = myOrder.value
    .filter((_, i) => i !== index)
    .sort((a, b) => Number(a.myRank) - Number(b.myRank))

  others.splice(target - 1, 0, entry)

  const positions = new Map(others.map((item, i) => [item.imageId, i + 1]))
  myOrder.value = myOrder.value.map((item) => ({
    ...item,
    myRank: String(positions.get(item.imageId)),
  }))
}

async function submitProposal() {
  busy.value = true
  error.value = null

  try {
    const ordered = [...myOrder.value]
      .sort((a, b) => Number(a.myRank) - Number(b.myRank))
      .map((entry) => entry.imageId)

    const data = await api.post(`/meetings/${props.id}/proposals`, { order: ordered })

    images.value = data.images
    revision.value = data.revision
    proposeOpen.value = false
    notice.value = 'Your ranking has been recorded. The agreed order has been recalculated.'
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

async function post() {
  if (!newPost.value.trim()) return

  busy.value = true

  try {
    await api.post(`/meetings/${props.id}/comments`, { body: newPost.value })
    newPost.value = ''
    discussion.value = (await api.get(`/meetings/${props.id}`)).discussion
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

async function setFinalized(finalized) {
  busy.value = true
  error.value = null

  try {
    const data = await api.post(`/meetings/${props.id}/${finalized ? 'finalize' : 'reopen'}`)
    isFinalized.value = data.isFinalized
    round.value = data.round
    notice.value = finalized
      ? 'The result has been finalized. You can reopen it if the panel needs to revisit it.'
      : 'The meeting has been reopened for further changes.'
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

function onMatrixUpdated(updated) {
  images.value = updated
}
</script>

<template>
  <CdxProgressBar v-if="loading" aria-label="Loading meeting" />

  <template v-else-if="round">
    <div class="page-head">
      <div>
        <h1 class="page-title">{{ round.name }}</h1>
        <p class="page-subtitle">
          Final jury meeting · {{ round.campaignName }}
          <template v-if="round.derivedFromRoundName"> · from {{ round.derivedFromRoundName }}</template>
        </p>
      </div>

      <div class="row">
        <CdxInfoChip :status="isFinalized ? 'success' : 'notice'">
          {{ isFinalized ? 'finalized' : 'open' }}
        </CdxInfoChip>

        <CdxButton v-if="!isFinalized" @click="proposeOpen = true">My ranking</CdxButton>

        <CdxButton
          v-if="canFinalize && !isFinalized"
          action="progressive"
          weight="primary"
          :disabled="busy"
          @click="setFinalized(true)"
        >
          Finalize result
        </CdxButton>

        <CdxButton v-if="canFinalize && isFinalized" :disabled="busy" @click="setFinalized(false)">
          Reopen meeting
        </CdxButton>

        <CdxButton @click="router.push({ name: 'my-rounds' })">Back</CdxButton>
      </div>
    </div>

    <CdxMessage v-if="error" type="error">{{ error }}</CdxMessage>
    <CdxMessage v-if="notice" type="success">{{ notice }}</CdxMessage>

    <CdxMessage v-if="isFinalized" type="notice">
      This result is finalized. An organizer can reopen the meeting to make further changes.
    </CdxMessage>

    <CdxMessage v-else-if="conflicts.length > 0" type="warning">
      The panel disagrees about {{ conflicts.length }} image{{ conflicts.length === 1 ? '' : 's' }}.
      Open the Conflicts tab to discuss them.
    </CdxMessage>

    <CdxTabs v-model:active="tab" framed style="margin-bottom: 1rem">
      <CdxTab name="ranking" :label="`Agreed ranking (${images.length})`" />
      <CdxTab name="conflicts" :label="`Conflicts (${conflicts.length})`" />
      <CdxTab name="discussion" :label="`Discussion (${discussion.length})`" />
    </CdxTabs>

    <!-- Agreed ranking, with every juror's proposal beside it -->
    <div v-if="tab === 'ranking'" class="stack">
      <div v-for="image in images" :key="image.imageId" class="card meeting-row">
        <img :src="image.thumbUrl" :alt="image.title" class="meeting-thumb" />

        <div class="meeting-body">
          <div class="row wrap">
            <strong class="meeting-rank">#{{ image.position }}</strong>
            <span class="muted">{{ image.title }}</span>
            <CdxInfoChip v-if="image.isConflict" status="warning">disputed</CdxInfoChip>
            <span class="spacer"></span>
            <span v-if="image.drift !== 0" class="muted" style="font-size: 0.8125rem">
              moved {{ Math.abs(image.drift) }} {{ image.drift > 0 ? 'up' : 'down' }}
            </span>
          </div>

          <div class="proposal-row">
            <span
              v-for="proposal in image.proposals"
              :key="proposal.juror"
              class="proposal-chip"
              :title="proposal.rationale ?? ''"
            >
              {{ proposal.juror }}: <strong>#{{ proposal.position }}</strong>
            </span>
            <span v-if="image.proposals.length === 0" class="muted">No proposals yet</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Disputed images, with opinions and points -->
    <div v-else-if="tab === 'conflicts'" class="stack">
      <div v-if="conflicts.length === 0" class="card empty">
        <p>The panel agrees on every image so far.</p>
      </div>

      <OpinionThread
        v-for="image in conflicts"
        :key="image.imageId"
        :meeting-id="props.id"
        :image="image"
        :readonly="isFinalized"
        @updated="onMatrixUpdated"
      />
    </div>

    <!-- General discussion -->
    <div v-else class="stack">
      <div v-if="discussion.length === 0" class="card empty">
        <p>No discussion yet.</p>
      </div>

      <div v-for="post in discussion" :key="post.id" class="card">
        <div class="row">
          <strong>{{ post.author }}</strong>
          <span class="muted" style="font-size: 0.8125rem">
            {{ new Date(post.createdAt).toLocaleString() }}
          </span>
        </div>
        <p style="margin: 0.5rem 0 0; white-space: pre-wrap">{{ post.body }}</p>
      </div>

      <div v-if="!isFinalized" class="card">
        <CdxField>
          <template #label>Add to the discussion</template>
          <CdxTextArea v-model="newPost" rows="3" placeholder="Share your thinking with the panel…" />
        </CdxField>
        <div class="row row-end">
          <CdxButton
            action="progressive"
            weight="primary"
            :disabled="busy || !newPost.trim()"
            @click="post"
          >
            Post
          </CdxButton>
        </div>
      </div>
    </div>

    <!-- This juror's own proposed ranking -->
    <CdxDialog
      v-model:open="proposeOpen"
      title="My proposed ranking"
      subtitle="Your ranking is recorded separately, so disagreements stay visible"
      :primary-action="{ label: 'Submit my ranking', actionType: 'progressive', disabled: busy }"
      :default-action="{ label: 'Cancel' }"
      @primary="submitProposal"
      @default="proposeOpen = false"
    >
      <div class="stack" style="gap: 0.5rem">
        <div v-for="(entry, index) in myOrder" :key="entry.imageId" class="row propose-row">
          <img :src="entry.thumbUrl" :alt="entry.title" class="propose-thumb" />
          <span class="muted propose-title">{{ entry.title }}</span>
          <CdxTextInput
            :model-value="entry.myRank"
            input-type="number"
            min="1"
            :max="myOrder.length"
            :aria-label="`My rank for ${entry.title}`"
            style="max-width: 5rem"
            @update:model-value="applyRank(index, $event)"
          />
        </div>
      </div>
    </CdxDialog>
  </template>
</template>
