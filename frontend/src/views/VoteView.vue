<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
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
import { fetchDescription } from '@/commons'
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

// The juror's existing score when the image on the stage is one they have
// already judged, otherwise null. Only ever set by openInSingleView; the
// queue excludes images this juror has voted on.
const alreadyJudged = ref(null)

// The score just clicked, held from the click until the stage moves on to
// another image — the queue never returns the juror's own vote, so this
// is the only record of it while the star row still needs to show it.
const pendingScore = ref(null)

// What the star row should show as filled: the vote just cast, or the
// verdict already on record for an image opened from a judged gallery
// tab. Never both — alreadyJudged only applies to an image nothing has
// been clicked on yet this visit, and a fresh click means a fresh choice.
const displayedScore = computed(() => pendingScore.value ?? alreadyJudged.value)

// The star currently under the pointer, so hovering the third star
// previews all three as filled rather than only the one being pointed
// at — moving off the row falls back to whatever is actually recorded.
const hoveredScore = ref(null)

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

/**
 * The open image's description, read from Commons by the browser.
 *
 * Not stored by this tool: the text lives on Commons, changes there, and
 * is wanted for one image at a time — fetching it per view keeps it
 * current and costs the import nothing.
 */
const description = ref(null)
const descriptionLoading = ref(false)

watch(
  () => current.value?.commonsPageId,
  async (pageId) => {
    description.value = null

    if (pageId === undefined || pageId === null) return

    descriptionLoading.value = true

    try {
      const text = await fetchDescription(pageId)

      // Voting is quick, and a slow reply can land after the juror has
      // moved on; without this check it would appear under the next
      // photograph as if it described that one.
      if (current.value?.commonsPageId === pageId) {
        description.value = text
      }
    } finally {
      if (current.value?.commonsPageId === pageId) {
        descriptionLoading.value = false
      }
    }
  },
  { immediate: true },
)

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

  // The gallery's selected/rejected/all tabs list images this juror has
  // already judged, and picking one used to open it as though a verdict
  // were still due: the vote buttons were live, and casting one hit the
  // "already voted" error. The queue itself never hands these out, so the
  // stage only ever sees one via this path.
  alreadyJudged.value = image.score ?? null
  pendingScore.value = null
}

/**
 * Opens a Commons URL in a new tab.
 *
 * Kept as a function rather than calling window.open inline in the
 * template: template expressions resolve bare identifiers against the
 * component's render context, not the global scope, so `window` is
 * undefined there and the click throws instead of opening anything.
 */
function openExternal(url) {
  window.open(url, '_blank', 'noopener')
}

async function loadQueue() {
  fetchingNext.value = true

  try {
    const limit = isRanking.value ? 200 : 1
    const data = await api.get(`/my/rounds/${props.id}/queue?limit=${limit}`)

    images.value = data.images
    exhausted.value = data.exhausted
    // Everything the queue returns is awaiting this juror's verdict, so
    // any banner or fill left over from a gallery pick or the last vote
    // no longer applies.
    alreadyJudged.value = null
    pendingScore.value = null
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
  // Fills the clicked star and everything before it the instant the click
  // happens, rather than only once the request returns — the star row
  // otherwise looked like the click had no effect for the whole round trip.
  pendingScore.value = score

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
    // The vote did not go through, so the fill should not linger on stars
    // that do not reflect anything real.
    pendingScore.value = null
  } finally {
    busy.value = false
  }
}

async function skip() {
  if (!current.value || busy.value) return

  busy.value = true
  showFullSize.value = false
  pendingScore.value = null

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
  // alreadyJudged covers the same case the buttons disable for: a shortcut
  // must not reach past an inert button and hit the "already voted" error.
  if (busy.value || isRanking.value || mode.value !== 'single' || !current.value) return
  if (alreadyJudged.value !== null) return
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

  <!-- loadRound() can throw before round is ever set — not a juror on
       this round, the round no longer exists, and so on — in which case
       the message below was previously unreachable: it lived inside the
       v-else-if="round" branch, so a request that failed before round was
       set left the page rendering nothing at all rather than the error a
       juror (or an admin who is not on this round's panel) needed to see. -->
  <template v-else-if="error">
    <CdxMessage type="error">{{ error }}</CdxMessage>
    <CdxButton style="margin-top: 1rem" @click="router.push({ name: 'my-rounds' })">
      Back to my rounds
    </CdxButton>
  </template>

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
            @click="openExternal(current.descriptionUrl)"
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
            @click="openExternal(current.fileUrl)"
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
          <!-- Falls back the way the gallery tile does. A juror reaching
               this stage from a filtered gallery tab can be handed an
               image the queue never presented, and an absent thumbUrl
               left the stage showing a broken frame while the very same
               image rendered fine in the grid. -->
          <img
            :src="
              showFullSize
                ? (current.fileUrl ?? current.thumbUrl)
                : (current.thumbUrl ?? current.fileUrl)
            "
            :alt="current.name ?? 'Image awaiting judgement'"
          />
        </div>

        <!-- Reached only by picking an image from a gallery tab that
             lists judged work; says why the buttons below are inert. -->
        <CdxMessage v-if="alreadyJudged !== null" type="notice" class="stage-judged">
          <template v-if="isYesNo">
            You already {{ alreadyJudged >= 1 ? 'accepted' : 'declined' }} this image.
          </template>
          <template v-else> You already rated this image {{ alreadyJudged }}. </template>
          Return to the gallery to change your vote.
        </CdxMessage>

        <!-- The vote sits directly under the photograph rather than in
             the sidebar: it is the one thing done on every image. -->
        <div class="stage-actions">
          <div v-if="isYesNo" class="row">
            <CdxButton
              action="progressive"
              weight="primary"
              :disabled="busy || alreadyJudged !== null"
              @click="vote(1)"
            >
              <CdxIcon :icon="cdxIconCheck" /> Accept
            </CdxButton>
            <CdxButton
              action="destructive"
              :disabled="busy || alreadyJudged !== null"
              @click="vote(0)"
            >
              <CdxIcon :icon="cdxIconClose" /> Decline
            </CdxButton>
          </div>

          <div v-else class="star-row" @mouseleave="hoveredScore = null">
            <button
              v-for="star in stars"
              :key="star"
              type="button"
              class="star"
              :class="{ on: (hoveredScore ?? displayedScore) >= star }"
              :aria-label="`Rate ${star}`"
              :disabled="busy || alreadyJudged !== null"
              @mouseenter="hoveredScore = star"
              @click="vote(star)"
            >
              ★
            </button>
          </div>

          <span class="spacer"></span>

          <CdxButton weight="quiet" :disabled="busy || alreadyJudged !== null" @click="skip">
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

        <!-- Read from Commons by the browser rather than stored here, so
             it reflects whatever the file's page says now. -->
        <template v-if="descriptionLoading || description">
          <h3 class="vote-section">Description</h3>
          <p v-if="descriptionLoading" class="muted vote-description">Loading…</p>
          <p v-else class="vote-description">{{ description }}</p>
        </template>

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
