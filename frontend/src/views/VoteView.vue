<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  CdxButton,
  CdxIcon,
  CdxMessage,
  CdxProgressBar,
  CdxTabs,
  CdxTab,
} from '@wikimedia/codex'
import {
  cdxIconCheck,
  cdxIconClose,
  cdxIconDownload,
  cdxIconHeart,
  cdxIconImage,
  cdxIconLink,
  cdxIconNext,
} from '@wikimedia/codex-icons'
import { api } from '@/api'
import { formatNumber } from '@/format'
import GalleryGrid from '@/components/GalleryGrid.vue'
import RankBoard from '@/components/RankBoard.vue'

const props = defineProps({ id: { type: String, required: true } })
const router = useRouter()

const round = ref(null)
const stats = ref(null)
const counts = ref({ unrated: 0, selected: 0, rejected: 0, all: 0 })
const remaining = ref(0)
const handover = ref(null)

const images = ref([])
const loading = ref(true)
const busy = ref(false)
const error = ref(null)
const exhausted = ref(false)

// True only while a fresh queue is being fetched after the last image in
// hand was used up. Without this, the gap between images.shift() emptying
// the list and loadQueue()'s response landing rendered as "All done" —
// indistinguishable from genuine exhaustion, even mid-round.
const fetchingNext = ref(false)

// The gallery tile a juror picked to inspect in single view, so its
// highlight survives the round trip — the gallery component itself
// unmounts (v-else-if) while single view is showing, so this cannot live
// as its local state.
const pickedImageId = ref(null)

// "single" walks one image at a time; "gallery" is the fast grid pass.
const mode = ref('single')
const showFullSize = ref(false)

/**
 * What the photograph is shown against.
 *
 * Not a fixed choice: a dark surround flatters a bright image and buries
 * a dark one, and light does the reverse. Since telling those apart is
 * the juror's whole job, the backdrop is theirs to set — and remembered,
 * because it is a working preference rather than a per-image decision.
 */
const backdrops = [
  { value: 'dark', label: 'Dark' },
  { value: 'grey', label: 'Grey' },
  { value: 'light', label: 'Light' },
  { value: 'checker', label: 'Checkered' },
]

const backdrop = ref(localStorage.getItem('snap-backdrop') ?? 'grey')

function cycleBackdrop() {
  const index = backdrops.findIndex((b) => b.value === backdrop.value)
  backdrop.value = backdrops[(index + 1) % backdrops.length].value
  localStorage.setItem('snap-backdrop', backdrop.value)
}

const backdropLabel = computed(
  () => backdrops.find((b) => b.value === backdrop.value)?.label ?? 'Grey',
)

const current = computed(() => images.value[0] ?? null)
const isRanking = computed(() => round.value?.votingMethod === 'rank')
const isYesNo = computed(() => round.value?.votingMethod === 'yesno')
const stars = computed(() => Array.from({ length: round.value?.maxRating ?? 5 }, (_, i) => i + 1))

async function loadRound() {
  const data = await api.get(`/my/rounds/${props.id}`)
  round.value = data.round
  stats.value = data.statistics ?? null
  counts.value = data.counts
  remaining.value = data.remaining
  handover.value = data.handover ?? null
}

/**
 * Opens one specific image, picked from the gallery, in the single-image
 * flow — where a vote can actually be cast on it, unlike the gallery's
 * lightbox. It replaces whatever the queue was showing rather than being
 * inserted into it: this is a juror choosing to look at this photograph
 * now, not the next one due.
 */
function openInSingleView(image) {
  images.value = [image]
  exhausted.value = false
  mode.value = 'single'
  pickedImageId.value = image.id
}

async function loadQueue() {
  fetchingNext.value = true

  try {
    const limit = isRanking.value ? 200 : 1
    const data = await api.get(`/my/rounds/${props.id}/queue?limit=${limit}`)

    images.value = data.images
    exhausted.value = data.exhausted
  } finally {
    fetchingNext.value = false
  }
}

onMounted(async () => {
  try {
    await loadRound()

    // Ranking is inherently comparative, so it is always shown as a
    // gallery; yes/no and rating start on the single-image flow.
    mode.value = isRanking.value ? 'gallery' : 'single'

    await loadQueue()
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
})

async function vote(score) {
  if (!current.value || busy.value) return

  error.value = null
  busy.value = true
  showFullSize.value = false

  try {
    const data = await api.post(`/my/images/${current.value.id}/vote`, { score })
    if (data.statistics) stats.value = data.statistics

    remaining.value = Math.max(0, remaining.value - 1)
    counts.value.unrated = Math.max(0, counts.value.unrated - 1)
    if (score >= 1) counts.value.selected++
    else counts.value.rejected++

    images.value.shift()
    if (images.value.length === 0) await loadQueue()
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

async function skip() {
  if (!current.value || busy.value) return

  busy.value = true
  showFullSize.value = false

  try {
    await api.post(`/my/images/${current.value.id}/skip`, { skipped: true })

    // Move it out of the local queue; the server sorts skipped images last
    // so it will come back once the rest are done.
    images.value.shift()
    if (images.value.length === 0) await loadQueue()
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

async function toggleFavorite() {
  if (!current.value) return

  const next = !current.value.isFavorite

  try {
    await api.post(`/my/images/${current.value.id}/favorite`, { favorite: next })
    current.value.isFavorite = next
  } catch (e) {
    error.value = e.message
  }
}

function onKey(event) {
  if (busy.value || isRanking.value || mode.value !== 'single' || !current.value) return
  if (event.target.tagName === 'INPUT' || event.target.tagName === 'TEXTAREA') return

  if (event.key === 'ArrowRight' || event.key === 's') {
    skip()
    return
  }

  if (isYesNo.value) {
    if (event.key === 'ArrowUp' || event.key === 'y') vote(1)
    if (event.key === 'ArrowDown' || event.key === 'n') vote(0)
    return
  }

  const digit = Number(event.key)
  if (Number.isInteger(digit) && digit >= 1 && digit <= round.value.maxRating) vote(digit)
}

onMounted(() => window.addEventListener('keydown', onKey))
onUnmounted(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <CdxProgressBar v-if="loading" aria-label="Loading round" />

  <template v-else-if="round">
    <div class="page-head">
      <div>
        <h1 class="page-title">{{ round.name }}</h1>
        <p class="page-subtitle">{{ round.campaignName }} · {{ round.votingMethodLabel }}</p>
      </div>

      <div class="row">
        <CdxButton
          v-if="!isRanking"
          :aria-pressed="mode === 'gallery'"
          @click="mode = mode === 'single' ? 'gallery' : 'single'"
        >
          {{ mode === 'single' ? 'Gallery view' : 'Single image view' }}
        </CdxButton>
        <CdxButton @click="router.push({ name: 'my-rounds' })">Back to my rounds</CdxButton>
      </div>
    </div>

    <CdxMessage v-if="error" type="error">{{ error }}</CdxMessage>

    <!-- Shown when this juror took over another juror's seat, so they know
         the inherited votes are now theirs to stand behind or change. -->
    <CdxMessage v-if="handover" type="warning" :dismiss-button-label="'Dismiss'">
      <strong>You have taken over from {{ handover.replacedUsername }}.</strong>
      {{ handover.notice }}
      <template v-if="handover.inheritedVotes > 0">
        <br />
        <CdxButton weight="quiet" style="margin-top: 0.5rem" @click="mode = 'gallery'">
          Review inherited votes
        </CdxButton>
      </template>
    </CdxMessage>

    <CdxMessage v-if="round.details" type="notice">{{ round.details }}</CdxMessage>
    <CdxMessage v-if="!round.acceptsVotes" type="warning">
      This round is not currently accepting votes.
    </CdxMessage>

    <!-- Ranking: order everything, submit once -->
    <RankBoard
      v-else-if="isRanking"
      :round-id="props.id"
      :images="images"
      @submitted="exhausted = true"
    />

    <!-- Gallery: fast grid pass with per-tile accept/reject -->
    <GalleryGrid
      v-else-if="mode === 'gallery'"
      :round="round"
      :counts="counts"
      :picked-id="pickedImageId"
      @voted="loadRound"
      @open-single="openInSingleView"
    />

    <!-- Between one image and the next: without this branch, the moment
         the used-up image leaves the queue looked identical to genuinely
         running out, mid-round. -->
    <div v-else-if="fetchingNext" class="card empty">
      <CdxProgressBar aria-label="Loading the next image" />
    </div>

    <!-- All caught up -->
    <div v-else-if="exhausted || !current" class="card empty">
      <h2>All done</h2>
      <p>You have judged every image in this round. Thank you.</p>
      <CdxButton action="progressive" weight="primary" @click="router.push({ name: 'my-rounds' })">
        Back to my rounds
      </CdxButton>
    </div>

    <!-- Single image: the photograph and everything acting on it in one
         frame, so a juror's eyes travel a short distance between
         deciding and voting; supporting detail to the side. -->
    <div v-else class="vote-layout">
      <div class="stage">
        <div class="stage-bar">
          <span class="stage-position">
            {{ formatNumber(counts.all - remaining + 1) }} of {{ formatNumber(counts.all) }}
          </span>

          <span class="spacer"></span>

          <CdxButton
            weight="quiet"
            class="stage-tool"
            :title="`Backdrop: ${backdropLabel}`"
            @click="cycleBackdrop"
          >
            <span class="backdrop-swatch" :class="`swatch-${backdrop}`"></span>
            {{ backdropLabel }}
          </CdxButton>

          <CdxButton
            weight="quiet"
            class="stage-tool"
            :title="showFullSize ? 'Fit to the frame' : 'Show at full size'"
            @click="showFullSize = !showFullSize"
          >
            <CdxIcon :icon="cdxIconImage" />
          </CdxButton>

          <CdxButton
            v-if="current.descriptionUrl"
            weight="quiet"
            class="stage-tool"
            title="Open the Commons page"
            @click="window.open(current.descriptionUrl, '_blank', 'noopener')"
          >
            <CdxIcon :icon="cdxIconLink" />
          </CdxButton>

          <!-- The Commons page above is the file's wiki entry; this is the
               raw image itself, for when a juror wants to inspect the
               original rather than the description. -->
          <CdxButton
            v-if="current.fileUrl"
            weight="quiet"
            class="stage-tool"
            title="Open the original file"
            @click="window.open(current.fileUrl, '_blank', 'noopener')"
          >
            <CdxIcon :icon="cdxIconDownload" />
          </CdxButton>

          <CdxButton
            weight="quiet"
            class="stage-tool"
            :class="{ 'is-on': current.isFavorite }"
            :title="current.isFavorite ? 'Remove from favourites' : 'Add to favourites'"
            @click="toggleFavorite"
          >
            <CdxIcon :icon="cdxIconHeart" />
          </CdxButton>
        </div>

        <div class="stage-image" :class="[`backdrop-${backdrop}`, { 'is-full': showFullSize }]">
          <img
            :src="showFullSize ? current.fileUrl : current.thumbUrl"
            :alt="current.name ?? 'Image awaiting judgement'"
          />
        </div>

        <!-- The vote sits directly under the photograph rather than in
             the sidebar: it is the one thing done on every image. -->
        <div class="stage-actions">
          <div v-if="isYesNo" class="row">
            <CdxButton action="progressive" weight="primary" :disabled="busy" @click="vote(1)">
              <CdxIcon :icon="cdxIconCheck" /> Accept
            </CdxButton>
            <CdxButton action="destructive" :disabled="busy" @click="vote(0)">
              <CdxIcon :icon="cdxIconClose" /> Decline
            </CdxButton>
          </div>

          <div v-else class="star-row">
            <button
              v-for="star in stars"
              :key="star"
              type="button"
              class="star"
              :aria-label="`Rate ${star}`"
              :disabled="busy"
              @click="vote(star)"
            >
              ★
            </button>
          </div>

          <span class="spacer"></span>

          <CdxButton weight="quiet" :disabled="busy" @click="skip">
            <CdxIcon :icon="cdxIconNext" /> Skip
          </CdxButton>
        </div>
      </div>

      <aside class="vote-panel">
        <h2 class="vote-filename">{{ current.name ?? 'Image awaiting judgement' }}</h2>

        <div class="vote-progress">
          <div class="meter">
            <span
              :style="{ width: `${counts.all ? ((counts.all - remaining) / counts.all) * 100 : 0}%` }"
            ></span>
          </div>
          <p class="muted vote-progress-text">
            {{ formatNumber(remaining) }} left of {{ formatNumber(counts.all) }}
          </p>
        </div>

        <template v-if="current.width || current.megapixels || current.uploader">
          <h3 class="vote-section">Image</h3>
          <dl class="stat-list">
            <div v-if="current.width">
              <dt>Resolution</dt>
              <dd>{{ current.width }} × {{ current.height }}</dd>
            </div>
            <div v-if="current.megapixels">
              <dt>Megapixels</dt>
              <dd>{{ current.megapixels }} Mpix</dd>
            </div>
            <!-- Only present when the round has opted out of blind judging;
                 the field is withheld server-side otherwise. -->
            <div v-if="current.uploader">
              <dt>Uploader</dt>
              <dd>{{ current.uploader }}</dd>
            </div>
          </dl>
        </template>

        <template v-if="stats">
          <h3 class="vote-section">Your statistics</h3>
          <dl class="stat-list">
            <div><dt>Votes cast</dt><dd>{{ formatNumber(stats.totalVotes) }}</dd></div>
            <template v-if="isYesNo">
              <div><dt>Accepted</dt><dd>{{ formatNumber(stats.accepted) }}</dd></div>
              <div><dt>Declined</dt><dd>{{ formatNumber(stats.declined) }}</dd></div>
            </template>
          </dl>
        </template>

        <h3 class="vote-section">Keyboard</h3>
        <p class="muted vote-hint">
          <template v-if="isYesNo"><kbd>↑</kbd> <kbd>↓</kbd> — Accept / Decline<br /></template>
          <template v-else><kbd>1</kbd>–<kbd>{{ round.maxRating }}</kbd> — Rate<br /></template>
          <kbd>→</kbd> — Skip (vote later)
        </p>

        <CdxButton weight="quiet" style="margin-top: 0.75rem" @click="mode = 'gallery'">
          Edit previous votes
        </CdxButton>
      </aside>
    </div>
  </template>
</template>
