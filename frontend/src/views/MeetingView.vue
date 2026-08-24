<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
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
  CdxTextInput,
} from '@wikimedia/codex'
import {
  cdxIconArrowDown,
  cdxIconArrowUp,
  cdxIconClose,
  cdxIconCollapse,
  cdxIconExpand,
  cdxIconImageGallery,
  cdxIconImageLayoutThumbnail,
  cdxIconListBullet,
  cdxIconNext,
  cdxIconPrevious,
} from '@wikimedia/codex-icons'
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
const route = useRoute()
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
// Switches the agreed-ranking tab between the row list (per-juror
// proposals beside each image), a gallery grid, and a film strip — some
// coordinators want to scan every photograph at once rather than read
// down a list, others want the compact strip used elsewhere in the
// meeting for quickly jumping into a single image to discuss. Seeded
// from the URL so a link from the per-image discuss screen's own view
// switcher lands here already showing the same view.
const validRankingViews = ['list', 'gallery', 'filmstrip']
const rankingView = ref(
  validRankingViews.includes(route.query.view) ? route.query.view : 'list',
)
const lightbox = ref(null)
const lightboxDetailsOpen = ref(true)

// Paged at 25, same cap as the per-image discuss screen's own strip —
// a meeting can carry over far more images than a strip that size can
// usefully show at once.
const FILMSTRIP_PAGE_SIZE = 25
const filmstripPage = ref(0)
const filmstripPageCount = computed(() =>
  Math.max(1, Math.ceil(images.value.length / FILMSTRIP_PAGE_SIZE)),
)
const filmstripImages = computed(() => {
  const start = filmstripPage.value * FILMSTRIP_PAGE_SIZE

  return images.value.slice(start, start + FILMSTRIP_PAGE_SIZE)
})

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

// Gallery-view position field, per tile — typing a new position moves
// the image there and shifts the rest, same insert-and-shift behaviour
// as a juror's own ranking gallery, but resubmitted through reorder()
// since this is the shared agreed order, not one juror's proposal.
const galleryDrafts = ref({})

// The moved tile briefly highlights after landing on its new position —
// typing a number can send the tile somewhere else in the grid, and
// without this the reorder is easy to miss having actually happened.
const justMovedId = ref(null)
let justMovedTimer = null

function flashMoved(imageId) {
  clearTimeout(justMovedTimer)
  justMovedId.value = imageId
  justMovedTimer = setTimeout(() => {
    justMovedId.value = null
  }, 1500)
}

function galleryPosition(image) {
  return galleryDrafts.value[image.imageId] ?? String(image.position)
}

async function applyGalleryRank(image, rawValue) {
  galleryDrafts.value[image.imageId] = rawValue

  const requested = Number(rawValue)

  if (!Number.isInteger(requested) || requested < 1) return

  const target = Math.min(requested, images.value.length)
  const changedIndex = images.value.findIndex((i) => i.imageId === image.imageId)

  if (changedIndex === -1 || target === image.position) return

  busy.value = true
  error.value = null

  try {
    const others = images.value.filter((i) => i.imageId !== image.imageId)
    others.splice(target - 1, 0, image)

    const order = others.map((i) => i.imageId)

    await api.post(`/meetings/${props.id}/order`, { order, revision: revision.value })

    const matrix = await api.get(`/meetings/${props.id}/proposals`)
    images.value = matrix.images
    revision.value = matrix.revision
    galleryDrafts.value = {}
    flashMoved(image.imageId)
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

// Moves the open lightbox to the neighbouring image in agreed order,
// without closing it — scanning through the set is the point of a
// lightbox, and forcing a close-reopen for every image defeats that.
const lightboxIndex = computed(() =>
  lightbox.value ? images.value.findIndex((i) => i.imageId === lightbox.value.imageId) : -1,
)

function lightboxStep(direction) {
  const next = images.value[lightboxIndex.value + direction]

  if (next) lightbox.value = next
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
    <template v-if="tab === 'ranking'">
      <div class="gallery-toolbar">
        <span class="muted">{{ images.length }} image(s)</span>
        <span class="spacer"></span>
        <CdxButton
          weight="quiet"
          :class="{ 'is-active-view': rankingView === 'list' }"
          aria-label="List view"
          title="List view"
          @click="rankingView = 'list'"
        >
          <CdxIcon :icon="cdxIconListBullet" />
        </CdxButton>
        <CdxButton
          weight="quiet"
          :class="{ 'is-active-view': rankingView === 'gallery' }"
          aria-label="Gallery view"
          title="Gallery view"
          @click="rankingView = 'gallery'"
        >
          <CdxIcon :icon="cdxIconImageGallery" />
        </CdxButton>
        <CdxButton
          weight="quiet"
          :class="{ 'is-active-view': rankingView === 'filmstrip' }"
          aria-label="Film strip view"
          title="Film strip view"
          @click="rankingView = 'filmstrip'"
        >
          <CdxIcon :icon="cdxIconImageLayoutThumbnail" />
        </CdxButton>
      </div>

      <div v-if="rankingView === 'list'" class="stack">
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

      <!-- Gallery view: every image at once, in agreed order, with a
           lightbox for a closer look — the list stays for reading who
           proposed what, this is for scanning the set as photographs. -->
      <div v-else-if="rankingView === 'gallery'" class="gallery-grid">
        <figure
          v-for="image in images"
          :key="image.imageId"
          class="gallery-tile meeting-gallery-tile"
          :class="{ 'is-just-moved': justMovedId === image.imageId }"
        >
          <img :src="image.thumbUrl" :alt="image.title" loading="lazy" />
          <span class="meeting-gallery-position">#{{ image.position }}</span>

          <CdxInfoChip v-if="image.isConflict" status="warning" class="meeting-gallery-conflict">
            disputed
          </CdxInfoChip>

          <button
            type="button"
            class="tile-action tile-zoom"
            aria-label="View larger"
            @click="lightbox = image"
          >
            ⤢
          </button>

          <figcaption class="rank-caption">
            <CdxTextInput
              :model-value="galleryPosition(image)"
              input-type="number"
              min="1"
              :max="images.length"
              :disabled="isFinalized || busy"
              :aria-label="`Position for ${image.title}`"
              @update:model-value="applyGalleryRank(image, $event)"
            />
          </figcaption>
        </figure>
      </div>

      <!-- Film strip view: the same compact strip used on the per-image
           discuss screen, paged at 25 — a quick way to scan the order and
           jump straight into discussing one image. -->
      <div v-else class="meeting-filmstrip meeting-filmstrip-standalone">
        <div class="filmstrip-pager">
          <CdxButton
            weight="quiet"
            :disabled="filmstripPage === 0"
            aria-label="Previous page of the film strip"
            @click="filmstripPage--"
          >
            <CdxIcon :icon="cdxIconPrevious" />
          </CdxButton>
          <span class="muted" style="font-size: var(--font-size-x-small)">
            {{ filmstripPage + 1 }} / {{ filmstripPageCount }}
          </span>
          <CdxButton
            weight="quiet"
            :disabled="filmstripPage >= filmstripPageCount - 1"
            aria-label="Next page of the film strip"
            @click="filmstripPage++"
          >
            <CdxIcon :icon="cdxIconNext" />
          </CdxButton>
        </div>

        <button
          v-for="image in filmstripImages"
          :key="image.imageId"
          type="button"
          class="filmstrip-item"
          @click="router.push({ name: 'meeting-image', params: { id, imageId: image.imageId } })"
        >
          <img :src="image.thumbUrl" :alt="image.title" loading="lazy" />
          <span class="filmstrip-position">#{{ image.position }}</span>
        </button>
      </div>
    </template>

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

  <!-- Detailed view of one image without leaving the grid. -->
  <div v-if="lightbox" class="lightbox" @click="lightbox = null">
    <img class="lightbox-image" :src="lightbox.fileUrl ?? lightbox.thumbUrl" :alt="lightbox.title" />

    <aside v-if="lightboxDetailsOpen" class="lightbox-panel" @click.stop>
      <div class="row lightbox-panel-head">
        <h2 class="vote-filename">{{ lightbox.title }}</h2>
        <CdxButton
          weight="quiet"
          aria-label="Minimise details"
          title="Minimise details"
          @click="lightboxDetailsOpen = false"
        >
          <CdxIcon :icon="cdxIconCollapse" />
        </CdxButton>
      </div>

      <h3 class="vote-section">Agreed ranking</h3>
      <dl class="stat-list">
        <div>
          <dt>Position</dt>
          <dd>#{{ lightbox.position }}</dd>
        </div>
        <div v-if="lightbox.width">
          <dt>Resolution</dt>
          <dd>{{ lightbox.width }} × {{ lightbox.height }}</dd>
        </div>
      </dl>

      <template v-if="lightbox.proposals?.length">
        <h3 class="vote-section">Jury proposals</h3>
        <div class="proposal-row">
          <span
            v-for="proposal in lightbox.proposals"
            :key="proposal.juror"
            class="proposal-chip"
            :title="proposal.rationale ?? ''"
          >
            {{ proposal.juror }}: <strong>#{{ proposal.position }}</strong>
          </span>
        </div>
      </template>

      <CdxButton
        style="margin-top: 0.5rem"
        @click="router.push({ name: 'meeting-image', params: { id, imageId: lightbox.imageId } })"
      >
        View & discuss
      </CdxButton>

      <div class="row lightbox-panel-nav">
        <CdxButton
          :disabled="lightboxIndex <= 0"
          @click="lightboxStep(-1)"
        >
          <CdxIcon :icon="cdxIconPrevious" /> Prev
        </CdxButton>
        <CdxButton
          :disabled="lightboxIndex === -1 || lightboxIndex >= images.length - 1"
          @click="lightboxStep(1)"
        >
          Next <CdxIcon :icon="cdxIconNext" />
        </CdxButton>
      </div>

      <a
        v-if="lightbox.descriptionUrl"
        class="lightbox-panel-link"
        :href="lightbox.descriptionUrl"
        target="_blank"
        rel="noopener"
      >
        Open the Commons page
      </a>
    </aside>

    <CdxButton
      v-else
      weight="quiet"
      class="lightbox-restore"
      aria-label="Show details"
      title="Show details"
      @click.stop="lightboxDetailsOpen = true"
    >
      <CdxIcon :icon="cdxIconExpand" />
    </CdxButton>

    <button type="button" class="lightbox-close" aria-label="Close" @click.stop="lightbox = null">
      <CdxIcon :icon="cdxIconClose" />
    </button>
  </div>
</template>
