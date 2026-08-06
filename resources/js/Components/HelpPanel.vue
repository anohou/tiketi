<script setup>
import { computed, ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { Link } from '@inertiajs/vue3';
import Close from 'vue-material-design-icons/Close.vue';
import Fullscreen from 'vue-material-design-icons/Fullscreen.vue';
import FullscreenExit from 'vue-material-design-icons/FullscreenExit.vue';
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue';
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue';
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue';
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue';
import HelpScreenshot from '@/Components/HelpScreenshot.vue';
import { getHelpTopicsForRole } from '@/Support/helpContent.js';

const props = defineProps({
  show: Boolean,
  topic: Object,
  role: {
    type: String,
    default: null,
  },
});

const emit = defineEmits(['close']);

// Rubrique affichée : locale (navigation dans le panneau) sinon celle du contexte
const activeTopic = ref(props.topic);
watch(() => props.topic, (topic) => {
  activeTopic.value = topic;
});

// Agrandissement du panneau (sans navigation vers la page d'aide globale)
const expanded = ref(false);
const toggleExpanded = () => {
  expanded.value = !expanded.value;
};

// Navigation entre les rubriques du rôle, directement dans le panneau
const roleTopics = computed(() => (props.role ? getHelpTopicsForRole(props.role) : []));
const activeIndex = computed(() => roleTopics.value.findIndex((t) => t.id === activeTopic.value?.id));
const previousTopic = computed(() => activeIndex.value > 0 ? roleTopics.value[activeIndex.value - 1] : null);
const nextTopic = computed(() => activeIndex.value >= 0 && activeIndex.value < roleTopics.value.length - 1 ? roleTopics.value[activeIndex.value + 1] : null);

const goToTopic = (topic) => {
  if (topic) activeTopic.value = topic;
};

const close = () => {
  expanded.value = false;
  emit('close');
};

const onKeydown = (e) => {
  if (!props.show) return;
  if (e.key === 'Escape') close();
};

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
  <div v-if="show" class="fixed inset-0 z-[120]">
    <div class="absolute inset-0 bg-black/30 backdrop-blur-sm dark:bg-black/50" @click="close"></div>

    <aside
      class="absolute inset-y-0 right-0 flex flex-col bg-white shadow-2xl border-l border-slate-200 transition-[max-width] duration-200 dark:border-slate-800 dark:bg-slate-900"
      :class="expanded ? 'w-[95vw] max-w-[1100px]' : 'w-full max-w-[420px]'"
    >
      <!-- En-tête -->
      <div class="flex items-start justify-between gap-3 border-b border-slate-100 p-4 dark:border-slate-800 sm:p-5">
        <div class="flex min-w-0 gap-3">
          <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">
            <HelpCircleOutline :size="22" />
          </div>
          <div class="min-w-0">
            <div class="flex items-center gap-2">
              <div class="text-xs font-black uppercase tracking-widest text-slate-500 dark:text-slate-400">Aide contextuelle</div>
              <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                {{ expanded ? 'Agrandi' : 'Compact' }}
              </span>
            </div>
            <h2 class="mt-1 truncate text-lg font-black leading-tight text-slate-900 dark:text-slate-100 sm:text-xl">
              {{ activeTopic?.title }}
            </h2>
            <div class="mt-2 flex flex-wrap gap-1.5">
              <span class="rounded-full bg-emerald-50 px-2 py-1 text-[10px] font-black text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-800">
                Pour {{ activeTopic?.roleLabel }}
              </span>
              <span class="rounded-full bg-slate-100 px-2 py-1 text-[10px] font-bold text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                {{ activeTopic?.contextLabel }}
              </span>
            </div>
          </div>
        </div>

        <div class="flex shrink-0 items-center gap-1">
          <!-- Agrandir / Réduire -->
          <button
            type="button"
            class="rounded-xl p-2 text-slate-400 transition hover:bg-slate-100 hover:text-emerald-700 dark:hover:bg-slate-800 dark:hover:text-emerald-300"
            @click="toggleExpanded"
            :title="expanded ? 'Réduire le panneau' : 'Agrandir le panneau'"
          >
            <FullscreenExit v-if="expanded" :size="22" />
            <Fullscreen v-else :size="22" />
          </button>
          <!-- Fermer -->
          <button
            type="button"
            class="rounded-xl p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800 dark:hover:text-slate-200"
            @click="close"
            title="Fermer"
          >
            <Close :size="24" />
          </button>
        </div>
      </div>

      <!-- Corps : compact = colonne simple ; agrandi = 2 colonnes (texte + image) -->
      <div class="flex-1 overflow-y-auto p-4 sm:p-5">
        <div :class="expanded ? 'grid gap-6 xl:grid-cols-[1fr_360px] xl:items-start' : ''">
          <div class="min-w-0">
            <p class="text-sm leading-6 text-slate-600 dark:text-slate-300">{{ activeTopic?.description }}</p>

            <HelpScreenshot v-if="!expanded" class="mt-5" :src="activeTopic?.image" :title="activeTopic?.title" />

            <div class="mt-6 space-y-5">
              <section v-for="section in activeTopic?.sections" :key="section.title" class="rounded-2xl border border-slate-100 bg-slate-50/50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
                <h3 class="font-black text-slate-900 dark:text-slate-100">{{ section.title }}</h3>
                <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">{{ section.body }}</p>
                <div v-if="section.links?.length" class="mt-3 grid gap-2">
                  <a
                    v-for="link in section.links"
                    :key="link.url"
                    :href="link.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="text-sm font-black text-emerald-700 hover:text-emerald-800 hover:underline dark:text-emerald-300 dark:hover:text-emerald-200"
                  >
                    {{ link.label }}
                  </a>
                </div>
                <ol v-if="section.steps?.length" class="mt-3 space-y-2">
                  <li v-for="(step, index) in section.steps" :key="step" class="flex gap-3 text-sm leading-5 text-slate-700 dark:text-slate-300">
                    <span class="grid h-5 w-5 shrink-0 place-items-center rounded-full bg-emerald-600 text-[10px] font-black text-white">{{ index + 1 }}</span>
                    <span>{{ step }}</span>
                  </li>
                </ol>
              </section>
            </div>
          </div>

          <!-- Capture d'écran dans le mode agrandi -->
          <div v-if="expanded && activeTopic?.image" class="shrink-0">
            <HelpScreenshot :src="activeTopic.image" :title="activeTopic.title" />
          </div>
        </div>
      </div>

      <!-- Pied : navigation entre rubriques + accès globaux -->
      <div class="border-t border-slate-100 p-4 dark:border-slate-800">
        <!-- Navigation précédent / suivant (reste sur la page) -->
        <div v-if="previousTopic || nextTopic" class="mb-3 grid grid-cols-2 gap-2">
          <button
            v-if="previousTopic"
            type="button"
            class="flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-2.5 text-left transition hover:border-emerald-300 hover:bg-emerald-50 dark:border-slate-700 dark:hover:bg-slate-800"
            @click="goToTopic(previousTopic)"
          >
            <ChevronLeft :size="18" class="shrink-0 text-emerald-600" />
            <span class="min-w-0">
              <span class="block text-[10px] font-bold text-slate-400">Précédent</span>
              <span class="block truncate text-xs font-black text-slate-700 dark:text-slate-200">{{ previousTopic.title }}</span>
            </span>
          </button>
          <button
            v-if="nextTopic"
            type="button"
            class="flex items-center justify-end gap-2 rounded-xl border border-slate-200 px-3 py-2.5 text-right transition hover:border-emerald-300 hover:bg-emerald-50 dark:border-slate-700 dark:hover:bg-slate-800"
            @click="goToTopic(nextTopic)"
          >
            <span class="min-w-0">
              <span class="block text-[10px] font-bold text-slate-400">Suivant</span>
              <span class="block truncate text-xs font-black text-slate-700 dark:text-slate-200">{{ nextTopic.title }}</span>
            </span>
            <ChevronRight :size="18" class="shrink-0 text-emerald-600" />
          </button>
        </div>

        <Link
          :href="route('help.index', { topic: 'interface-flags' })"
          class="mb-2 flex w-full items-center justify-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm font-black text-emerald-700 hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300"
          @click="close"
        >
          <HelpCircleOutline :size="18" />
          Drapeaux, badges et indicateurs
        </Link>
        <Link :href="route('help.index', { topic: activeTopic?.id })" class="flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-3 text-sm font-black text-white shadow-lg shadow-emerald-100 hover:bg-emerald-700 dark:shadow-black/20" @click="close">
          <OpenInNew :size="18" />
          Ouvrir le centre d’aide
        </Link>
      </div>
    </aside>
  </div>
</template>
