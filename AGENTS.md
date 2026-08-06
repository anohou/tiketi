# TIKETI — Règles de contribution

Ces règles s’appliquent à l’ensemble du dépôt et à toutes les interfaces de la plateforme.

## Alertes et confirmations

- Ne jamais utiliser les API natives du navigateur `alert()`, `confirm()` ou `window.alert()` / `window.confirm()`.
- Utiliser `toastStore` pour les informations non bloquantes, succès, avertissements et erreurs.
- Utiliser `confirmationStore.confirm()` pour toute action nécessitant une décision explicite de l’utilisateur.
- Choisir le ton du dialogue selon l’action :
  - `danger` pour une suppression, un retrait définitif ou une annulation destructive ;
  - `warning` pour une clôture, un départ, un changement de statut ou une action sensible mais réversible/auditable ;
  - `success` pour une approbation ou une validation positive.
- Le titre, le message et le libellé du bouton de confirmation doivent nommer précisément l’action. Éviter les boutons génériques « OK » lorsque « Supprimer », « Clôturer » ou « Approuver » est plus clair.
- Une clôture qui conserve l’historique ne doit pas être présentée comme une suppression.

## Sémantique des icônes d’action

- Utiliser exclusivement une icône de corbeille (`Delete`, `DeleteOutline`, `TrashCan` ou l’équivalent du design system) pour toute action de suppression, retrait, désaffectation ou clôture qui enlève un élément de la liste active.
- Ne pas introduire d’autre métaphore visuelle pour ces actions : pas de croix, moins, archive, calendrier ou icône d’interdiction.
- Réserver les icônes de croix (`Close`, `CloseCircle`, `X`) à la fermeture d’une fenêtre, d’un modal, d’un panneau, d’une notification ou d’un mode d’affichage.
- Ne jamais utiliser une croix pour supprimer une donnée, retirer une affectation, annuler un ticket ou clôturer une période.
- Une clôture peut conserver son libellé métier et utiliser un dialogue `warning`, mais son bouton d’action dans la liste utilise toujours la corbeille.
- Pour un statut inactif, refusé ou expiré, utiliser une icône d’état (`AlertCircle`, `AccountOff`, `Archive`) plutôt qu’une croix d’action.
- Chaque bouton uniquement composé d’une icône doit avoir un `title` ou un `aria-label` décrivant précisément son action.

## Comportement des actions destructives

- Toute suppression déclenchée depuis l’interface doit demander confirmation avec un dialogue moderne avant l’appel serveur.
- Le dialogue de suppression doit afficher une corbeille et utiliser le ton `danger`.
- Les erreurs serveur doivent être affichées via un toast ou dans le formulaire concerné, jamais via une alerte native.
- Après une action, préserver le défilement lorsque cela facilite la continuité du travail de l’utilisateur.

## Vérification UI

- Avant de livrer une modification front-end, exécuter `npm run build`.
- Lors d’une modification globale des alertes ou icônes, vérifier qu’aucun appel natif ne subsiste dans `resources/js` et que les croix restantes correspondent uniquement à des actions de fermeture.

## Couleurs et cohérence visuelle

- La couleur d’accent principale de l’interface est l’émeraude : utiliser `emerald-500/600` pour les actions, liens actifs, icônes fonctionnelles et indicateurs sélectionnés.
- Toutes les loupes placées dans un champ de recherche utilisent `text-emerald-500 dark:text-emerald-400`.
- Les champs utilisent une bordure structurelle neutre (`border-slate-200`, `dark:border-slate-700`) et un focus émeraude (`focus:border-emerald-500`, `focus:ring-emerald-500`).
- Les cartes, panneaux, séparateurs et zones vides utilisent des couleurs neutres `slate`; ne jamais employer une bordure orange comme décoration générale.
- Les en-têtes de page et de formulaire utilisent une icône émeraude sur fond émeraude clair, sauf lorsqu’une autre couleur transmet un état métier explicite.
- Réserver strictement `orange` et `amber` aux avertissements et états métier qui le nécessitent : retard, attention, demande en attente, embarquement ou élément physique codifié comme une porte de véhicule. Une palette multicolore reste permise dans un graphique lorsque plusieurs séries doivent être distinguées.
- Ne pas utiliser orange/amber pour une recherche, une bordure de carte, un séparateur, un état vide, une navigation, un focus ou un simple compteur.
- Avant de créer un nouveau style local, réutiliser les composants et conventions visuelles déjà présents dans les écrans de listes professionnels.
- Ne pas imbriquer une carte d’état vide dans une autre carte : utiliser la variante simple du composant lorsque le panneau parent fournit déjà la bordure, le fond et l’ombre.
- Ne pas répéter une action principale dans l’état vide d’un espace de travail lorsque cette action est déjà clairement disponible dans l’en-tête de la liste ou de la page.

## Formulaires longs

- Dans tout formulaire nécessitant un défilement vertical, les actions principales de validation doivent être placées dans un pied de panneau fixe ou sticky.
- Le contenu du formulaire défile indépendamment entre l’en-tête et ce pied.
- Les boutons de validation ne doivent jamais disparaître pendant la saisie.
- Le composant `FormPanel` de `resources/js/Components/FormPanel.vue` doit être utilisé à cet effet. Il crée un conteneur `<form>` approprié et gère la logique de la zone de défilement centrale et du pied de page.
