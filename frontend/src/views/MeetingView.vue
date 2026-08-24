<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  CdxButton,
  CdxField,
  CdxIcon,
  CdxInfoChip,
  CdxMessage,
  CdxProgressBar,
  CdxTab,
  CdxTabs,
  CdxTextArea,
} from '@wikimedia/codex'
import { cdxIconArrowDown, cdxIconArrowUp } from '@wikimedia/codex-icons'
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

const newPost = ref('')
// Toggles the agreed-ranking tab between the row list (per-juror proposals
// beside each image) and a gallery grid — some coordinators want to scan
// every photograph at once rather than read down a list.
const rankingView = ref('list')

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
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

onMounted(load)

/**
 * Swaps an image with its neighbour and resubmits the whole agreed
 * order — reorder() takes every image at once, same as propose(), so a
 * single swap still means sending the complete list back.
 */
async function moveImage(index, direction) {
  const target = index + direction

  if (target < 0 || target >= images.value.length) return

  busy.value = true
  error.value = null

  try {
    const order = images.value.map((image) => image.imageId)
    ;[order[index], order[target]] = [order[target], order[index]]

    await api.post(`/meetings/${props.id}/order`, {
      order,
      revision: revision.value,
    })

    // reorder() answers with the plain consensus list, not the fuller
    // proposal matrix this screen actually displays — proposals, spread,
    // isConflict — so the ranking tab is reloaded from proposals()
    // instead of assigning that narrower shape over what is shown.
    const matrix = await api.get(`/meetings/${props.id}/proposals`)
    images.value = matrix.images
    revision.value = matrix.revision
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
        <p v-if="round.jurorUsernames?.length" class="muted" style="margin: 0.25rem 0 0">
          Panel: {{ round.jurorUsernames.join(', ') }}
        </p>
      </div>

      <div class="row">
        <CdxInfoChip :status="isFinalized ? 'success' : 'notice'">
          {{ isFinalized ? 'finalized' : 'open' }}
        </CdxInfoChip>

        <CdxButton
          v-if="!isFinalized"
          @click="router.push({ name: 'meeting-rank', params: { id: props.id } })"
        >
          My ranking
        </CdxButton>

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
      <div v-for="(image, index) in images" :key="image.imageId" class="card meeting-row">
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
            <!-- Moves the agreed order directly, the same list reorder()
                 always accepted — a swap with the neighbour, resubmitted
                 whole, same as any other change to this order. -->
            <div v-if="!isFinalized" class="row" style="gap: 0.125rem">
              <CdxButton
                weight="quiet"
                :disabled="busy || index === 0"
                aria-label="Move up"
                @click="moveImage(index, -1)"
              >
                <CdxIcon :icon="cdxIconArrowUp" />
              </CdxButton>
              <CdxButton
                weight="quiet"
                :disabled="busy || index === images.length - 1"
                aria-label="Move down"
                @click="moveImage(index, 1)"
              >
                <CdxIcon :icon="cdxIconArrowDown" />
              </CdxButton>
            </div>
            <CdxButton
              weight="quiet"
              @click="router.push({ name: 'meeting-image', params: { id, imageId: image.imageId } })"
            >
              View & discuss
            </CdxButton>
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
  </template>
</template>
