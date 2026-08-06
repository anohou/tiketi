<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import HelpScreenshot from '@/Components/HelpScreenshot.vue';
import {
  getHelpTopicsForRole,
  helpLevels,
  helpRoleLabels,
} from '@/Support/helpContent.js';
import Magnify from 'vue-material-design-icons/Magnify.vue';
import BookOpenVariant from 'vue-material-design-icons/BookOpenVariant.vue';
import BookOpenPageVariant from 'vue-material-design-icons/BookOpenPageVariant.vue';
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue';
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue';
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue';
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue';
import Close from 'vue-material-design-icons/Close.vue';
import Menu from 'vue-material-design-icons/Menu.vue';
import ZoomIn from 'vue-material-design-icons/MagnifyExpand.vue';
import ChevronRightSmall from 'vue-material-design-icons/ChevronRight.vue';

const props = defineProps({
  public: {
    type: Boolean,
    default: false,
  },
});

const page = usePage();
const user = page.props.auth.user || null;

// Rôles proposés aux visiteurs de la documentation publique
const publicRoles = [
  { value: 'all', label: 'Tous' },
  { value: 'admin', label: 'Administrateur' },
  { value: 'supervisor', label: 'Superviseur' },
  { value: 'seller', label: 'Vendeur' },
  { value: 'accountant', label: 'Comptable' },
  { value: 'executive', label: 'Direction' },
  { value: 'fleet_manager', label: 'Flotte' },
];

const selectedRole = ref(props.public ? 'all' : (user?.role || 'all'));
const effectiveRole = computed(() => (props.public ? selectedRole.value : user?.role || 'all'));

const search = ref('');
const completedTopicIds = ref([]);
const drawerOpen = ref(false);
const lightboxSrc = ref('');

const topics = computed(() => getHelpTopicsForRole(effectiveRole.value));
const requestedTopicId = typeof window !== 'undefined'
  ? new URLSearchParams(window.location.search).get('topic')
  : null;
const selectedTopicId = ref(
  topics.value.some((topic) => topic.id === requestedTopicId)
    ? requestedTopicId
    : topics.value[0]?.id || null,
);

const roleLabel = computed(() => {
  if (props.public) {
    return publicRoles.find((r) => r.value === selectedRole.value)?.label || 'Tous';
  }
  return helpRoleLabels[user?.role] || 'Utilisateur';
});

const rolePromises = {
  admin: 'Configurez l’entreprise, sécurisez les accès et accompagnez l’exploitation.',
  fleet_manager: 'Préparez les véhicules, les plans de sièges et les équipages.',
  supervisor: 'Suivez les départs, contrôlez les ventes et traitez les alertes.',
  seller: 'Vendez rapidement, imprimez les tickets et gérez les cas courants.',
  accountant: 'Contrôlez les recettes et produisez des rapports fiables.',
  executive: 'Lisez les indicateurs utiles pour décider rapidement.',
};

const storageKey = computed(() => {
  if (! props.public && user?.id) {
    return `tiketi.help.completed.${user.id}`;
  }
  return `tiketi.help.completed.public.${effectiveRole.value}`;
});

const topicMatchesSearch = (topic, term) => [
  topic.title,
  topic.description,
  topic.outcome,
  topic.category,
  ...(topic.sections || []).flatMap((section) => [section.title, section.body, ...(section.steps || [])]),
].join(' ').toLowerCase().includes(term);

const visibleTopics = computed(() => {
  const term = search.value.trim().toLowerCase();
  return topics.value.filter((topic) => !term || topicMatchesSearch(topic, term));
});

const categories = computed(() => [...new Set(visibleTopics.value.map((topic) => (topic.category || 'Général')))]);

const groupedTopics = computed(() => categories.value
  .map((category) => ({
    category,
    topics: visibleTopics.value.filter((topic) => (topic.category || 'Général') === category),
  }))
  .filter((group) => group.topics.length > 0));

const selectedTopic = computed(() => visibleTopics.value.find((topic) => topic.id === selectedTopicId.value)
  || visibleTopics.value[0]
  || null);

const selectedTopicIndex = computed(() => visibleTopics.value.findIndex((topic) => topic.id === selectedTopic?.value?.id));
const previousTopic = computed(() => selectedTopicIndex.value > 0 ? visibleTopics.value[selectedTopicIndex.value - 1] : null);
const nextTopic = computed(() => selectedTopicIndex.value >= 0 ? visibleTopics.value[selectedTopicIndex.value + 1] || null : null);

const completedCount = computed(() => topics.value.filter((topic) => completedTopicIds.value.includes(topic.id)).length);
const progress = computed(() => topics.value.length ? Math.round((completedCount.value / topics.value.length) * 100) : 0);
const levelLabel = (levelId) => helpLevels.find((level) => level.id === levelId)?.label || levelId;
const isCompleted = (topicId) => completedTopicIds.value.includes(topicId);

const selectTopic = (topicId) => {
  selectedTopicId.value = topicId;
  drawerOpen.value = false;
};

const selectPublicRole = (roleValue) => {
  selectedRole.value = roleValue;
  selectedTopicId.value = null;
  const firstTopic = topics.value[0];
  selectedTopicId.value = firstTopic?.id || null;
};

const toggleCompleted = (topicId) => {
  completedTopicIds.value = isCompleted(topicId)
    ? completedTopicIds.value.filter((id) => id !== topicId)
    : [...completedTopicIds.value, topicId];

  if (typeof window !== 'undefined') {
    window.localStorage.setItem(storageKey.value, JSON.stringify(completedTopicIds.value));
  }
};

const openLightbox = (src) => {
  if (src) lightboxSrc.value = src;
};
const closeLightbox = () => {
  lightboxSrc.value = '';
};

onMounted(() => {
  try {
    const saved = JSON.parse(window.localStorage.getItem(storageKey.value) || '[]');
    completedTopicIds.value = Array.isArray(saved) ? saved : [];
  } catch (error) {
    completedTopicIds.value = [];
  }

  // Fermer la lightbox avec Échap
  const onKey = (e) => {
    if (e.key === 'Escape') closeLightbox();
  };
  window.addEventListener('keydown', onKey);
});

watch(visibleTopics, (nextTopics) => {
  if (!nextTopics.some((topic) => topic.id === selectedTopicId.value)) {
    selectedTopicId.value = nextTopics[0]?.id || null;
  }
});

watch(storageKey, () => {
  try {
    const saved = JSON.parse(window.localStorage.getItem(storageKey.value) || '[]');
    completedTopicIds.value = Array.isArray(saved) ? saved : [];
  } catch (error) {
    completedTopicIds.value = [];
  }
});
</script>

<template>
  <div class="flex h-full min-h-0 flex-col bg-slate-50 dark:bg-slate-950">
    <!-- ============ EN-TÊTE COMPACT STICKY ============ -->
    <header class="z-30 shrink-0 border-b border-slate-200 bg-white/95 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95">
      <div class="mx-auto w-full max-w-[1500px] px-4 py-3 sm:px-6">
        <div class="flex items-center gap-3">
          <!-- Bouton sommaire (mobile / tablette) -->
          <button
            type="button"
            class="grid h-9 w-9 shrink-0 place-items-center rounded-xl border border-slate-200 bg-white text-slate-600 transition hover:border-emerald-300 hover:bg-emerald-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 lg:hidden"
            @click="drawerOpen = true"
            :title="'Sommaire'"
          >
            <Menu :size="20" />
          </button>

          <!-- Titre concis -->
          <h1 class="flex min-w-0 items-center gap-2 truncate text-base font-black tracking-tight text-slate-900 dark:text-white sm:text-lg">
            <BookOpenPageVariant :size="22" class="shrink-0 text-emerald-600 dark:text-emerald-400" />
            <span class="truncate">Documentation &amp; Aide</span>
          </h1>

          <div class="ml-auto flex shrink-0 items-center gap-2">
            <!-- Recherche rapide -->
            <label class="relative hidden w-56 items-center sm:flex md:w-72">
              <span class="sr-only">Rechercher dans le guide</span>
              <Magnify :size="18" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-emerald-500 dark:text-emerald-400" />
              <input
                v-model="search"
                type="search"
                class="w-full rounded-xl border border-slate-200 bg-slate-50 py-2 pl-9 pr-3 text-sm text-slate-900 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                placeholder="Rechercher…"
              />
            </label>

            <!-- Progression compacte (desktop) -->
            <div class="hidden items-center gap-1.5 rounded-xl border border-slate-200 bg-slate-50 px-2.5 py-1.5 dark:border-slate-700 dark:bg-slate-800 md:flex" :title="`${completedCount} / ${topics.length} rubriques terminées`">
              <span class="text-[11px] font-black text-slate-500 dark:text-slate-300">{{ progress }}%</span>
              <div class="h-1.5 w-12 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                <div class="h-full rounded-full bg-emerald-600 transition-all" :style="{ width: `${progress}%` }"></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Recherche (mobile) -->
        <label class="relative mt-2 flex items-center sm:hidden">
          <Magnify :size="18" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-emerald-500 dark:text-emerald-400" />
          <input
            v-model="search"
            type="search"
            class="w-full rounded-xl border border-slate-200 bg-slate-50 py-2 pl-9 pr-3 text-sm text-slate-900 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white"
            placeholder="Rechercher…"
          />
        </label>

        <!-- Puces de rôle (documentation publique uniquement) -->
        <div v-if="public" class="mt-2.5 flex items-center gap-1.5 overflow-x-auto pb-0.5">
          <button
            v-for="role in publicRoles"
            :key="role.value"
            type="button"
            @click="selectPublicRole(role.value)"
            :class="[
              'shrink-0 rounded-full border px-3 py-1 text-xs font-black transition-all',
              selectedRole === role.value
                ? 'border-emerald-600 bg-emerald-600 text-white shadow-sm'
                : 'border-slate-200 bg-white text-slate-600 hover:border-emerald-300 hover:bg-emerald-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700'
            ]"
          >
            {{ role.label }}
          </button>
        </div>
        <!-- Puce rôle actif (mode connecté) -->
        <div v-else class="mt-2.5 flex items-center gap-1.5">
          <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-black text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-800">
            Parcours {{ roleLabel }}
          </span>
          <span v-if="search" class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-bold text-slate-500 dark:bg-slate-800 dark:text-slate-300">
            {{ visibleTopics.length }} résultat{{ visibleTopics.length > 1 ? 's' : '' }}
          </span>
        </div>
      </div>
    </header>

    <!-- ============ CORPS 2 COLONNES ============ -->
    <div class="mx-auto flex w-full max-w-[1500px] min-h-0 flex-1">
      <!-- --- Colonne gauche : Sommaire (desktop) --- -->
      <aside class="hidden w-[300px] shrink-0 flex-col border-r border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900 lg:flex">
        <div class="flex min-h-0 flex-1 flex-col">
          <div class="shrink-0 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
            <div class="flex items-center justify-between">
              <div class="text-[11px] font-black uppercase tracking-widest text-slate-400">Sommaire</div>
              <button
                v-if="search"
                type="button"
                class="text-xs font-black text-emerald-600 hover:text-emerald-700 dark:text-emerald-400"
                @click="search = ''"
              >
                Réinitialiser
              </button>
            </div>
            <div class="mt-1 text-sm font-bold text-slate-700 dark:text-slate-200">
              {{ visibleTopics.length }} rubrique{{ visibleTopics.length > 1 ? 's' : '' }}
            </div>
          </div>

          <div class="min-h-0 flex-1 overflow-y-auto p-3">
            <div v-if="groupedTopics.length" class="space-y-4">
              <section v-for="group in groupedTopics" :key="group.category">
                <h2 class="mb-1.5 px-2 text-[11px] font-black uppercase tracking-widest text-slate-400">
                  {{ group.category }}
                </h2>
                <div class="space-y-0.5">
                  <button
                    v-for="topic in group.topics"
                    :key="topic.id"
                    type="button"
                    @click="selectTopic(topic.id)"
                    :class="[
                      'group flex w-full items-center gap-2 rounded-xl px-3 py-2.5 text-left transition-all',
                      selectedTopic?.id === topic.id
                        ? 'bg-emerald-50 text-emerald-900 ring-1 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-200 dark:ring-emerald-800'
                        : 'text-slate-600 hover:bg-emerald-50/60 hover:text-emerald-800 dark:text-slate-300 dark:hover:bg-slate-800'
                    ]"
                  >
                    <span :class="['grid h-5 w-5 shrink-0 place-items-center rounded-full text-[10px] font-black', isCompleted(topic.id) ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-400 dark:bg-slate-700']">
                      {{ isCompleted(topic.id) ? '✓' : '•' }}
                    </span>
                    <span class="min-w-0 flex-1">
                      <span class="block text-[13px] font-bold leading-tight">{{ topic.title }}</span>
                    </span>
                    <span class="shrink-0 text-[10px] font-bold text-slate-400">{{ topic.duration }} min</span>
                  </button>
                </div>
              </section>
            </div>

            <div v-else class="rounded-xl border border-dashed border-slate-200 p-5 text-center dark:border-slate-700">
              <div class="font-black text-slate-900 dark:text-white">Aucun résultat</div>
              <p class="mt-1 text-sm text-slate-500">Essayez un autre mot.</p>
            </div>
          </div>

          <div class="shrink-0 border-t border-slate-100 px-4 py-3 dark:border-slate-800">
            <div class="flex items-center justify-between text-xs font-bold text-slate-500 dark:text-slate-400">
              <span>Progression</span>
              <span>{{ completedCount }}/{{ topics.length }}</span>
            </div>
            <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
              <div class="h-full rounded-full bg-emerald-600 transition-all" :style="{ width: `${progress}%` }"></div>
            </div>
          </div>
        </div>
      </aside>

      <!-- --- Drawer sommaire (mobile / tablette) --- -->
      <div v-if="drawerOpen" class="fixed inset-0 z-50 lg:hidden">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="drawerOpen = false"></div>
        <aside class="absolute inset-y-0 left-0 flex w-[300px] max-w-[85vw] flex-col bg-white shadow-2xl dark:bg-slate-900">
          <div class="flex shrink-0 items-center justify-between border-b border-slate-100 px-4 py-3 dark:border-slate-800">
            <div class="text-sm font-black text-slate-800 dark:text-white">Sommaire</div>
            <button type="button" class="rounded-xl p-2 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800" @click="drawerOpen = false" title="Fermer">
              <Close :size="22" />
            </button>
          </div>
          <div class="min-h-0 flex-1 overflow-y-auto p-3">
            <div v-if="groupedTopics.length" class="space-y-4">
              <section v-for="group in groupedTopics" :key="group.category">
                <h2 class="mb-1.5 px-2 text-[11px] font-black uppercase tracking-widest text-slate-400">{{ group.category }}</h2>
                <div class="space-y-0.5">
                  <button
                    v-for="topic in group.topics"
                    :key="topic.id"
                    type="button"
                    @click="selectTopic(topic.id)"
                    :class="[
                      'flex w-full items-center gap-2 rounded-xl px-3 py-2.5 text-left transition-all',
                      selectedTopic?.id === topic.id
                        ? 'bg-emerald-50 text-emerald-900 ring-1 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-200 dark:ring-emerald-800'
                        : 'text-slate-600 hover:bg-emerald-50/60 dark:text-slate-300 dark:hover:bg-slate-800'
                    ]"
                  >
                    <span :class="['grid h-5 w-5 shrink-0 place-items-center rounded-full text-[10px] font-black', isCompleted(topic.id) ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-400 dark:bg-slate-700']">
                      {{ isCompleted(topic.id) ? '✓' : '•' }}
                    </span>
                    <span class="min-w-0 flex-1 text-[13px] font-bold leading-tight">{{ topic.title }}</span>
                  </button>
                </div>
              </section>
            </div>
          </div>
        </aside>
      </div>

      <!-- --- Colonne droite : Zone de lecture --- -->
      <main class="min-w-0 flex-1 overflow-y-auto">
        <article v-if="selectedTopic" class="mx-auto max-w-4xl px-4 py-5 sm:px-6 lg:px-10 lg:py-8">
          <!-- Fil d'Ariane -->
          <nav class="flex flex-wrap items-center gap-1 text-xs font-bold text-slate-400 dark:text-slate-500" aria-label="Fil d'Ariane">
            <span class="text-slate-600 dark:text-slate-300">Documentation</span>
            <ChevronRightSmall :size="14" />
            <span>{{ selectedTopic.category || 'Général' }}</span>
            <ChevronRightSmall :size="14" />
            <span class="truncate text-emerald-700 dark:text-emerald-400">{{ selectedTopic.title }}</span>
          </nav>

          <!-- Titre de l'article -->
          <div class="mt-3 flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
            <div class="min-w-0 flex-1">
              <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-full bg-emerald-50 px-2.5 py-0.5 text-[10px] font-black uppercase tracking-wider text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
                  {{ selectedTopic.category || 'Général' }}
                </span>
                <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-0.5 text-[10px] font-black text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                  <ClockOutline :size="12" /> {{ selectedTopic.duration }} min
                </span>
                <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-[10px] font-black text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                  Niveau {{ levelLabel(selectedTopic.level).toLowerCase() }}
                </span>
              </div>
              <h1 class="mt-3 text-2xl font-black leading-tight tracking-tight text-slate-950 dark:text-white sm:text-3xl">
                {{ selectedTopic.title }}
              </h1>
              <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300 sm:text-base">
                {{ selectedTopic.description }}
              </p>
            </div>

            <!-- Capture d'écran avec zoom (lightbox) -->
            <div
              v-if="selectedTopic.image"
              class="relative w-full shrink-0 cursor-zoom-in xl:w-[320px]"
              @click="openLightbox(selectedTopic.image)"
            >
              <HelpScreenshot :src="selectedTopic.image" :title="selectedTopic.title" />
              <span class="absolute bottom-3 right-3 grid h-8 w-8 place-items-center rounded-full bg-emerald-600 text-white shadow-lg">
                <ZoomIn :size="18" />
              </span>
            </div>
          </div>

          <!-- Objectif -->
          <div class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-950/30">
            <div class="text-[11px] font-black uppercase tracking-widest text-emerald-700 dark:text-emerald-400">À la fin de ce guide</div>
            <p class="mt-1 text-sm font-bold leading-6 text-emerald-900 dark:text-emerald-200">{{ selectedTopic.outcome }}</p>
          </div>

          <!-- Sections du guide -->
          <div class="mt-6 space-y-4">
            <section
              v-for="(section, sectionIndex) in selectedTopic.sections"
              :key="section.title"
              class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-6"
            >
              <div class="flex gap-3">
                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-emerald-50 text-xs font-black text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
                  {{ sectionIndex + 1 }}
                </span>
                <div class="min-w-0 flex-1">
                  <h2 class="text-lg font-black text-slate-950 dark:text-white sm:text-xl">{{ section.title }}</h2>
                  <p class="mt-2 text-sm leading-7 text-slate-600 dark:text-slate-300">{{ section.body }}</p>

                  <div v-if="section.links?.length" class="mt-4 flex flex-wrap gap-2">
                    <a
                      v-for="link in section.links"
                      :key="link.url"
                      :href="link.url"
                      target="_blank"
                      rel="noopener noreferrer"
                      class="inline-flex rounded-lg bg-emerald-50 px-3 py-2 text-sm font-black text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-300"
                    >
                      {{ link.label }}
                    </a>
                  </div>

                  <ol v-if="section.steps?.length" class="mt-5 grid gap-3">
                    <li v-for="(step, index) in section.steps" :key="step" class="flex gap-3 rounded-xl bg-slate-50 p-3 text-sm leading-6 text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                      <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-emerald-600 text-[11px] font-black text-white">
                        {{ index + 1 }}
                      </span>
                      <span>{{ step }}</span>
                    </li>
                  </ol>
                </div>
              </div>
            </section>
          </div>

          <!-- Marquer comme terminé -->
          <div class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-5 dark:border-emerald-900 dark:bg-emerald-950/30 sm:flex sm:items-center sm:justify-between sm:gap-4">
            <div>
              <div class="flex items-center gap-2 font-black text-emerald-950 dark:text-emerald-100">
                <CheckCircleOutline :size="21" />
                Avez-vous terminé cette rubrique ?
              </div>
              <p class="mt-1 text-sm leading-6 text-emerald-800 dark:text-emerald-300">Votre progression reste enregistrée sur cet appareil.</p>
            </div>
            <button
              type="button"
              @click="toggleCompleted(selectedTopic.id)"
              :class="[
                'mt-3 shrink-0 rounded-xl px-4 py-3 text-sm font-black transition sm:mt-0',
                isCompleted(selectedTopic.id)
                  ? 'bg-white text-emerald-700 ring-1 ring-emerald-300 hover:bg-emerald-100 dark:bg-slate-900 dark:text-emerald-300'
                  : 'bg-emerald-700 text-white shadow-sm hover:bg-emerald-800'
              ]"
            >
              {{ isCompleted(selectedTopic.id) ? 'Marqué comme terminé ✓' : 'Marquer comme terminé' }}
            </button>
          </div>

          <!-- Navigation précédent / suivant -->
          <nav class="mt-6 grid gap-3 sm:grid-cols-2" aria-label="Navigation entre les guides">
            <button
              v-if="previousTopic"
              type="button"
              @click="selectTopic(previousTopic.id)"
              class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 text-left transition hover:border-emerald-300 hover:bg-emerald-50 dark:border-slate-800 dark:bg-slate-900 dark:hover:bg-slate-800"
            >
              <ChevronLeft :size="24" class="shrink-0 text-emerald-600" />
              <span class="min-w-0"><span class="block text-xs font-bold text-slate-400">Guide précédent</span><span class="mt-1 block truncate text-sm font-black text-slate-800 dark:text-white">{{ previousTopic.title }}</span></span>
            </button>
            <button
              v-if="nextTopic"
              type="button"
              @click="selectTopic(nextTopic.id)"
              class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4 text-left transition hover:border-emerald-300 hover:bg-emerald-50 dark:border-slate-800 dark:bg-slate-900 dark:hover:bg-slate-800 sm:col-start-2"
            >
              <span class="min-w-0"><span class="block text-xs font-bold text-slate-400">Guide suivant</span><span class="mt-1 block truncate text-sm font-black text-slate-800 dark:text-white">{{ nextTopic.title }}</span></span>
              <ChevronRight :size="24" class="shrink-0 text-emerald-600" />
            </button>
          </nav>

          <div class="mt-6 rounded-xl border border-slate-200 bg-white p-4 text-sm leading-6 text-slate-600 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300">
            <strong class="text-slate-900 dark:text-white">Besoin d’une réponse pendant votre travail ?</strong>
            Cliquez sur l’icône d’aide dans la barre supérieure : TIKETI ouvre directement la rubrique liée à la page courante.
          </div>
        </article>

        <div v-else class="mx-auto max-w-xl rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center dark:border-slate-700 dark:bg-slate-900">
          <div class="text-xl font-black text-slate-900 dark:text-white">Aucun guide ne correspond</div>
          <p class="mt-2 text-sm leading-6 text-slate-500">Modifiez votre recherche.</p>
          <button type="button" class="mt-5 rounded-xl bg-slate-900 px-4 py-3 text-sm font-black text-white dark:bg-white dark:text-slate-900" @click="search = ''">
            Afficher tous les guides
          </button>
        </div>
      </main>
    </div>

    <!-- ============ LIGHTBOX ============ -->
    <div v-if="lightboxSrc" class="fixed inset-0 z-[100] flex items-center justify-center bg-black/90 p-4" @click="closeLightbox">
      <button
        type="button"
        class="absolute right-4 top-4 grid h-11 w-11 place-items-center rounded-full bg-white/10 text-white transition hover:bg-white/20"
        @click.stop="closeLightbox"
        title="Fermer"
      >
        <Close :size="26" />
      </button>
      <img :src="lightboxSrc" alt="Capture d'écran agrandie" class="max-h-[90vh] max-w-[90vw] rounded-2xl shadow-2xl" @click.stop />
      <div class="absolute bottom-5 left-1/2 -translate-x-1/2 rounded-full bg-white/10 px-4 py-1.5 text-xs font-bold text-white">
        Cliquez ou appuyez sur Échap pour fermer
      </div>
    </div>
  </div>
</template>
