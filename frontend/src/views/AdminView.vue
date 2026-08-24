<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { cdxIconAdd, cdxIconEdit, cdxIconTrash } from '@wikimedia/codex-icons'
import {
  CdxButton,
  CdxIcon,
  CdxDialog,
  CdxField,
  CdxInfoChip,
  CdxMessage,
  CdxProgressBar,
  CdxSelect,
  CdxTab,
  CdxTabs,
  CdxTextArea,
  CdxTextInput,
} from '@wikimedia/codex'
import DataTable from '@/components/DataTable.vue'
import { api } from '@/api'
import { useSession } from '@/stores/session'
import { formatNumber } from '@/format'

/**
 * Administrator dashboard: overview, user administration, and the audit
 * log of everything people have done in the tool.
 */
const router = useRouter()
const session = useSession()

const tab = ref('overview')
const loading = ref(true)
const busy = ref(false)
const error = ref(null)
const notice = ref(null)

const overview = ref(null)
const users = ref([])
const roles = ref([])
const search = ref('')

const activity = ref([])
const activityTotal = ref(0)
const actionFilter = ref(null)
const knownActions = ref([])

// A generated password is shown once and never retrievable again.
const revealed = ref(null)
const createOpen = ref(false)
const newUser = ref({ username: '', role: 'juror', email: '' })

const roleOptions = computed(() => roles.value.map((r) => ({ label: r.label, value: r.value })))

const actionOptions = computed(() => [
  { label: 'All actions', value: null },
  ...knownActions.value.map((a) => ({ label: a, value: a })),
])

async function loadOverview() {
  overview.value = await api.get('/admin/overview')
}

async function loadUsers() {
  const query = search.value.trim() ? `?q=${encodeURIComponent(search.value.trim())}` : ''
  const data = await api.get(`/admin/users${query}`)

  users.value = data.users
  roles.value = data.roles
}

async function loadActivity() {
  const query = actionFilter.value ? `&action=${encodeURIComponent(actionFilter.value)}` : ''
  const data = await api.get(`/admin/activity?limit=100${query}`)

  activity.value = data.entries
  activityTotal.value = data.total
  knownActions.value = data.actions
}

// --- Structure: projects, campaigns and rounds -------------------------

const projects = ref([])
const campaigns = ref([])
const rounds = ref([])

// Column declarations. The table needs to know a key to sort and search
// on; how a cell looks is still up to a #cell-<key> slot.
const projectColumns = [
  { key: 'name', label: 'Project' },
  { key: 'campaignCount', label: 'Campaigns' },
  { key: 'leadNames', label: 'Leads' },
  { key: 'actions', label: '', sortable: false, align: 'end' },
]

const campaignColumns = [
  { key: 'name', label: 'Campaign' },
  { key: 'projectName', label: 'Project' },
  { key: 'imageCount', label: 'Images' },
  { key: 'actions', label: '', sortable: false, align: 'end' },
]

// The activity log is a feed rather than a table, so it keeps a plain
// search of its own.
const activitySearch = ref('')

const filteredActivity = computed(() => {
  const needle = activitySearch.value.trim().toLowerCase()

  if (needle === '') {
    return activity.value
  }

  return activity.value.filter((entry) =>
    [entry.actor, entry.summary, entry.action, entry.ipAddress].some((field) =>
      String(field ?? '').toLowerCase().includes(needle),
    ),
  )
})

const roundColumns = [
  { key: 'name', label: 'Round' },
  { key: 'campaignName', label: 'Campaign' },
  { key: 'votingMethodLabel', label: 'Method' },
  { key: 'state', label: 'State' },
  { key: 'actions', label: '', sortable: false, align: 'end' },
]

async function loadStructure() {
  const [projectData, campaignData] = await Promise.all([
    api.get('/projects'),
    api.get('/campaigns'),
  ])

  // Flattened for the table, which sorts and searches on plain values.
  projects.value = projectData.projects.map((project) => ({
    ...project,
    leadNames: (project.leads ?? []).join(', '),
  }))
  campaigns.value = campaignData.campaigns

  // There is no admin-wide rounds endpoint — rounds belong to campaigns,
  // so they are gathered from the campaigns just listed.
  const lists = await Promise.all(
    campaigns.value.map(async (campaign) => {
      const data = await api.get(`/campaigns/${campaign.id}`)

      return (data.campaign.rounds ?? []).map((round) => ({
        ...round,
        campaignName: campaign.name,
      }))
    }),
  )

  rounds.value = lists.flat()
}

/**
 * What is about to be deleted, held while the confirmation is open.
 *
 * Deleting cascades: a project takes its campaigns, their rounds, the
 * images imported for them and every vote and comment recorded against
 * those. None of it is recoverable, so the dialog says so in those terms
 * rather than asking a generic "are you sure".
 */
const pendingDelete = ref(null)

/** Inline project editing, since projects have no form view of their own. */
const projectEdit = ref(null)

function openProjectEdit(project) {
  projectEdit.value = {
    id: project.id,
    name: project.name,
    description: project.description ?? '',
  }
}

async function saveProject() {
  const { id, name, description } = projectEdit.value

  busy.value = true
  error.value = null
  notice.value = null

  try {
    await api.patch(`/projects/${id}`, { name, description })
    projectEdit.value = null
    notice.value = `Saved project “${name}”.`
    await loadStructure()
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

async function setCampaignClosed(campaign, closed) {
  busy.value = true
  error.value = null
  notice.value = null

  try {
    await api.patch(`/campaigns/${campaign.id}`, { isClosed: closed })
    notice.value = `${closed ? 'Closed' : 'Reopened'} “${campaign.name}”.`
    await loadStructure()
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

async function setRoundState(round, state) {
  busy.value = true
  error.value = null
  notice.value = null

  try {
    await api.post(`/rounds/${round.id}/state/${state}`)
    notice.value = `“${round.name}” is now ${state}.`
    await loadStructure()
  } catch (e) {
    // Carries the server's reason — an unimported round, or one with no
    // jurors — rather than a generic failure.
    error.value = e.message
  } finally {
    busy.value = false
  }
}

const deleteWarning = computed(() => {
  const kind = pendingDelete.value?.kind

  if (kind === 'project') {
    return 'This also deletes every campaign in the project, their rounds, the images imported for them, and every vote, ranking and comment the jury recorded.'
  }

  if (kind === 'campaign') {
    return 'This also deletes the campaign’s rounds, the images imported for it, and every vote, ranking and comment the jury recorded.'
  }

  // A juror's seat keeps their username as text, so their votes and the
  // tallies stand. Worth saying plainly, because deleting an account that
  // has judged sounds far more destructive than it is.
  if (kind === 'user') {
    return 'Their votes and rankings are kept, still under their username, and the round tallies do not change. Activity log entries keep their text. Block the account instead if you only want to prevent sign-in.'
  }

  return 'This also deletes the images imported into the round and every vote, ranking and comment the jury recorded against them.'
})

async function performDelete() {
  const { kind, item } = pendingDelete.value
  const paths = {
    project: 'projects',
    campaign: 'campaigns',
    round: 'rounds',
    user: 'admin/users',
  }

  busy.value = true
  error.value = null
  notice.value = null

  try {
    await api.delete(`/${paths[kind]}/${item.id}`)
    pendingDelete.value = null
    notice.value = `Deleted ${kind} “${item.name ?? item.username}”.`

    // Everything below the deleted row has gone too, so all lists are
    // reloaded rather than only the one on screen.
    await Promise.all([
      loadStructure(),
      loadOverview(),
      kind === 'user' ? loadUsers() : Promise.resolve(),
    ])
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

onMounted(async () => {
  try {
    await Promise.all([loadOverview(), loadUsers(), loadActivity(), loadStructure()])
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
})

watch(actionFilter, loadActivity)

/** Runs an admin action, refreshing the affected lists afterwards. */
async function run(fn, successMessage) {
  busy.value = true
  error.value = null
  notice.value = null

  try {
    const result = await fn()
    await Promise.all([loadUsers(), loadActivity(), loadOverview()])
    if (successMessage) notice.value = successMessage

    return result
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

function setRole(user, role) {
  run(
    () => api.patch(`/admin/users/${user.id}/role`, { role }),
    `${user.username} is now ${role}.`,
  )
}

function setActive(user, isActive) {
  run(
    () => api.patch(`/admin/users/${user.id}/active`, { isActive }),
    `${user.username} has been ${isActive ? 'unblocked' : 'blocked'}.`,
  )
}

async function resetPassword(user) {
  const data = await run(() => api.post(`/admin/users/${user.id}/password`, {}))

  if (data?.password) {
    revealed.value = { username: user.username, password: data.password }
  }
}

/**
 * The full, scoped picture for one user — every project they lead, every
 * campaign they organize, every round they judge — as opposed to the
 * table's Role column, which is only ever a one-word summary of these.
 */
const rolesDialog = ref(null)
const rolesLoading = ref(false)
const rolesError = ref(null)
const grantForm = ref({ role: 'lead', projectId: null, campaignId: null })

const grantRoleOptions = [
  { label: 'Lead of a project', value: 'lead' },
  { label: 'Organizer of a campaign', value: 'organizer' },
]

async function openRoles(user) {
  rolesDialog.value = { user, grants: [], rounds: [], projects: [], campaigns: [] }
  rolesLoading.value = true
  rolesError.value = null
  grantForm.value = { role: 'lead', projectId: null, campaignId: null }

  try {
    const data = await api.get(`/admin/users/${user.id}/roles`)
    rolesDialog.value = { user, ...data }
  } catch (e) {
    rolesError.value = e.message
  } finally {
    rolesLoading.value = false
  }
}

async function grantScopedRole() {
  const { role, projectId, campaignId } = grantForm.value
  const user = rolesDialog.value.user

  rolesLoading.value = true
  rolesError.value = null

  try {
    const body = role === 'lead' ? { role, projectId } : { role, campaignId }
    const data = await api.post(`/admin/users/${user.id}/roles`, body)

    rolesDialog.value = { ...rolesDialog.value, grants: data.grants }
    grantForm.value = { role: grantForm.value.role, projectId: null, campaignId: null }
    await loadUsers()
  } catch (e) {
    rolesError.value = e.message
  } finally {
    rolesLoading.value = false
  }
}

async function revokeScopedRole(grant) {
  const user = rolesDialog.value.user

  rolesLoading.value = true
  rolesError.value = null

  try {
    const data = await api.delete(`/admin/users/${user.id}/roles/${grant.id}`)
    rolesDialog.value = { ...rolesDialog.value, grants: data.grants }
    await loadUsers()
  } catch (e) {
    rolesError.value = e.message
  } finally {
    rolesLoading.value = false
  }
}

async function createUser() {
  const data = await run(() => api.post('/admin/users', newUser.value))

  if (data?.password) {
    revealed.value = { username: data.user.username, password: data.password }
  }

  createOpen.value = false
  newUser.value = { username: '', role: 'juror', email: '' }
}
</script>

<template>
  <div class="page-head">
    <div>
      <h1 class="page-title">Administration</h1>
      <p class="page-subtitle">Users, access and activity across the tool</p>
    </div>
    <CdxButton @click="router.push({ name: 'campaigns' })">Campaigns</CdxButton>
  </div>

  <CdxMessage v-if="error" type="error">{{ error }}</CdxMessage>
  <CdxMessage v-if="notice" type="success">{{ notice }}</CdxMessage>

  <CdxMessage v-if="revealed" type="warning" :dismiss-button-label="'Dismiss'" @user-dismissed="revealed = null">
    <strong>Password for {{ revealed.username }}:</strong>
    <code class="revealed-password">{{ revealed.password }}</code><br />
    Copy it now — it is stored only as a hash and cannot be shown again.
  </CdxMessage>

  <CdxProgressBar v-if="loading" aria-label="Loading dashboard" />

  <template v-else>
    <CdxTabs v-model:active="tab" framed style="margin-bottom: 1rem">
      <CdxTab name="overview" label="Overview" />
      <CdxTab name="users" :label="`Users (${users.length})`" />
      <CdxTab name="projects" :label="`Projects (${projects.length})`" />
      <CdxTab name="campaigns" :label="`Campaigns (${campaigns.length})`" />
      <CdxTab name="rounds" :label="`Rounds (${rounds.length})`" />
      <CdxTab name="activity" :label="`Activity (${activityTotal})`" />
    </CdxTabs>

    <!-- Overview -->
    <template v-if="tab === 'overview' && overview">
      <div class="stat-grid">
        <div class="card stat-card">
          <span class="stat-value">{{ formatNumber(overview.users) }}</span>
          <span class="muted">Users</span>
        </div>
        <div class="card stat-card">
          <span class="stat-value">{{ formatNumber(overview.campaigns) }}</span>
          <span class="muted">Campaigns</span>
        </div>
        <div class="card stat-card">
          <span class="stat-value">{{ formatNumber(overview.rounds) }}</span>
          <span class="muted">Rounds</span>
        </div>
        <div class="card stat-card">
          <span class="stat-value">{{ formatNumber(overview.images) }}</span>
          <span class="muted">Images</span>
        </div>
        <div class="card stat-card">
          <span class="stat-value">{{ formatNumber(overview.votes) }}</span>
          <span class="muted">Votes cast</span>
        </div>
        <div class="card stat-card">
          <span class="stat-value">{{ formatNumber(overview.blockedUsers) }}</span>
          <span class="muted">Blocked</span>
        </div>
      </div>

      <div class="grid-2" style="margin-top: 1.5rem">
        <div class="card">
          <h2 class="section-title">Users by role</h2>
          <dl class="stat-list">
            <div v-for="(count, role) in overview.usersByRole" :key="role">
              <dt style="text-transform: capitalize">{{ role }}</dt>
              <dd>{{ formatNumber(count) }}</dd>
            </div>
          </dl>
        </div>

        <div class="card">
          <h2 class="section-title">Recent activity</h2>
          <div v-for="entry in overview.recentActivity" :key="entry.id" class="activity-row">
            <strong>{{ entry.actor }}</strong>
            <span>{{ entry.summary }}</span>
          </div>
        </div>
      </div>
    </template>

    <!-- Users -->
    <template v-else-if="tab === 'users'">
      <div class="row" style="margin-bottom: 1rem">
        <CdxTextInput
          v-model="search"
          placeholder="Search usernames…"
          style="max-width: 20rem"
          @keydown.enter="loadUsers"
        />
        <CdxButton @click="loadUsers">Search</CdxButton>
        <span class="spacer"></span>
        <CdxButton action="progressive" weight="primary" @click="createOpen = true">
          New local account
        </CdxButton>
      </div>

      <div class="card table-scroll">
        <table>
          <thead>
            <tr>
              <th>Username</th>
              <th>Role</th>
              <th>Login</th>
              <th>Seats</th>
              <th>Last seen</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="user in users" :key="user.id" :class="{ 'row-blocked': !user.isActive }">
              <td>
                <strong>{{ user.username }}</strong>
                <div v-if="user.email" class="muted" style="font-size: 0.8125rem">
                  {{ user.email }}
                </div>
              </td>
              <td style="min-width: 11rem">
                <CdxSelect
                  :selected="user.role"
                  :menu-items="roleOptions"
                  :disabled="busy"
                  @update:selected="setRole(user, $event)"
                />
                <!-- The dropdown only ever sets the summary column, which
                     can drift from what the user is actually granted — this
                     is what shows the real, scoped picture, and lets it be
                     corrected. -->
                <a href="#" class="role-scope-link" @click.prevent="openRoles(user)">
                  Which project or campaign?
                </a>
              </td>
              <td>
                <!-- Three states, not two: an account that is neither linked
                     to Wikimedia nor holds a password cannot sign in at all,
                     and calling it "local" hid that. -->
                <CdxInfoChip v-if="user.isWikimediaLinked">Wikimedia</CdxInfoChip>
                <CdxInfoChip v-else-if="user.hasLocalPassword">local</CdxInfoChip>
                <CdxInfoChip v-else status="warning">no sign-in</CdxInfoChip>
              </td>
              <td>{{ user.jurorSeats }}</td>
              <td class="muted">
                {{ user.lastLoginAt ? new Date(user.lastLoginAt).toLocaleDateString() : 'never' }}
              </td>
              <td>
                <CdxInfoChip :status="user.isActive ? 'success' : 'error'">
                  {{ user.isActive ? 'active' : 'blocked' }}
                </CdxInfoChip>
              </td>
              <td>
                <div class="row" style="gap: 0.25rem; justify-content: flex-end">
                  <!-- Offered whether or not the account already has a
                       password: the endpoint sets one either way, and
                       gating on hasLocalPassword left an account that
                       never had one with no way to be given one. -->
                  <CdxButton
                    weight="quiet"
                    :disabled="busy"
                    @click="resetPassword(user)"
                  >
                    {{ user.hasLocalPassword ? 'Reset password' : 'Set password' }}
                  </CdxButton>
                  <CdxButton
                    weight="quiet"
                    :action="user.isActive ? 'destructive' : 'progressive'"
                    :disabled="busy || user.id === session.user.id"
                    @click="setActive(user, !user.isActive)"
                  >
                    {{ user.isActive ? 'Block' : 'Unblock' }}
                  </CdxButton>
                  <!-- Deleting your own account is refused by the server
                       too; disabling it here keeps the row honest. -->
                  <CdxButton
                    weight="quiet"
                    action="destructive"
                    :disabled="busy || user.id === session.user.id"
                    @click="pendingDelete = { kind: 'user', item: user }"
                  >
                    <CdxIcon :icon="cdxIconTrash" /> Delete
                  </CdxButton>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- Projects -->
    <template v-else-if="tab === 'projects'">
      <DataTable
        :columns="projectColumns"
        :rows="projects"
        search-placeholder="Search projects…"
        empty-text="No projects yet."
      >
        <template #cell-name="{ row }">
          <a href="#" @click.prevent="router.push({ name: 'project', params: { id: row.id } })">
            {{ row.name }}
          </a>
        </template>
        <template #cell-campaignCount="{ row }">
          {{ formatNumber(row.campaignCount ?? 0) }}
        </template>
        <template #cell-leadNames="{ row }">
          {{ row.leadNames || '—' }}
        </template>
        <template #cell-actions="{ row }">
          <div class="row" style="gap: 0.25rem; justify-content: flex-end">
            <!-- PATCH /projects/{id} has always existed with nothing in the
                 interface calling it, so a project's name and description
                 could not be corrected once created. -->
            <CdxButton weight="quiet" :disabled="busy" @click="openProjectEdit(row)">
              <CdxIcon :icon="cdxIconEdit" /> Edit
            </CdxButton>
            <CdxButton
              action="destructive"
              weight="quiet"
              :disabled="busy"
              @click="pendingDelete = { kind: 'project', item: row }"
            >
              <CdxIcon :icon="cdxIconTrash" /> Delete
            </CdxButton>
          </div>
        </template>
      </DataTable>
    </template>

    <!-- Campaigns -->
    <template v-else-if="tab === 'campaigns'">
      <DataTable
        :columns="campaignColumns"
        :rows="campaigns"
        search-placeholder="Search campaigns…"
        empty-text="No campaigns yet."
      >
        <template #cell-name="{ row }">
          <a href="#" @click.prevent="router.push({ name: 'campaign', params: { id: row.id } })">
            {{ row.name }}
          </a>
        </template>
        <template #cell-imageCount="{ row }">
          {{ formatNumber(row.imageCount ?? 0) }}
        </template>
        <template #cell-actions="{ row }">
          <div class="row" style="gap: 0.25rem; justify-content: flex-end">
            <CdxButton
              weight="quiet"
              :disabled="busy"
              @click="router.push({ name: 'campaign-edit', params: { id: row.id } })"
            >
              <CdxIcon :icon="cdxIconEdit" /> Edit
            </CdxButton>
            <!-- Closing stops a campaign accepting new work without
                 deleting anything, which is what most "finished" campaigns
                 actually want. -->
            <CdxButton weight="quiet" :disabled="busy" @click="setCampaignClosed(row, !row.isClosed)">
              {{ row.isClosed ? 'Reopen' : 'Close' }}
            </CdxButton>
            <CdxButton
              action="destructive"
              weight="quiet"
              :disabled="busy"
              @click="pendingDelete = { kind: 'campaign', item: row }"
            >
              <CdxIcon :icon="cdxIconTrash" /> Delete
            </CdxButton>
          </div>
        </template>
      </DataTable>
    </template>

    <!-- Rounds -->
    <template v-else-if="tab === 'rounds'">
      <DataTable
        :columns="roundColumns"
        :rows="rounds"
        search-placeholder="Search rounds…"
        empty-text="No rounds yet."
      >
        <template #cell-name="{ row }">
          <a href="#" @click.prevent="router.push({ name: 'round', params: { id: row.id } })">
            {{ row.name }}
          </a>
        </template>
        <template #cell-state="{ row }">
          <CdxInfoChip :status="row.state === 'active' ? 'success' : 'notice'">
            {{ row.state }}
          </CdxInfoChip>
        </template>
        <template #cell-actions="{ row }">
          <div class="row" style="gap: 0.25rem; justify-content: flex-end">
            <CdxButton
              weight="quiet"
              :disabled="busy"
              @click="router.push({ name: 'round-edit', params: { id: row.id } })"
            >
              <CdxIcon :icon="cdxIconEdit" /> Edit
            </CdxButton>
            <!-- Activation can fail for reasons this table cannot see — no
                 images, no jurors — so the server's message is surfaced
                 rather than the button being hidden on a guess. -->
            <CdxButton
              v-if="row.state === 'draft' || row.state === 'paused'"
              weight="quiet"
              action="progressive"
              :disabled="busy"
              @click="setRoundState(row, 'active')"
            >
              Activate
            </CdxButton>
            <CdxButton
              v-else-if="row.state === 'active'"
              weight="quiet"
              :disabled="busy"
              @click="setRoundState(row, 'paused')"
            >
              Pause
            </CdxButton>
            <CdxButton
              action="destructive"
              weight="quiet"
              :disabled="busy"
              @click="pendingDelete = { kind: 'round', item: row }"
            >
              <CdxIcon :icon="cdxIconTrash" /> Delete
            </CdxButton>
          </div>
        </template>
      </DataTable>
    </template>

    <!-- Activity log -->
    <template v-else>
      <div class="row" style="margin-bottom: 1rem">
        <CdxTextInput
          v-model="activitySearch"
          placeholder="Search the log…"
          style="max-width: 22rem"
        />
        <CdxSelect
          v-model:selected="actionFilter"
          :menu-items="actionOptions"
          style="max-width: 18rem"
        />
        <span class="spacer"></span>
        <span class="muted">
          {{ formatNumber(filteredActivity.length) }} of
          {{ formatNumber(activityTotal) }} entries
        </span>
      </div>

      <!-- A feed rather than a table. The five columns repeated
           themselves — the action code says the same thing as the summary
           beside it — and gave an IP address the same weight as what
           actually happened. -->
      <div v-if="filteredActivity.length === 0" class="card empty">
        <p>Nothing matches.</p>
      </div>

      <div v-else class="card feed">
        <div v-for="entry in filteredActivity" :key="entry.id" class="feed-row">
          <div class="feed-main">
            <span class="feed-actor">{{ entry.actor }}</span>
            {{ entry.summary }}
          </div>
          <div class="feed-meta">
            <span :title="entry.ipAddress ? `from ${entry.ipAddress}` : ''">
              {{ new Date(entry.createdAt).toLocaleString() }}
            </span>
            <code class="action-code">{{ entry.action }}</code>
          </div>
        </div>
      </div>
    </template>
  </template>

  <CdxDialog
    v-model:open="createOpen"
    title="New local account"
    subtitle="For people who cannot sign in with Wikimedia OAuth"
    :primary-action="{
      label: 'Create account',
      actionType: 'progressive',
      disabled: busy || !newUser.username.trim(),
    }"
    :default-action="{ label: 'Cancel' }"
    @primary="createUser"
    @default="createOpen = false"
  >
    <CdxField>
      <template #label>Wikimedia username</template>
      <CdxTextInput v-model="newUser.username" />
    </CdxField>

    <CdxField>
      <template #label>Role</template>
      <CdxSelect v-model:selected="newUser.role" :menu-items="roleOptions" />
    </CdxField>

    <CdxField>
      <template #label>Email</template>
      <template #description>Optional.</template>
      <CdxTextInput v-model="newUser.email" input-type="email" />
    </CdxField>

    <CdxMessage type="notice" inline>
      A strong password will be generated and shown to you once.
    </CdxMessage>
  </CdxDialog>

  <CdxDialog
    :open="projectEdit !== null"
    title="Edit project"
    :primary-action="{ label: 'Save', actionType: 'progressive', disabled: busy }"
    :default-action="{ label: 'Cancel' }"
    @primary="saveProject"
    @default="projectEdit = null"
    @update:open="(open) => { if (!open) projectEdit = null }"
  >
    <!-- Guarded: the dialog body still renders while closed, and
         projectEdit is null then. -->
    <template v-if="projectEdit">
      <CdxField>
        <template #label>Name</template>
        <CdxTextInput v-model="projectEdit.name" />
      </CdxField>

      <CdxField>
        <template #label>Description</template>
        <CdxTextArea v-model="projectEdit.description" rows="3" />
      </CdxField>
    </template>
  </CdxDialog>

  <!-- Deletion cascades, so the dialog names what else goes rather than
       asking a generic "are you sure". -->
  <CdxDialog
    :open="pendingDelete !== null"
    :title="`Delete ${pendingDelete?.kind}?`"
    :primary-action="{
      label: 'Delete permanently',
      actionType: 'destructive',
      disabled: busy,
    }"
    :default-action="{ label: 'Cancel' }"
    @primary="performDelete"
    @default="pendingDelete = null"
    @update:open="(open) => { if (!open) pendingDelete = null }"
  >
    <p style="margin-top: 0">
      <!-- Users are named by username; everything else by name. -->
      <strong>{{ pendingDelete?.item?.name ?? pendingDelete?.item?.username }}</strong>
    </p>

    <CdxMessage type="warning" inline>
      {{ deleteWarning }} This cannot be undone.
    </CdxMessage>
  </CdxDialog>

  <!-- The scoped picture the Role column can only summarise: exactly
       which project, campaign or round, and a way to correct it — grant
       a scoped role, or revoke one that no longer applies. -->
  <CdxDialog
    :open="rolesDialog !== null"
    :title="rolesDialog ? `${rolesDialog.user.username}'s roles` : ''"
    :use-close-button="true"
    @update:open="(open) => { if (!open) rolesDialog = null }"
  >
    <template v-if="rolesDialog">
      <CdxMessage v-if="rolesError" type="error" inline>{{ rolesError }}</CdxMessage>
      <CdxProgressBar v-if="rolesLoading && rolesDialog.grants.length === 0 && rolesDialog.rounds.length === 0" aria-label="Loading" />

      <template v-if="rolesDialog.grants.length">
        <h3 class="vote-section" style="margin-top: 0">Leads and organizes</h3>
        <ul class="role-grant-list">
          <li v-for="grant in rolesDialog.grants" :key="grant.id ?? grant.role" class="row">
            <span>
              <strong>{{ grant.roleLabel }}</strong>
              of {{ grant.scope }}
            </span>
            <span class="spacer"></span>
            <!-- Admin has no scope to revoke here — it is set from the
                 table's dropdown, same as it always was. -->
            <CdxButton
              v-if="grant.id"
              weight="quiet"
              action="destructive"
              size="small"
              :disabled="rolesLoading"
              @click="revokeScopedRole(grant)"
            >
              Remove
            </CdxButton>
          </li>
        </ul>
      </template>
      <p v-else-if="!rolesLoading" class="muted">No scoped grants — just the role shown in the table.</p>

      <template v-if="rolesDialog.rounds.length">
        <h3 class="vote-section">Judges</h3>
        <ul class="role-grant-list">
          <li v-for="seat in rolesDialog.rounds" :key="seat.jurorId" class="row">
            <span>
              {{ seat.roundName }}
              <span class="muted">({{ seat.campaignName }}, {{ seat.state }})</span>
            </span>
          </li>
        </ul>
      </template>

      <h3 class="vote-section">Grant a role</h3>
      <div class="row" style="gap: 0.5rem; align-items: flex-end; flex-wrap: wrap">
        <CdxField style="min-width: 12rem">
          <template #label>Role</template>
          <CdxSelect v-model:selected="grantForm.role" :menu-items="grantRoleOptions" />
        </CdxField>

        <CdxField v-if="grantForm.role === 'lead'" style="min-width: 14rem">
          <template #label>Project</template>
          <CdxSelect
            v-model:selected="grantForm.projectId"
            :menu-items="rolesDialog.projects.map((p) => ({ label: p.name, value: p.id }))"
          />
        </CdxField>

        <CdxField v-else style="min-width: 14rem">
          <template #label>Campaign</template>
          <CdxSelect
            v-model:selected="grantForm.campaignId"
            :menu-items="rolesDialog.campaigns.map((c) => ({ label: `${c.name} (${c.projectName})`, value: c.id }))"
          />
        </CdxField>

        <CdxButton
          action="progressive"
          weight="primary"
          :disabled="rolesLoading || (grantForm.role === 'lead' ? !grantForm.projectId : !grantForm.campaignId)"
          @click="grantScopedRole"
        >
          Grant
        </CdxButton>
      </div>
    </template>
  </CdxDialog>
</template>
