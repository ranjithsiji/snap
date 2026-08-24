<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import {
  CdxButton,
  CdxField,
  CdxIcon,
  CdxMessage,
  CdxProgressBar,
  CdxTextArea,
  CdxTextInput,
} from '@wikimedia/codex'
import { cdxIconDownload, cdxIconLink, cdxIconNext, cdxIconPrevious } from '@wikimedia/codex-icons'
import { api } from '@/api'
import { useSession } from '@/stores/session'

/**
 * One image from the jury meeting, full screen: a film strip to move
 * between images, and everything the panel said about whichever one is
 * open — every juror's proposed position and their written remarks.
 *
 * MeetingView's own tabs cover the shared agreed ranking and the general
 * discussion; this is for looking hard at one photograph on its own,
 * which is what a coordinator needs to actually decide how many images
 * go to the jury, or to check a specific file for a copyright problem
 * before it goes further.
 */
const props = defineProps({
  id: { type: String, required: true },
  imageId: { type: String, required: true },
})

const router = useRouter()
const session = useSession()

const round = ref(null)
const images = ref([])
const loading = ref(true)
const error = ref(null)
const isFinalized = ref(false)

const comments = ref([])
const commentsLoading = ref(false)
const newComment = ref('')
const posting = ref(false)

// The comment goes with the reposition itself: this is the field that
// answers "why did you move it here", asked at the moment the number
// changes rather than in the general thread where the reason for a
// specific move would be lost among everything else.
const myNewPosition = ref('')
const repositionComment = ref('')
const repositioning = ref(false)

const current = computed(
  () => images.value.find((image) => String(image.imageId) === String(props.imageId)) ?? null,
)

const currentIndex = computed(() =>
  images.value.findIndex((image) => String(image.imageId) === String(props.imageId)),
)

// The film strip pages rather than rendering every image in the meeting
// at once — a meeting can carry over hundreds of images if left uncapped,
// and a strip that size is unusable to scan and heavy enough to hang the
// page. Prev/Next still walk the full ordered list, independent of which
// page the strip happens to be showing.
const FILMSTRIP_PAGE_SIZE = 25
const filmstripPage = ref(0)
const filmstripPageCount = computed(() =>
  Math.max(1, Math.ceil(images.value.length / FILMSTRIP_PAGE_SIZE)),
)
const filmstripImages = computed(() => {
  const start = filmstripPage.value * FILMSTRIP_PAGE_SIZE

  return images.value.slice(start, start + FILMSTRIP_PAGE_SIZE)
})

/** Jumps the strip to whichever page holds the open image. */
function showCurrentPage() {
  if (currentIndex.value >= 0) {
    filmstripPage.value = Math.floor(currentIndex.value / FILMSTRIP_PAGE_SIZE)
  }
}

const previousImage = computed(() =>
  currentIndex.value > 0 ? images.value[currentIndex.value - 1] : null,
)

const nextImage = computed(() =>
  currentIndex.value >= 0 && currentIndex.value < images.value.length - 1
    ? images.value[currentIndex.value + 1]
    : null,
)

/** This juror's own proposed position for the open image, if they made one. */
const myPosition = computed(() => {
  if (!current.value) return null

  const mine = current.value.proposals.find((p) => p.juror === session.user?.username)

  return mine ? mine.position : null
})

watch(
  current,
  (image) => {
    myNewPosition.value = image ? String(myPosition.value ?? image.position) : ''
    repositionComment.value = ''
  },
  { immediate: true },
)

async function loadImages() {
  loading.value = true
  error.value = null

  try {
    const [detail, matrix] = await Promise.all([
      api.get(`/meetings/${props.id}`),
      api.get(`/meetings/${props.id}/proposals`),
    ])

    round.value = detail.round
    isFinalized.value = detail.isFinalized
    images.value = matrix.images
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

/**
 * Moves just this image to the typed position and resubmits the whole
 * ranking — propose() takes every image at once, so a single-image move
 * means starting from this juror's current full order (their existing
 * proposals if they have any, otherwise the agreed order), relocating
 * this one image, and sending the complete list back. The comment is
 * posted alongside it, as the record of why the move was made.
 */
async function submitReposition() {
  const requested = Number(myNewPosition.value)

  if (!Number.isInteger(requested) || requested < 1) {
    error.value = 'Enter a whole number position of at least 1.'
    return
  }

  repositioning.value = true
  error.value = null

  try {
    const target = Math.min(requested, images.value.length)

    const withMine = images.value.map((image) => {
      const mine = image.proposals.find((p) => p.juror === session.user?.username)

      return { imageId: image.imageId, position: mine ? mine.position : image.position }
    })

    const others = withMine
      .filter((entry) => String(entry.imageId) !== String(current.value.imageId))
      .sort((a, b) => a.position - b.position)

    others.splice(target - 1, 0, { imageId: current.value.imageId })

    const order = others.map((entry) => entry.imageId)

    const data = await api.post(`/meetings/${props.id}/proposals`, { order })
    images.value = data.images

    if (repositionComment.value.trim()) {
      await api.post(`/meetings/${props.id}/images/${props.imageId}/comments`, {
        body: repositionComment.value.trim(),
      })
      repositionComment.value = ''
      await loadComments()
    }
  } catch (e) {
    error.value = e.message
  } finally {
    repositioning.value = false
  }
}

async function loadComments() {
  if (!current.value) return

  commentsLoading.value = true

  try {
    const data = await api.get(`/meetings/${props.id}/images/${props.imageId}/comments`)
    comments.value = data.comments
  } catch (e) {
    error.value = e.message
  } finally {
    commentsLoading.value = false
  }
}

onMounted(async () => {
  await loadImages()
  showCurrentPage()
  await loadComments()
})

// Re-fetches comments when the film strip moves to a different image,
// rather than loading them all up front — a meeting can run to hundreds
// of images, and only one is ever being read at a time. Also keeps the
// strip's own page following wherever Prev/Next lands, so the open
// image is never on a page the strip is not showing.
watch(() => props.imageId, () => {
  showCurrentPage()
  loadComments()
})

function goTo(image) {
  if (image) {
    router.push({ name: 'meeting-image', params: { id: props.id, imageId: image.imageId } })
  }
}

async function postComment() {
  if (!newComment.value.trim()) return

  posting.value = true
  error.value = null

  try {
    await api.post(`/meetings/${props.id}/images/${props.imageId}/comments`, {
      body: newComment.value.trim(),
    })
    newComment.value = ''
    await loadComments()
  } catch (e) {
    error.value = e.message
  } finally {
    posting.value = false
  }
}
</script>

<template>
  <CdxProgressBar v-if="loading" aria-label="Loading meeting" />

  <CdxMessage v-else-if="error" type="error">{{ error }}</CdxMessage>

  <div v-else-if="current" class="meeting-image-screen">
    <!-- Film strip, 25 at a time — every image in the meeting rendered at
         once is what actually hung the page when a meeting was left
         uncapped; paging keeps the strip usable regardless of how big
         the meeting turned out. Prev/Next below still walk the full
         ordered list and jump the strip to whichever page holds the
         result. -->
    <div class="meeting-filmstrip">
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
        :class="{ 'is-current': String(image.imageId) === String(imageId) }"
        @click="goTo(image)"
      >
        <img :src="image.thumbUrl" :alt="image.title" loading="lazy" />
        <span class="filmstrip-position">#{{ image.position }}</span>
      </button>
    </div>

    <div class="meeting-image-main">
      <div class="meeting-image-toolbar">
        <CdxButton weight="quiet" @click="router.push({ name: 'meeting', params: { id } })">
          Back to meeting
        </CdxButton>
        <span class="spacer"></span>
        <CdxButton weight="quiet" :disabled="!previousImage" @click="goTo(previousImage)">
          <CdxIcon :icon="cdxIconPrevious" /> Previous
        </CdxButton>
        <span class="muted">{{ currentIndex + 1 }} of {{ images.length }}</span>
        <CdxButton weight="quiet" :disabled="!nextImage" @click="goTo(nextImage)">
          Next <CdxIcon :icon="cdxIconNext" />
        </CdxButton>
      </div>

      <div class="meeting-image-frame">
        <img :src="current.fileUrl ?? current.thumbUrl" :alt="current.title" />
      </div>

      <div class="row wrap meeting-image-links">
        <a :href="current.fileUrl" target="_blank" rel="noopener" class="row" style="gap: 0.25rem">
          <CdxIcon :icon="cdxIconDownload" /> Full resolution
          <span v-if="current.width" class="muted">
            ({{ current.width }} × {{ current.height }})
          </span>
        </a>
        <a
          v-if="current.descriptionUrl"
          :href="current.descriptionUrl"
          target="_blank"
          rel="noopener"
          class="row"
          style="gap: 0.25rem"
        >
          <CdxIcon :icon="cdxIconLink" /> Commons page
        </a>
      </div>

      <h2 class="meeting-image-title">{{ current.title }}</h2>
    </div>

    <aside class="meeting-image-panel">
      <h3 class="vote-section" style="margin-top: 0">Proposed positions</h3>
      <ul class="role-grant-list">
        <li
          v-for="proposal in current.proposals.filter((p) => p.juror !== session.user?.username)"
          :key="proposal.juror"
          class="row"
        >
          <strong>{{ proposal.juror }}</strong>
          <span class="spacer"></span>
          <span>#{{ proposal.position }}</span>
        </li>
        <li v-if="current.proposals.length === 0" class="muted">
          No one has proposed a position for this image yet.
        </li>
      </ul>

      <!-- Repositioning just this image resubmits the juror's whole
           ranking under the hood — propose() takes every image at
           once — but only asks for the one number here, plus a place
           to say why the move was made. -->
      <template v-if="!isFinalized">
        <h3 class="vote-section">Your position</h3>
        <div class="row" style="gap: 0.5rem; align-items: flex-end; flex-wrap: wrap">
          <CdxField style="margin: 0">
            <template #label>Position</template>
            <CdxTextInput v-model="myNewPosition" input-type="number" min="1" style="width: 6rem" />
          </CdxField>
        </div>

        <CdxTextArea
          v-model="repositionComment"
          rows="2"
          placeholder="Why does this image belong here? (optional, posted as a comment)"
          style="margin-top: 0.5rem"
        />

        <div class="row row-end" style="margin-top: 0.5rem">
          <CdxButton
            action="progressive"
            weight="primary"
            :disabled="repositioning || !myNewPosition"
            @click="submitReposition"
          >
            {{ repositioning ? 'Saving…' : 'Save position' }}
          </CdxButton>
        </div>
      </template>

      <h3 class="vote-section">Comments</h3>
      <CdxProgressBar v-if="commentsLoading" aria-label="Loading comments" />

      <div v-else class="meeting-comment-list">
        <div v-for="comment in comments" :key="comment.id" class="meeting-comment">
          <strong>{{ comment.author }}</strong>
          <span class="muted meeting-comment-time">
            {{ new Date(comment.createdAt).toLocaleString() }}
          </span>
          <p style="margin: 0.25rem 0 0; white-space: pre-wrap">{{ comment.body }}</p>
        </div>

        <p v-if="comments.length === 0" class="muted">No comments on this image yet.</p>
      </div>

      <CdxTextArea
        v-model="newComment"
        rows="3"
        placeholder="Say what you think of this image…"
        style="margin-top: 0.75rem"
      />
      <div class="row row-end" style="margin-top: 0.5rem">
        <CdxButton
          action="progressive"
          weight="primary"
          :disabled="posting || !newComment.trim()"
          @click="postComment"
        >
          {{ posting ? 'Posting…' : 'Post comment' }}
        </CdxButton>
      </div>
    </aside>
  </div>
</template>
