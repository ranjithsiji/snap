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
    meta: { role: 'administrator' },
  },
  {
    path: '/campaigns/:id',
    name: 'campaign',
    component: () => import('@/views/CampaignView.vue'),
    props: true,
    meta: { role: 'organizer' },
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

  if (to.meta.role === 'admin' && !session.isAdmin) {
    return { name: 'home' }
  }

  if (to.meta.role === 'coordinator' && !session.isCoordinator) {
    return { name: 'home' }
  }

  return true
})
