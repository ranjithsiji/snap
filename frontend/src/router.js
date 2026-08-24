import { createRouter, createWebHistory } from 'vue-router'
import { useSession } from '@/stores/session'

const routes = [
  // Public: someone arriving at the tool should be told what it is, not
  // asked to log in before they can find out.
  {
    path: '/',
    name: 'home',
    component: () => import('@/views/LandingView.vue'),
    meta: { public: true },
  },
  {
    path: '/my-rounds',
    name: 'my-rounds',
    component: () => import('@/views/HomeView.vue'),
  },
  { path: '/login', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { public: true } },

  // Public: describes the tool to people who have not logged in, and is
  // where the Toolforge tool listing points.
  { path: '/about', name: 'about', component: () => import('@/views/AboutView.vue'), meta: { public: true } },

  // Organizer (read) and administrator (write)
  {
    path: '/projects',
    name: 'projects',
    component: () => import('@/views/ProjectListView.vue'),
  },
  {
    path: '/projects/:id',
    name: 'project',
    component: () => import('@/views/ProjectView.vue'),
    props: true,
  },
  {
    path: '/campaigns',
    name: 'campaigns',
    component: () => import('@/views/CampaignListView.vue'),
    meta: { role: 'organizer' },
  },
  {
    path: '/campaigns/new',
    name: 'campaign-new',
    component: () => import('@/views/CampaignFormView.vue'),
    // A lead runs the campaigns inside their project, so they create them
    // too. This said 'administrator', which was stricter than the API has
    // ever been and hid the page from the people who use it most.
    meta: { role: 'lead' },
  },
  {
    path: '/campaigns/:id',
    name: 'campaign',
    component: () => import('@/views/CampaignView.vue'),
    props: true,
    meta: { role: 'organizer' },
  },
  {
    // Creating a campaign is an lead's job and running it
    // one needs to correct its name or source without waiting on them.
    path: '/campaigns/:id/edit',
    name: 'campaign-edit',
    component: () => import('@/views/CampaignFormView.vue'),
    props: true,
    meta: { role: 'lead' },
  },
  {
    path: '/campaigns/:campaignId/rounds/new',
    name: 'round-new',
    component: () => import('@/views/RoundFormView.vue'),
    props: true,
    meta: { role: 'organizer' },
  },
  {
    path: '/rounds/:id',
    name: 'round',
    component: () => import('@/views/RoundView.vue'),
    props: true,
    meta: { role: 'organizer' },
  },
  {
    path: '/rounds/:id/edit',
    name: 'round-edit',
    component: () => import('@/views/RoundFormView.vue'),
    props: true,
    meta: { role: 'organizer' },
  },
  {
    path: '/rounds/:id/results',
    name: 'round-results',
    component: () => import('@/views/ResultsView.vue'),
    props: true,
    meta: { role: 'organizer' },
  },

  {
    path: '/admin',
    name: 'admin',
    component: () => import('@/views/AdminView.vue'),
    meta: { role: 'administrator' },
  },

  // Juror
  {
    path: '/meetings/:id',
    name: 'meeting',
    component: () => import('@/views/MeetingView.vue'),
    props: true,
  },
  {
    path: '/vote/:id',
    name: 'vote',
    component: () => import('@/views/VoteView.vue'),
    props: true,
  },

  { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/views/NotFoundView.vue'), meta: { public: true } },
]

export const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach(async (to) => {
  const session = useSession()

  // The session is resolved once, on first navigation.
  if (!session.loaded) {
    await session.load()
  }

  if (to.meta.public) {
    return true
  }

  if (!session.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  // Keyed to what the store actually exposes. These previously tested
  // session.isAdmin and session.isCoordinator against meta.role values of
  // 'administrator' and 'organizer' — neither the properties nor the
  // values matched, so every role check passed and the admin pages were
  // reachable by any signed-in user. The server refuses the underlying
  // requests regardless; this keeps the menu honest.
  const guards = {
    administrator: session.isAdministrator,
    lead: session.isLead,
    organizer: session.isOrganizer,
    jury: session.isJuror,
  }

  if (to.meta.role !== undefined && guards[to.meta.role] === false) {
    return { name: 'my-rounds' }
  }

  return true
})
