<script setup>
/**
 * AccordionSection.vue
 *
 * Composant générique d'accordéon vertical collapsible — le standard
 * harmonisé de la plateforme (remplace les onglets horizontaux).
 *
 * Référence de rendu : les sections « Stations Escale / Tarifs / Voyages »
 * de Admin/Routes/Index.vue. Toutes les pages de travail (Gares, Trajets,
 * Véhicules, Utilisateurs, Destinations) doivent passer par ce composant
 * pour garantir un rendu 100 % identique.
 *
 * Props :
 *   - title      : titre de la section (le compteur s'affiche entre parenthèses)
 *   - icon       : composant d'icône vue-material-design-icons
 *   - count      : compteur numérique affiché entre parenthèses (optionnel)
 *   - open       : état déplié/plié (v-model:open)
 *   - showAdd    : affiche le bouton d'ajout rapide « + » (selon permissions)
 *   - addLabel   : tooltip / aria-label du bouton « + »
 *   - iconClass  : classes de couleur de l'icône (défaut emerald)
 *
 * Événements :
 *   - update:open : bascule de l'accordéon
 *   - add         : clic sur le bouton « + » (ne bascule PAS l'accordéon)
 */
import { computed } from 'vue';
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue';
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue';
import Plus from 'vue-material-design-icons/Plus.vue';

const props = defineProps({
  title: {
    type: String,
    required: true,
  },
  icon: {
    type: Object,
    required: true,
  },
  count: {
    type: [Number, String],
    default: null,
  },
  open: {
    type: Boolean,
    default: false,
  },
  showAdd: {
    type: Boolean,
    default: false,
  },
  canAdd: {
    type: Boolean,
    default: false,
  },
  addLabel: {
    type: String,
    default: 'Ajouter',
  },
  addTooltip: {
    type: String,
    default: null,
  },
  iconClass: {
    type: String,
    default: 'text-emerald-500',
  },
});

const emit = defineEmits(['update:open', 'add']);

const isAddVisible = computed(() => props.showAdd || props.canAdd);
const effectiveAddLabel = computed(() => props.addTooltip || props.addLabel || 'Ajouter');

const toggle = () => emit('update:open', !props.open);
</script>

<template>
  <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
    <!-- En-tête cliquable -->
    <div
      @click="toggle"
      class="p-3 bg-slate-50 dark:bg-slate-950/40 flex items-center justify-between cursor-pointer hover:bg-slate-100 dark:hover:bg-slate-900/60 transition-colors"
      :aria-expanded="open"
      role="button"
      tabindex="0"
      @keydown.enter.prevent="toggle"
      @keydown.space.prevent="toggle"
    >
      <div class="flex items-center gap-2 min-w-0">
        <component :is="icon" class="h-5 w-5 shrink-0" :class="iconClass" />
        <h3 class="font-semibold text-slate-700 dark:text-slate-300 truncate">
          {{ title }}<template v-if="count !== null && count !== undefined"> ({{ count }})</template>
        </h3>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <button
          v-if="isAddVisible"
          type="button"
          @click.stop="$emit('add')"
          class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded bg-emerald-100 text-emerald-700 transition-colors hover:bg-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-400 dark:hover:bg-emerald-900"
          :title="effectiveAddLabel"
          :aria-label="effectiveAddLabel"
        >
          <Plus :size="16" class="inline-flex h-4 w-4 shrink-0 items-center justify-center leading-none text-emerald-700 dark:text-emerald-400" />
        </button>
        <component :is="open ? ChevronDown : ChevronRight" class="h-5 w-5 text-emerald-600" />
      </div>
    </div>

    <!-- Contenu collapsible avec transition fluide -->
    <Transition name="accordion">
      <div v-if="open" class="accordion-body">
        <div class="accordion-inner">
          <div class="p-4 border-t border-slate-100 dark:border-slate-800/50">
            <slot />
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.accordion-body {
  display: grid;
  grid-template-rows: 1fr;
}

.accordion-inner {
  overflow: hidden;
  min-height: 0;
}

.accordion-enter-active,
.accordion-leave-active {
  transition: grid-template-rows 0.25s ease;
}

.accordion-enter-from,
.accordion-leave-to {
  grid-template-rows: 0fr;
}
</style>
