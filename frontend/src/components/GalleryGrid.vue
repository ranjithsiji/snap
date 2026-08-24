<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { CdxButton, CdxIcon, CdxMessage, CdxProgressBar, CdxTabs, CdxTab } from '@wikimedia/codex'
import {
  cdxIconCheck,
  cdxIconClose,
  cdxIconCollapse,
  cdxIconExpand,
  cdxIconFullscreen,
} from '@wikimedia/codex-icons'
import { api } from '@/api'
import { formatNumber } from '@/format'

/**
 * Grid pass over a round's images.
 *
 * Much faster than the single-image flow for a first sweep: the juror sees
 * many photos at once and accepts or rejects on hover. Tabs filter by how
 * they already voted, which doubles as the "edit previous votes" screen.
 */
const props = defineProps({
  round: { type: Object, required: true },
  counts: { type: Object, required: true },
  // Held by the parent rather than as local state: switching to single
  // view unmounts this component entirely (v-else-if in VoteView), which
  // would otherwise reset the pick the moment it was made.
  pickedId: { type: [Number, String], default: null },
})

const emit = defineEmits(['voted', 'open-single'])

function pick(image) {
  emit('open-single', image)
}

const PAGE_SIZE = 60

const filter = ref('unrated')
const images = ref([])
const total = ref(0)
const offset = ref(0)
const loading = ref(true)
const error = ref(null)
const localCounts = ref({ ...props.counts })
const lightbox = ref(null)

// Remembered across images rather than reset per-open: once a juror
// minimises the details, re-opening it for every next image they inspect
// would be the opposite of what dismissing it meant.
const lightboxDetailsOpen = ref(true)

const isYesNo = computed(() => props.round.votingMethod === 'yesno')
const stars = computed(() => Array.from({ length: props.round.maxRating ?? 5 }, (_, i) => i + 1))
const pageCount = computed(() => Math.max(1, Math.ceil(total.value / PAGE_SIZE)))
const currentPage = computed(() => Math.floor(offset.value / PAGE_SIZE) + 1)

const tabs = computed(() => [
  { name: 'unrated', label: `Unrated (${formatNumber(localCounts.value.unrated)})` },
  { name: 'selected', label: `Selected (${formatNumber(localCounts.value.selected)})` },
  { name: 'rejected', label: `Rejected (${formatNumber(localCounts.value.rejected)})` },
  { name: 'favorites', label: 'Favorites' },
])

async function load() {
  loading.value = true
  error.value = null

  try {
    const data = await api.get(
      `/my/rounds/${props.round.id}/gallery?filter=${filter.value}&limit=${PAGE_SIZE}&offset=${offset.value}`,
    )

    images.value = data.images
    total.value = data.total
    localCounts.value = data.counts
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

onMounted(load)

watch(filter, () => {
  offset.value = 0
  load()
})

function goToPage(page) {
  offset.value = (page - 1) * PAGE_SIZE
  load()
}

/** Page numbers to show: every page when few, otherwise decade jumps. */
const pageLinks = computed(() => {
  const pages = []
  const last = pageCount.value

  for (let i = 1; i <= Math.min(10, last); i++) pages.push(i)
  for (let i = 20; i <= last; i += 10) pages.push(i)
  if (last > 10 && !pages.includes(last)) pages.push(last)

  return pages
})

async function vote(image, score) {
  if (image.busy) return
  image.busy = true

  try {
    await api.post(`/my/images/${image.id}/vote`, { score })

    const wasUnrated = image.score === null || image.score === undefined
    image.score = score

    // Adjust the tab counts locally so the UI stays responsive; the server
    // sends authoritative counts on the next load.
    if (wasUnrated) localCounts.value.unrated = Math.max(0, localCounts.value.unrated - 1)
    else if (isSelected(score)) localCounts.value.rejected = Math.max(0, localCounts.value.rejected - 1)
    else localCounts.value.selected = Math.max(0, localCounts.value.selected - 1)

    if (isSelected(score)) localCounts.value.selected++
    else localCounts.value.rejected++

    // On a filtered tab the image no longer belongs here.
    if (filter.value !== 'all' && filter.value !== 'favorites') {
      images.value = images.value.filter((i) => i.id !== image.id)
      total.value = Math.max(0, total.value - 1)
    }

    emit('voted')
  } catch (e) {
    error.value = e.message
  } finally {
    image.busy = false
  }
}

function isSelected(score) {
  const threshold = isYesNo.value ? 1 : Math.ceil((props.round.maxRating ?? 5) / 2) + 1

  return score >= threshold
}

async function toggleFavorite(image) {
  const next = !image.isFavorite

  try {
    await api.post(`/my/images/${image.id}/favorite`, { favorite: next })
    image.isFavorite = next
  } catch (e) {
    error.value = e.message
  }
}
</script>

<template>
  <div class="gallery-toolbar">
    <CdxTabs v-model:active="filter" framed>
      <CdxTab v-for="tab in tabs" :key="tab.name" :name="tab.name" :label="tab.label" />
    </CdxTabs>

    <nav v-if="pageCount > 1" class="pager">
      <CdxButton
        weight="quiet"
        :disabled="currentPage === 1"
        aria-label="Previous page"
        @click="goToPage(currentPage - 1)"
      >
        «
      </CdxButton>

      <CdxButton
        v-for="page in pageLinks"
        :key="page"
        weight="quiet"
        :aria-current="page === currentPage ? 'page' : undefined"
        :class="{ 'pager-current': page === currentPage }"
        @click="goToPage(page)"
      >
        {{ page }}
      </CdxButton>

      <CdxButton
        weight="quiet"
        :disabled="currentPage === pageCount"
        aria-label="Next page"
        @click="goToPage(currentPage + 1)"
      >
        »
      </CdxButton>
    </nav>
  </div>

  <CdxMessage v-if="error" type="error">{{ error }}</CdxMessage>
  <CdxProgressBar v-if="loading" aria-label="Loading images" />

  <div v-else-if="images.length === 0" class="card empty">
    <p>Nothing here.</p>
  </div>

  <div v-else class="gallery-grid">
    <figure
      v-for="image in images"
      :key="image.id"
      class="gallery-tile"
      :class="{ 'is-picked': pickedId === image.id }"
    >
      <img :src="image.gridUrl ?? image.thumbUrl" :alt="image.name ?? 'Contest image'" loading="lazy" />

      <div class="gallery-overlay">
        <button
          type="button"
          class="tile-action tile-zoom"
          aria-label="View larger"
          @click="lightbox = image"
        >
          ⤢
        </button>

        <!-- Separate from the lightbox above: this hands the image to the
             single-image flow, where a vote can actually be cast on it,
             rather than just showing it bigger. -->
        <button
          type="button"
          class="tile-action tile-single"
          aria-label="Open in single view"
          @click="pick(image)"
        >
          <CdxIcon :icon="cdxIconFullscreen" />
        </button>

        <div class="tile-votes">
          <template v-if="isYesNo">
            <button
              type="button"
              class="tile-action tile-yes"
              :class="{ on: image.score === 1 }"
              aria-label="Accept"
              :disabled="image.busy"
              @click="vote(image, 1)"
            >
              <CdxIcon :icon="cdxIconCheck" />
            </button>
            <button
              type="button"
              class="tile-action tile-no"
              :class="{ on: image.score === 0 }"
              aria-label="Decline"
              :disabled="image.busy"
              @click="vote(image, 0)"
            >
              <CdxIcon :icon="cdxIconClose" />
            </button>
          </template>

          <template v-else>
            <button
              v-for="star in stars"
              :key="star"
              type="button"
              class="tile-action tile-star"
              :class="{ on: image.score >= star }"
              :aria-label="`Rate ${star}`"
              :disabled="image.busy"
              @click="vote(image, star)"
            >
              ★
            </button>
          </template>
        </div>

        <button
          type="button"
          class="tile-action tile-fav"
          :class="{ on: image.isFavorite }"
          aria-label="Favourite"
          @click="toggleFavorite(image)"
        >
          ♥
        </button>
      </div>
    </figure>
  </div>

  <!-- Lightbox: a closer look without leaving the grid. The details panel
       is what makes this useful for actually judging rather than just
       peeking — the full image and its facts on one screen, without the
       trip out to single-image view. -->
  <div v-if="lightbox" class="lightbox" @click="lightbox = null">
    <img
      class="lightbox-image"
      :src="lightbox.fileUrl ?? lightbox.thumbUrl"
      :alt="lightbox.name ?? 'Contest image'"
    />

    <aside
      v-if="lightboxDetailsOpen"
      class="lightbox-panel"
      @click.stop
    >
      <div class="row lightbox-panel-head">
        <h2 class="vote-filename">{{ lightbox.name ?? 'Contest image' }}</h2>
        <CdxButton
          weight="quiet"
          aria-label="Minimise details"
          title="Minimise details"
          @click="lightboxDetailsOpen = false"
        >
          <CdxIcon :icon="cdxIconCollapse" />
        </CdxButton>
      </div>

      <template v-if="lightbox.width || lightbox.megapixels || lightbox.uploader">
        <h3 class="vote-section">Image</h3>
        <dl class="stat-list">
          <div v-if="lightbox.width">
            <dt>Resolution</dt>
            <dd>{{ lightbox.width }} × {{ lightbox.height }}</dd>
          </div>
          <div v-if="lightbox.megapixels">
            <dt>Megapixels</dt>
            <dd>{{ lightbox.megapixels }} Mpix</dd>
          </div>
          <div v-if="lightbox.uploader">
            <dt>Uploader</dt>
            <dd>{{ lightbox.uploader }}</dd>
          </div>
        </dl>
      </template>

      <template v-if="lightbox.score !== undefined">
        <h3 class="vote-section">Your vote</h3>

        <!-- Voting from the lightbox too, not just naming the current
             state: a juror inspecting the full image with its details is
             in exactly the position to decide, and sending them back to
             the grid to do it is an extra step for no reason. -->
        <div v-if="isYesNo" class="row lightbox-vote">
          <button
            type="button"
            class="tile-action tile-yes lightbox-vote-btn"
            :class="{ on: lightbox.score === 1 }"
            aria-label="Accept"
            :disabled="lightbox.busy"
            @click="vote(lightbox, 1)"
          >
            <CdxIcon :icon="cdxIconCheck" />
          </button>
          <button
            type="button"
            class="tile-action tile-no lightbox-vote-btn"
            :class="{ on: lightbox.score === 0 }"
            aria-label="Decline"
            :disabled="lightbox.busy"
            @click="vote(lightbox, 0)"
          >
            <CdxIcon :icon="cdxIconClose" />
          </button>
        </div>

        <div v-else class="row lightbox-vote">
          <button
            v-for="star in stars"
            :key="star"
            type="button"
            class="tile-action tile-star lightbox-vote-btn"
            :class="{ on: lightbox.score >= star }"
            :aria-label="`Rate ${star}`"
            :disabled="lightbox.busy"
            @click="vote(lightbox, star)"
          >
            ★
          </button>
        </div>

        <p v-if="lightbox.score === null || lightbox.score === undefined" class="muted">
          Not yet judged
        </p>
      </template>

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

    <!-- Only shown once minimised: with the panel open, the collapse
         button inside it already does this job. -->
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
  </div>
</template>
