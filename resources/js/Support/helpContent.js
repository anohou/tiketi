const sharedGettingStarted = {
  id: 'getting-started',
  category: 'Premiers pas',
  title: 'Démarrage rapide',
  description: 'Comprendre la logique générale de TIKETI, les rôles et la navigation.',
  audience: ['admin', 'fleet_manager', 'supervisor', 'seller', 'accountant', 'executive'],
  image: '/images/help/help-center.png',
  roleVariants: {
    seller: {
      description: 'Créer vos voyages autorisés, vendre les tickets et suivre les départs de votre gare.',
      prependSections: [
        {
          title: 'Votre espace vendeur',
          body: 'Votre aide privilégie la création des voyages de votre périmètre, la vente, l’impression et le traitement des incidents au guichet. Les routes proposées dépendent de vos affectations de gare.',
        },
      ],
    },
    supervisor: {
      description: 'Créer et contrôler les voyages, suivre les gares et accompagner les agents de vente.',
      prependSections: [
        {
          title: 'Votre espace superviseur',
          body: 'Votre aide privilégie la préparation des départs, la visibilité multi-gare, le contrôle des ventes et la résolution des alertes opérationnelles.',
        },
      ],
    },
    admin: {
      description: 'Configurer TIKETI, planifier les voyages et garantir un cadre fiable pour tous les utilisateurs.',
      prependSections: [
        {
          title: 'Votre espace administrateur',
          body: 'Votre aide couvre la configuration des données de base, la création complète des voyages, les permissions et le contrôle global de l’exploitation.',
        },
      ],
    },
  },
  sections: [
    {
      title: 'À quoi sert TIKETI',
      body: 'TIKETI centralise la billetterie transport : configuration des gares, véhicules et trajets, vente des tickets au guichet, attribution intelligente des places, impression, supervision et suivi comptable.',
    },
    {
      title: 'Se repérer dans l’interface',
      body: 'La barre du haut donne accès aux modules disponibles selon votre rôle. Le bouton d’aide ouvre une assistance liée à la page courante, tandis que cette page regroupe la documentation complète.',
      steps: [
        'Utilisez Accueil pour revenir au tableau de bord de votre rôle.',
        'Utilisez Billetterie ou Voyages pour vendre et suivre les places.',
        'Utilisez Paramétrage pour créer les données de base.',
        'Utilisez Comptabilité et Analytics pour suivre les ventes.',
      ],
    },
    {
      title: 'Rôles principaux',
      body: 'Chaque rôle voit uniquement les menus qui lui sont utiles. L’administrateur configure, le vendeur vend, le superviseur contrôle, le comptable vérifie les rapports et l’exécutif suit les indicateurs.',
    },
  ],
};

const helpTopics = [
  sharedGettingStarted,
  {
    id: 'contextual-help',
    category: 'Premiers pas',
    title: 'Aide contextuelle',
    description: 'Ouvrir l’aide depuis une page et obtenir la rubrique liée au travail en cours.',
    audience: ['admin', 'fleet_manager', 'supervisor', 'seller', 'accountant', 'executive'],
    image: '/images/help/contextual-panel.png',
    sections: [
      {
        title: 'Principe',
        body: 'Le bouton d’aide dans la barre supérieure ouvre un panneau latéral. TIKETI choisit automatiquement une rubrique selon la page active : billetterie, paramètres tickets, rapports, trajets, utilisateurs ou supervision.',
      },
      {
        title: 'Utilisation au poste',
        body: 'Ce panneau est fait pour répondre vite sans quitter l’écran de travail. L’agent peut lire la procédure, fermer le panneau et continuer la vente ou la configuration.',
        steps: [
          'Ouvrez la page sur laquelle vous travaillez.',
          'Cliquez sur l’icône Aide dans la barre supérieure.',
          'Lisez la rubrique proposée.',
          'Cliquez sur Voir toute l’aide pour ouvrir le centre complet si la réponse n’est pas suffisante.',
        ],
      },
    ],
  },
  {
    id: 'ticketing',
    category: 'Billetterie',
    title: 'Vendre un ticket',
    description: 'Préparer le voyage, sélectionner une destination, vendre la place et imprimer le ticket.',
    audience: ['admin', 'supervisor', 'seller'],
    routes: ['seller.ticketing', 'seller.ticketing.horizontal', 'supervisor.ticketing'],
    pathPrefixes: ['/seller/ticketing', '/supervisor/ticketing'],
    image: '/images/help/ticketing-auto.png',
    roleVariants: {
      seller: {
        description: 'Créer ou choisir votre voyage, sélectionner une destination, vendre la place et imprimer le ticket.',
        prependSections: [
          {
            title: 'Créer votre voyage depuis votre espace de vente',
            body: 'En tant que vendeur, vous pouvez créer un voyage pour les routes et les gares auxquelles vous êtes affecté. Le sens du voyage est déterminé à partir de votre gare lorsque cela est nécessaire.',
            steps: [
              'Depuis Accueil ou Voyages, cliquez sur Nouveau Voyage.',
              'Choisissez une route disponible dans votre périmètre.',
              'Sélectionnez le véhicule et renseignez la date et l’heure de départ.',
              'Vérifiez le code proposé et le contrôle des ventes.',
              'Activez les correspondances ou la réplication uniquement si l’exploitation le prévoit.',
              'Cliquez sur Créer, puis sélectionnez le nouveau voyage pour commencer les ventes.',
            ],
          },
          {
            title: 'Si votre voyage ne s’affiche pas',
            body: 'Vérifiez d’abord votre gare, la date et la route. Une route absente de la liste indique généralement qu’elle n’appartient pas à votre périmètre d’affectation.',
            steps: [
              'Actualisez la liste et vérifiez la date du départ.',
              'Contrôlez votre gare active et vos affectations.',
              'Vérifiez que le voyage vient d’être créé avec le bon horaire.',
              'Demandez au superviseur ou à l’administrateur de contrôler la route si elle reste indisponible.',
            ],
          },
        ],
      },
      supervisor: {
        description: 'Créer ou contrôler un voyage, vendre sur le bon périmètre et superviser le départ.',
        prependSections: [
          {
            title: 'Créer un voyage depuis la billetterie superviseur',
            body: 'En tant que superviseur, vous pouvez créer un voyage depuis la billetterie. Votre visibilité plus large vous permet de préparer un départ pour les gares que vous supervisez.',
            steps: [
              'Ouvrez Billetterie puis cliquez sur Nouveau Voyage.',
              'Choisissez la route et vérifiez son sens de circulation.',
              'Sélectionnez le véhicule, la date et l’heure de départ.',
              'Contrôlez le code, le mode de vente et les éventuelles correspondances.',
              'Cliquez sur Créer et vérifiez que le voyage apparaît pour la gare concernée.',
            ],
          },
          {
            title: 'Contrôle avant l’ouverture des ventes',
            body: 'Avant qu’un agent vende, vérifiez que le voyage utilise la bonne route, le bon véhicule et le bon horaire. Une correction est plus simple avant l’émission du premier ticket.',
          },
        ],
      },
      admin: {
        description: 'Planifier ou choisir un voyage, contrôler sa configuration, puis vendre et imprimer les tickets.',
        prependSections: [
          {
            title: 'Planifier le voyage avant la vente',
            body: 'En tant qu’administrateur, vous pouvez créer le voyage depuis Paramétrage > Voyages pour disposer de tous les réglages, ou utiliser Nouveau Voyage depuis la billetterie pour une création rapide.',
            steps: [
              'Ouvrez Paramétrage puis Voyages, ou cliquez sur Nouveau Voyage dans Billetterie.',
              'Choisissez la route, le véhicule, la date et l’heure de départ.',
              'Vérifiez le code, le statut Planifié et les options d’exploitation.',
              'Enregistrez puis contrôlez la présence du voyage dans la billetterie.',
            ],
          },
          {
            title: 'Contrôler ce que verront les agents',
            body: 'La route détermine les destinations et les tarifs ; le véhicule détermine le plan de sièges. Vérifiez aussi les affectations des vendeurs si le voyage n’apparaît pas dans leur espace.',
          },
        ],
      },
    },
    sections: [
      {
        title: 'Flux de vente',
        body: 'Une fois le voyage créé et visible, sélectionnez-le puis choisissez la destination du passager. Le plan affiche les places disponibles, occupées et les suggestions intelligentes.',
        steps: [
          'Choisissez le voyage dans la liste.',
          'Sélectionnez la destination du passager.',
          'Vérifiez la place suggérée ou choisissez une autre place disponible.',
          'Validez pour créer le ticket et déclencher l’impression.',
        ],
      },
      {
        title: 'Comprendre le plan des sièges',
        body: 'Les couleurs suivent la destination des passagers. Les destinations plus proches sont plus claires, les destinations plus éloignées plus foncées.',
      },
      {
        title: 'Suggestion intelligente',
        body: 'Quand l’option automatique est active, TIKETI propose les places les plus cohérentes selon la destination, la zone d’embarquement, les places déjà occupées et la logique de remplissage du véhicule. Les détails sont expliqués dans la rubrique Algorithme de suggestion.',
      },
      {
        title: 'Bouton Auto ou Placement auto',
        body: 'Sur la capture, le bouton Auto se trouve dans le bloc Destinations. Il contrôle le comportement de la suggestion intelligente sur la page de vente. Quand il est activé, TIKETI ouvre directement la meilleure place proposée après le choix de la destination. Quand il est désactivé, TIKETI affiche les suggestions sur le plan, mais le vendeur choisit lui-même la place à vendre.',
        steps: [
          'Activez Auto pour vendre vite quand la logique de placement convient.',
          'Désactivez Auto si le passager demande une place précise, si le chef de gare donne une consigne, ou si vous voulez comparer les suggestions avant de confirmer.',
          'Même avec Auto désactivé, choisissez toujours une place disponible sur le segment affiché.',
          'Si la suggestion semble incohérente, désactivez Auto, choisissez manuellement la place, puis signalez le cas avec le numéro du voyage, la destination et le siège proposé.',
        ],
      },
      {
        title: 'Vendre manuellement sans suggestion',
        body: 'La vente manuelle reste possible. Elle est utile lorsqu’un passager voyage en groupe, préfère une zone du véhicule, ou lorsque le responsable d’exploitation impose un placement particulier.',
        steps: [
          'Sélectionnez le voyage.',
          'Sélectionnez la destination du passager.',
          'Désactivez Auto si la fenêtre de vente s’ouvre automatiquement sur une place non souhaitée.',
          'Cliquez sur une place libre du plan.',
          'Vérifiez le siège, la destination, le prix et la quantité.',
          'Cliquez sur Valider ou Imprimer pour terminer la vente.',
        ],
      },
      {
        title: 'Impression du ticket',
        body: 'Si l’imprimante Bluetooth est activée, TIKETI imprime directement sur la thermique. Sinon, le navigateur ouvre la version imprimable. Le ticket contient les informations de contrôle : numéro, destination, siège, zone, montant et QR code.',
      },
      {
        title: 'Bouton BT imprimante',
        body: 'Sur la capture de la billetterie, le bouton BT se trouve dans la barre supérieure, près du bouton d’aide. Il active l’impression Bluetooth thermique. Quand l’imprimante est connectée, le bouton est affiché en bleu. Si elle n’est pas connectée ou si le navigateur bloque Bluetooth, TIKETI ouvre l’impression navigateur comme solution de secours.',
        steps: [
          'Allumez l’imprimante thermique et vérifiez le papier.',
          'Cliquez sur BT dans la page de vente.',
          'Choisissez l’imprimante dans la fenêtre Bluetooth du navigateur.',
          'Vendez un ticket de test ou réimprimez un ticket existant pour confirmer.',
        ],
      },
    ],
  },
  {
    id: 'bluetooth-printer',
    category: 'Billetterie',
    title: 'Connecter l’imprimante Bluetooth',
    description: 'Préparer, connecter et dépanner l’imprimante thermique du guichet.',
    audience: ['admin', 'supervisor', 'seller'],
    routes: ['seller.ticketing', 'seller.ticketing.horizontal'],
    pathPrefixes: ['/seller/ticketing'],
    image: '/images/help/ticketing-auto.png',
    sections: [
      {
        title: 'Avant de connecter',
        body: 'L’impression Bluetooth fonctionne avec une imprimante thermique compatible ESC/POS et un navigateur qui autorise Web Bluetooth. Elle est surtout prévue pour Chrome ou Edge sur ordinateur ou tablette compatible.',
        steps: [
          'Chargez ou branchez l’imprimante.',
          'Mettez du papier thermique dans le bon sens.',
          'Allumez l’imprimante.',
          'Activez le Bluetooth de l’appareil.',
          'Restez proche de l’imprimante pendant l’appairage.',
        ],
      },
      {
        title: 'Connexion depuis TIKETI',
        body: 'La connexion se fait directement depuis la page de vente avec le bouton BT. TIKETI mémorise le choix localement sur l’appareil pour tenter une reconnexion automatique lors des prochaines ouvertures.',
        steps: [
          'Ouvrez Billetterie.',
          'Cliquez sur le bouton BT en haut de l’écran.',
          'Dans la fenêtre du navigateur, sélectionnez l’imprimante thermique.',
          'Acceptez la connexion.',
          'Vérifiez que le bouton BT devient bleu.',
        ],
      },
      {
        title: 'Pendant la vente',
        body: 'Après validation du ticket, TIKETI envoie le ticket à l’imprimante Bluetooth si elle est activée et connectée. Si l’impression Bluetooth échoue, l’application bascule vers l’impression navigateur pour éviter de bloquer la vente.',
      },
      {
        title: 'Si rien ne s’imprime',
        body: 'Le problème vient le plus souvent d’une imprimante éteinte, trop loin, déjà connectée à un autre appareil, sans papier, ou d’un navigateur qui ne supporte pas Bluetooth.',
        steps: [
          'Vérifiez que le bouton BT est bleu.',
          'Vérifiez le papier et le voyant de l’imprimante.',
          'Éteignez puis rallumez l’imprimante.',
          'Désactivez puis réactivez BT dans TIKETI.',
          'Fermez les autres appareils qui pourraient être connectés à l’imprimante.',
          'Si nécessaire, imprimez avec la fenêtre navigateur en attendant.',
        ],
      },
      {
        title: 'Conseils au guichet',
        body: 'Gardez une imprimante par poste de vente lorsque c’est possible. Évitez de partager la même imprimante entre plusieurs guichets en Bluetooth, car elle peut accepter une seule connexion stable à la fois.',
      },
    ],
  },
  {
    id: 'interface-flags',
    category: 'Lecture de l’interface',
    contextPriority: -10,
    title: 'Drapeaux, badges et indicateurs',
    description: 'Comprendre les signaux visuels affichés dans TIKETI et les actions associées.',
    audience: ['admin', 'fleet_manager', 'supervisor', 'seller', 'accountant', 'executive'],
    routes: ['seller.ticketing', 'seller.ticketing.horizontal', 'admin.trips.index', 'accountant.reports', 'executive.analytics'],
    pathPrefixes: ['/seller/ticketing', '/admin/trips', '/accountant/reports', '/executive/analytics'],
    image: '/images/help/ticketing-auto.png',
    sections: [
      {
        title: 'Couleurs principales',
        body: 'TIKETI utilise les couleurs pour aider à décider rapidement. Vert indique généralement une information positive ou disponible. Rouge indique une alerte, une annulation, une occupation critique ou une action destructive. Orange attire l’attention sur un paramètre, une étape ou une configuration. Bleu et violet sont souvent utilisés pour les indicateurs statistiques.',
      },
      {
        title: 'Statuts des places',
        body: 'Sur le plan de sièges, une place grise est libre ou neutre selon le contexte, une place colorée indique une occupation liée à une destination, une place verte peut indiquer une suggestion ou une sélection, et une place marquée occupée ne peut pas être vendue sur le segment actuel.',
        steps: [
          'Cliquez uniquement sur une place disponible pour vendre.',
          'Vérifiez la couleur de destination avant de confirmer.',
          'Si une place est suggérée, TIKETI l’a priorisée selon l’algorithme de remplissage.',
          'Si une place est occupée, ouvrez le détail si vous devez contrôler la destination ou le ticket associé.',
        ],
      },
      {
        title: 'Cadenas de vente',
        body: 'Le cadenas ouvert signifie que les ventes intermédiaires sont autorisées. Le cadenas fermé signifie que la vente est limitée au départ principal, sauf places réellement libérées à la gare intermédiaire selon la configuration du voyage.',
      },
      {
        title: 'Badges actif/inactif',
        body: 'Un badge actif signifie que l’élément peut être utilisé dans l’exploitation. Un badge inactif garde l’élément en historique mais l’empêche d’être proposé dans les nouveaux usages.',
      },
      {
        title: 'Icônes d’action',
        body: 'Les icônes suivent une convention simple : crayon pour modifier, corbeille pour supprimer, plus pour ajouter, imprimante pour imprimer, fichier pour exporter, loupe pour rechercher, roue dentée pour paramétrer.',
      },
    ],
  },
  {
    id: 'suggestion-algorithm',
    category: 'Billetterie',
    title: 'Algorithme de suggestion des sièges',
    description: 'Comprendre pourquoi TIKETI propose certaines places plutôt que d’autres.',
    audience: ['admin', 'supervisor', 'seller'],
    routes: ['seller.ticketing', 'seller.ticketing.horizontal', 'supervisor.ticketing'],
    pathPrefixes: ['/seller/ticketing', '/supervisor/ticketing'],
    image: '/images/help/ticketing-manual.png',
    sections: [
      {
        title: 'Objectif',
        body: 'L’algorithme cherche à vendre une place qui facilite l’embarquement, limite les blocages entre passagers, respecte les destinations, et garde le véhicule exploitable pour les prochains tronçons.',
      },
      {
        title: 'Distance de destination',
        body: 'La première logique est la distance du trajet. Une destination proche doit rester plus facile à faire descendre, tandis qu’une destination longue peut être placée plus loin dans le véhicule. C’est pour cela que les destinations proches sont privilégiées vers les zones avant ou proches des portes.',
      },
      {
        title: 'Zones idéales',
        body: 'Le véhicule est découpé en zones physiques selon le plan et les portes. Pour une destination courte, TIKETI privilégie les zones avant ou proches de sortie. Pour une destination longue, TIKETI peut accepter des places plus profondes afin de préserver les places pratiques pour les descentes rapides.',
      },
      {
        title: 'Anti-blocage',
        body: 'L’algorithme évite de créer des situations où un passager qui descend tôt est bloqué par un passager qui descend plus tard. Il regarde les voisins de rangée, les couloirs et les fenêtres selon la configuration du véhicule.',
      },
      {
        title: 'Regroupement par destination',
        body: 'Quand c’est possible, TIKETI donne un bonus aux places proches de passagers allant vers la même destination. Cela rend le contrôle plus lisible et peut fluidifier la descente.',
      },
      {
        title: 'Pourquoi le score peut changer',
        body: 'Le score dépend de l’état du voyage au moment exact de la vente. Une place peut être bonne au début, puis moins bonne après plusieurs ventes, annulations ou changements de destination.',
        steps: [
          'Choisissez une destination.',
          'TIKETI calcule les places disponibles pour ce segment.',
          'Les places impossibles ou déjà occupées sur le segment sont retirées.',
          'Chaque place reçoit un score selon zone, distance, voisins, blocage et occupation.',
          'Les meilleures places sont proposées au vendeur.',
        ],
      },
    ],
  },
  {
    id: 'ticket-cancellation',
    category: 'Billetterie',
    title: 'Annulation et libération des places',
    description: 'Annuler un ticket, conserver la trace et libérer une place pour une nouvelle vente.',
    audience: ['admin', 'supervisor', 'seller'],
    routes: ['seller.tickets.index', 'supervisor.compensations.index'],
    pathPrefixes: ['/seller/tickets', '/seller/ticketing', '/supervisor/compensations'],
    sections: [
      {
        title: 'Principe',
        body: 'Une annulation ne supprime pas l’historique du ticket. Le ticket passe en statut annulé, la raison est conservée, et la place redevient disponible si le segment concerné le permet.',
      },
      {
        title: 'Bonnes pratiques',
        body: 'Demandez toujours une raison claire. Cela facilite les contrôles comptables et les vérifications par le superviseur.',
        steps: [
          'Ouvrez le ticket concerné.',
          'Renseignez une raison courte et précise.',
          'Validez l’annulation.',
          'Vérifiez que la place est bien libérée sur le plan.',
        ],
      },
    ],
  },
  {
    id: 'ticketing-common-issues',
    category: 'Billetterie',
    title: 'Problèmes fréquents au guichet',
    description: 'Réponses rapides aux blocages courants pendant la vente.',
    audience: ['admin', 'supervisor', 'seller'],
    routes: ['seller.ticketing', 'seller.ticketing.horizontal'],
    pathPrefixes: ['/seller/ticketing'],
    image: '/images/help/ticketing-auto.png',
    sections: [
      {
        title: 'Aucune destination ne s’affiche',
        body: 'Le voyage sélectionné n’a peut-être pas de tarifs actifs pour la gare de départ du vendeur, ou le trajet n’est pas correctement configuré.',
        steps: [
          'Vérifiez que le bon voyage est sélectionné.',
          'Vérifiez que le vendeur est affecté à la bonne gare.',
          'Demandez à l’administrateur de contrôler les tarifs du trajet.',
          'Contrôlez aussi l’ordre des arrêts si le trajet a des destinations intermédiaires.',
        ],
      },
      {
        title: 'Le prix ne correspond pas',
        body: 'Le prix vient du tarif configuré entre la gare de départ et la destination choisie. Si le montant est mauvais, il faut corriger le tarif, pas le ticket déjà imprimé.',
        steps: [
          'Notez le voyage, la destination et le prix affiché.',
          'Demandez la correction dans Paramétrage puis Tarifs.',
          'Annulez et revendez le ticket si le mauvais prix a déjà été encaissé.',
        ],
      },
      {
        title: 'La place demandée est grisée ou occupée',
        body: 'Une place occupée sur le segment choisi ne peut pas être vendue. Elle peut être libre sur un autre tronçon, mais pas entre la gare de départ et la destination sélectionnée.',
        steps: [
          'Vérifiez que la destination choisie est la bonne.',
          'Sélectionnez une autre place libre.',
          'Si vous êtes superviseur ou administrateur, ouvrez la place occupée pour inspecter le ticket lié.',
        ],
      },
      {
        title: 'Le ticket est vendu mais pas imprimé',
        body: 'La vente peut réussir même si l’impression échoue. Dans ce cas, retrouvez le ticket dans la liste ou dans le voyage, puis relancez l’impression.',
        steps: [
          'Vérifiez l’état de l’imprimante Bluetooth.',
          'Autorisez les popups si l’impression navigateur est utilisée.',
          'Ouvrez la liste des tickets ou le détail du voyage.',
          'Réimprimez le ticket concerné.',
        ],
      },
      {
        title: 'Le client change de destination',
        body: 'Après impression, ne modifiez pas le ticket directement. Annulez le ticket si la procédure de l’entreprise l’autorise, puis revendez avec la bonne destination afin de garder une trace claire.',
      },
    ],
  },
  {
    id: 'ticket-settings',
    category: 'Billetterie',
    title: 'Paramètres des tickets',
    description: 'Configurer le contenu fixe du ticket, le QR code, l’impression et l’intégration OKOHI.',
    audience: ['admin'],
    routes: ['admin.ticket-settings.index'],
    pathPrefixes: ['/admin/ticket-settings'],
    image: '/images/help/ticket-settings.png',
    sections: [
      {
        title: 'Aperçu du ticket',
        body: 'L’aperçu montre un ticket complet avec des données d’exemple. Les champs du formulaire pilotent les textes fixes comme le nom, les téléphones, le libellé CC, les messages de pied et la fidélité.',
      },
      {
        title: 'Contenu personnalisable',
        body: 'Le nom de l’entreprise, les numéros de téléphone, le libellé CC, les messages de pied de page et le message bagages sont modifiables. Si le libellé CC est vide, la ligne CC disparaît du ticket.',
      },
      {
        title: 'QR code',
        body: 'Le QR TIKETI reste actif par défaut. Quand OKOHI est activé et configuré, le QR imprimé devient l’URL de scan OKOHI pour cumuler les points.',
        steps: [
          'Activez le QR code.',
          'Activez la fidélité OKOHI.',
          'Renseignez l’hôte OKOHI, le company ID, le type de fidélité et la clé d’intégration.',
          'Copiez l’URL de vérification affichée dans l’espace propriétaire OKOHI.',
        ],
      },
    ],
  },
  {
    id: 'okohi',
    category: 'Fidélité',
    title: 'Fidélité OKOHI',
    description: 'Connecter TIKETI à OKOHI pour attribuer des points ou visites après scan du ticket.',
    audience: ['admin'],
    routes: ['admin.ticket-settings.index', 'admin.settings.loyalty'],
    pathPrefixes: ['/admin/ticket-settings', '/admin/settings/loyalty'],
    image: '/images/help/ticket-settings.png',
    sections: [
      {
        title: 'Fonctionnement',
        body: 'OKOHI est le système de fidélité connecté à TIKETI. Quand l’intégration est activée, le QR code imprimé sur le ticket contient une URL OKOHI. Le client scanne ce QR avec l’application OKOHI, puis OKOHI vérifie le ticket auprès de TIKETI avant d’attribuer les points ou la visite.',
        links: [
          {
            label: 'Télécharger OKOHI sur Google Play',
            url: 'https://play.google.com/store/apps/details?id=com.anohou.okohi',
          },
        ],
      },
      {
        title: 'Format du QR imprimé',
        body: 'TIKETI génère automatiquement le format attendu par OKOHI : https://<okohi-host>/api/v1/scan/{company_id}/{loyalty_type}/{integration_key}/{ticket_id}/{amount}/{timestamp}. Le ticket_id envoyé à OKOHI correspond au numéro du ticket TIKETI, le montant correspond au prix payé, et le timestamp correspond à l’émission du ticket.',
      },
      {
        title: 'Vérification par OKOHI',
        body: 'Après le scan, OKOHI appelle l’API de vérification de TIKETI en GET. TIKETI répond success: true uniquement si le ticket existe et n’est pas annulé. Cette vérification empêche l’attribution de points sur un faux ticket ou un ticket invalidé.',
        steps: [
          'Activez la fidélité OKOHI dans les paramètres tickets.',
          'Copiez l’URL de vérification affichée dans TIKETI.',
          'Collez cette URL dans l’espace propriétaire OKOHI.',
          'Renseignez les champs fournis par OKOHI : company ID, type, clé d’intégration.',
        ],
      },
      {
        title: 'URL à renseigner dans OKOHI',
        body: 'Dans l’espace propriétaire OKOHI, la section Intégration API demande une URL de vérification contenant le placeholder {ticket_id}. Dans TIKETI, cette URL est affichée dans Paramètres tickets après activation OKOHI. Elle ressemble à : https://votre-domaine/api/okohi/verify?ticket_id={ticket_id}.',
      },
      {
        title: 'Sécurité opérationnelle',
        body: 'OKOHI attend une réponse HTTP 200 avec success: true pour attribuer les points. Un ticket annulé ou introuvable est refusé. En production, l’URL publique de vérification doit être accessible en HTTPS, et chaque ticket_id doit rester unique.',
      },
    ],
  },
  {
    id: 'admin-settings',
    category: 'Administration',
    title: 'Paramétrage',
    description: 'Créer les gares, véhicules, trajets, tarifs, utilisateurs et affectations.',
    audience: ['admin', 'supervisor'],
    routes: ['admin.settings.index', 'supervisor.settings.index'],
    pathPrefixes: ['/admin/settings', '/supervisor/settings'],
    image: '/images/help/route-detail.png',
    roleVariants: {
      supervisor: {
        title: 'Paramétrage de supervision',
        description: 'Accéder aux gares, utilisateurs et affectations utiles à votre périmètre.',
        sections: [
          {
            title: 'Votre périmètre de paramétrage',
            body: 'En tant que superviseur, vous disposez des réglages nécessaires au suivi des gares et des équipes. Les réglages structurels de l’entreprise restent sous la responsabilité de l’administrateur.',
            steps: [
              'Consultez les gares de votre périmètre.',
              'Créez ou mettez à jour les utilisateurs autorisés.',
              'Vérifiez les affectations des vendeurs aux bonnes gares.',
              'Demandez à l’administrateur toute modification de route, tarif ou véhicule non disponible dans votre espace.',
            ],
          },
        ],
      },
    },
    sections: [
      {
        title: 'Ordre conseillé',
        body: 'Configurez les éléments dans l’ordre opérationnel pour éviter les données manquantes.',
        steps: [
          'Créez les gares et destinations.',
          'Créez les types de véhicules puis les véhicules.',
          'Créez les trajets et leurs arrêts.',
          'Ajoutez les tarifs.',
          'Créez les utilisateurs et leurs affectations.',
        ],
      },
    ],
  },
  {
    id: 'stations-routes',
    category: 'Administration',
    title: 'Gares, destinations et trajets',
    description: 'Structurer le réseau de transport avant les ventes et contrôler l’ordre des destinations.',
    audience: ['admin', 'supervisor'],
    routes: ['supervisor.stations.index'],
    pathPrefixes: ['/admin/stations', '/admin/routes', '/admin/destinations', '/supervisor/stations'],
    image: '/images/help/route-destination-order.png',
    roleVariants: {
      supervisor: {
        title: 'Consulter les gares supervisées',
        description: 'Comprendre les gares disponibles et leur impact sur les vendeurs et les voyages.',
        sections: [
          {
            title: 'Gares et périmètre',
            body: 'Une gare détermine les routes et voyages visibles par les vendeurs qui lui sont affectés. Contrôlez la gare d’un agent lorsqu’une route ou un voyage attendu ne s’affiche pas.',
          },
          {
            title: 'Quand solliciter l’administrateur',
            body: 'La modification de la structure d’une route, de l’ordre de ses arrêts ou des destinations relève de l’administration. Transmettez le nom de la route, la gare concernée et l’ordre attendu.',
          },
        ],
      },
    },
    sections: [
      {
        title: 'Gares et destinations',
        body: 'Une gare représente un point physique de départ ou d’arrivée. Les destinations permettent d’organiser les lieux servis et de les réutiliser dans les trajets.',
      },
      {
        title: 'Ordre des arrêts',
        body: 'L’ordre des arrêts est essentiel pour les ventes par tronçon. Il permet à TIKETI de savoir si deux passagers peuvent utiliser la même place sur des segments différents.',
        steps: [
          'Créez ou ouvrez un trajet.',
          'Ajoutez les gares dans l’ordre réel de passage.',
          'Vérifiez l’origine et la destination terminale.',
          'Enregistrez avant de créer les voyages.',
        ],
      },
      {
        title: 'Changer l’ordre des destinations sur un trajet',
        body: 'Changer l’ordre des destinations modifie la logique de vente, la disponibilité des places par segment, les couleurs du plan et les suggestions intelligentes. Il faut donc le faire avant l’exploitation du voyage, ou vérifier attentivement les tickets déjà vendus.',
        steps: [
          'Ouvrez Paramétrage puis Trajets.',
          'Sélectionnez le trajet concerné.',
          'Ouvrez la gestion des arrêts ou destinations du trajet.',
          'Réorganisez les arrêts dans l’ordre réel de passage du véhicule.',
          'Enregistrez l’ordre.',
          'Contrôlez que l’origine, les arrêts intermédiaires et la destination finale sont cohérents.',
        ],
      },
      {
        title: 'Impact sur les tickets déjà vendus',
        body: 'Si des tickets existent déjà sur des voyages liés au trajet, un changement d’ordre peut rendre la lecture des segments différente. La meilleure pratique est de corriger l’ordre avant d’ouvrir les ventes.',
      },
    ],
  },
  {
    id: 'vehicles',
    category: 'Administration',
    title: 'Véhicules et plans de sièges',
    description: 'Définir les types de véhicules, les sièges, portes et configurations.',
    audience: ['admin'],
    pathPrefixes: ['/admin/vehicle-types', '/admin/vehicles'],
    image: '/images/seat-map.png',
    sections: [
      {
        title: 'Type de véhicule',
        body: 'Le type de véhicule porte la configuration des sièges : capacité, disposition, portes, dernière rangée et plan utilisé par la billetterie.',
      },
      {
        title: 'Véhicule',
        body: 'Le véhicule est l’unité opérationnelle affectée à un voyage. Il utilise un type de véhicule pour hériter du plan et de la capacité.',
      },
      {
        title: 'Impact sur la suggestion',
        body: 'La position des portes et des rangées influence la suggestion intelligente. Une configuration correcte améliore le remplissage et l’embarquement.',
      },
    ],
  },
  {
    id: 'fares',
    category: 'Administration',
    title: 'Tarifs',
    description: 'Définir les prix entre les gares et destinations.',
    audience: ['admin'],
    pathPrefixes: ['/admin/route-fares'],
    sections: [
      {
        title: 'Rôle des tarifs',
        body: 'Les tarifs déterminent le montant du ticket au moment de la vente. Si aucun tarif actif n’existe entre deux gares, la vente est bloquée.',
      },
      {
        title: 'Tarif bidirectionnel',
        body: 'Un tarif bidirectionnel peut être utilisé dans les deux sens. Désactivez cette option si le prix doit être différent selon le sens du voyage.',
      },
    ],
  },
  {
    id: 'users-assignments',
    category: 'Administration',
    title: 'Utilisateurs et affectations',
    description: 'Créer les comptes et limiter les vendeurs à leurs gares.',
    audience: ['admin', 'supervisor'],
    routes: ['admin.users.index', 'admin.assignments.index', 'supervisor.users.index', 'supervisor.assignments.index'],
    pathPrefixes: ['/admin/users', '/admin/assignments', '/supervisor/users', '/supervisor/assignments'],
    sections: [
      {
        title: 'Rôles',
        body: 'Les rôles contrôlent les menus et permissions : administrateur, vendeur, superviseur, comptable et exécutif.',
      },
      {
        title: 'Affectation vendeur',
        body: 'Un vendeur peut être limité à une ou plusieurs gares. Cette restriction empêche la vente depuis une gare non autorisée.',
        steps: [
          'Créez l’utilisateur vendeur.',
          'Ouvrez les affectations.',
          'Associez le vendeur à ses gares.',
          'Vérifiez que l’affectation est active.',
        ],
      },
    ],
  },
  {
    id: 'trips',
    category: 'Exploitation',
    title: 'Gestion des voyages',
    description: 'Planifier les départs, consulter l’occupation et suivre les tickets vendus.',
    audience: ['admin', 'supervisor', 'seller'],
    routes: ['admin.trips.index', 'seller.dashboard', 'trips.index'],
    pathPrefixes: ['/admin/trips', '/seller/dashboard', '/trips'],
    image: '/images/seat-map.png',
    roleVariants: {
      seller: {
        title: 'Créer et préparer mes voyages',
        description: 'Créer un départ dans votre périmètre, vérifier le véhicule et ouvrir les ventes.',
        sections: [
          {
            title: 'Créer un voyage',
            body: 'Le bouton Nouveau Voyage est disponible depuis votre accueil et depuis la page Voyages. La liste des routes respecte vos affectations de gare.',
            steps: [
              'Cliquez sur Nouveau Voyage.',
              'Choisissez la route proposée pour votre gare.',
              'Sélectionnez le véhicule prévu.',
              'Renseignez la date et l’heure de départ.',
              'Vérifiez le code et les options de vente.',
              'Cliquez sur Créer puis ouvrez le voyage obtenu.',
            ],
          },
          {
            title: 'Contrôler avant le premier ticket',
            body: 'Vérifiez la destination, l’heure et le plan de sièges. Si une route ou un véhicule manque, contactez le superviseur ou l’administrateur plutôt que de créer le départ avec une donnée incorrecte.',
          },
          {
            title: 'Ventes intermédiaires',
            body: 'Le contrôle des ventes indique si les ventes sont autorisées depuis les gares intermédiaires. Respectez la consigne définie pour le voyage.',
          },
        ],
      },
      supervisor: {
        title: 'Créer et superviser les voyages',
        description: 'Préparer les départs des gares supervisées et contrôler leur mise en vente.',
        sections: [
          {
            title: 'Créer un voyage supervisé',
            body: 'Depuis Billetterie, le bouton Nouveau Voyage permet de préparer un départ pour votre périmètre de supervision.',
            steps: [
              'Cliquez sur Nouveau Voyage dans Billetterie.',
              'Choisissez la route et vérifiez le sens du départ.',
              'Sélectionnez le véhicule, la date et l’heure.',
              'Contrôlez le mode de vente, le code et les correspondances.',
              'Créez le voyage puis vérifiez sa visibilité pour la gare et les vendeurs concernés.',
            ],
          },
          {
            title: 'Superviser l’ouverture des ventes',
            body: 'Contrôlez l’occupation, les affectations et les données du départ avant le premier ticket. En cas d’erreur, faites corriger le voyage avant de demander aux agents de vendre.',
          },
          {
            title: 'Ventes intermédiaires',
            body: 'Le mode de contrôle des ventes détermine si les agents peuvent vendre seulement au départ ou aussi aux gares intermédiaires. Vérifiez qu’il correspond à la consigne d’exploitation.',
          },
        ],
      },
    },
    sections: [
      {
        title: 'Créer un voyage',
        body: 'Un voyage relie une route, un véhicule et une date et heure de départ. Il doit être créé avant la vente, car tous les tickets et toutes les occupations de sièges seront rattachés à ce départ précis.',
        steps: [
          'Ouvrez Paramétrage puis Voyages avec un compte administrateur.',
          'Cliquez sur Nouveau Voyage.',
          'Choisissez la route correspondant au sens réel du départ.',
          'Affectez un véhicule actif avec le bon plan de sièges.',
          'Renseignez la date, l’heure et vérifiez le code proposé.',
          'Laissez le statut Planifié pour un nouveau départ.',
          'Activez Voyage réplicable uniquement si ce départ doit être recréé automatiquement chaque jour.',
          'Enregistrez et contrôlez sa présence dans la billetterie.',
        ],
      },
      {
        title: 'Informations à contrôler avant les ventes',
        body: 'Une erreur de route, d’heure ou de véhicule se répercute sur les destinations, les tarifs et le plan de sièges. Faites ce contrôle avant qu’un premier ticket soit émis.',
        steps: [
          'Vérifiez l’origine, les arrêts et la destination finale de la route.',
          'Vérifiez la date et l’heure locales du départ.',
          'Vérifiez le véhicule, sa capacité et son assurance à la date du voyage.',
          'Vérifiez le mode de vente et les éventuelles correspondances ouvertes.',
        ],
      },
      {
        title: 'Ventes intermédiaires',
        body: 'Le mode de contrôle des ventes détermine si les agents peuvent vendre seulement au départ ou aussi aux gares intermédiaires.',
      },
    ],
  },
  {
    id: 'seat-reuse',
    category: 'Exploitation',
    title: 'Réutilisation des sièges par tronçon',
    description: 'Comprendre pourquoi une même place peut être vendue plusieurs fois sur un voyage.',
    audience: ['admin', 'supervisor', 'seller'],
    image: '/images/seat-map.png',
    sections: [
      {
        title: 'Principe',
        body: 'Sur un trajet avec arrêts intermédiaires, une place peut être utilisée par plusieurs passagers si leurs segments ne se chevauchent pas. Exemple : une place vendue de A à B peut être revendue de B à C.',
      },
      {
        title: 'Contrôle automatique',
        body: 'TIKETI vérifie les segments avant chaque vente. Si les segments se chevauchent, la vente est refusée pour éviter la double occupation.',
      },
    ],
  },
  {
    id: 'fleet-operations',
    category: 'Gestion de flotte',
    title: 'Piloter la flotte et les équipages',
    description: 'Tenir le parc à jour, préparer les plans de sièges et affecter les équipages.',
    audience: ['admin', 'fleet_manager'],
    routes: ['fleet.dashboard', 'fleet.vehicles.index', 'fleet.vehicle-types.index', 'fleet.crew-members.index', 'fleet.crew-assignments.index'],
    pathPrefixes: ['/fleet'],
    image: '/images/seat-map.png',
    sections: [
      {
        title: 'Routine du responsable de flotte',
        body: 'Le tableau de bord flotte regroupe les éléments qui doivent être prêts avant l’exploitation : véhicules actifs, plans de sièges cohérents, membres d’équipage disponibles et affectations valides.',
        steps: [
          'Contrôlez les alertes et les échéances depuis le tableau de bord.',
          'Vérifiez que le véhicule prévu est actif et utilise le bon type de véhicule.',
          'Contrôlez le plan de sièges et la position des portes.',
          'Vérifiez la disponibilité des membres d’équipage.',
          'Créez ou corrigez les affectations avant le départ.',
        ],
      },
      {
        title: 'Véhicule ou type de véhicule',
        body: 'Le type de véhicule décrit le modèle et son plan de sièges. Le véhicule représente l’unité réelle du parc avec son immatriculation, son état et ses échéances. Corrigez le type si plusieurs véhicules doivent partager le même plan ; corrigez le véhicule si une seule unité est concernée.',
      },
      {
        title: 'Affectations sans conflit',
        body: 'Une affectation associe un véhicule ou un membre d’équipage à une période d’exploitation. Avant d’enregistrer, contrôlez les dates, le rôle de l’équipier et l’absence de chevauchement avec une autre mission.',
      },
      {
        title: 'Quand rendre un élément inactif',
        body: 'Rendez un véhicule ou un équipier inactif lorsqu’il ne doit plus être proposé dans les nouvelles affectations. Cette action conserve son historique. Vérifiez d’abord les voyages et affectations à venir qui pourraient encore en dépendre.',
      },
    ],
  },
  {
    id: 'fleet-compliance',
    category: 'Gestion de flotte',
    title: 'Anticiper les indisponibilités de la flotte',
    description: 'Contrôler les échéances, les conflits d’affectation et les éléments inactifs avant qu’ils ne bloquent un départ.',
    audience: ['admin', 'fleet_manager'],
    pathPrefixes: ['/fleet/vehicles', '/fleet/crew-members', '/fleet/crew-assignments'],
    sections: [
      {
        title: 'Contrôle préventif',
        body: 'Un véhicule techniquement présent dans le parc n’est pas nécessairement exploitable. Croisez son état actif, les échéances administratives, son type de véhicule et ses affectations futures avant de le confirmer pour un départ.',
        steps: [
          'Repérez les documents ou échéances qui approchent de leur date limite.',
          'Vérifiez les véhicules et équipiers concernés par des départs à venir.',
          'Préparez un remplacement avant de rendre un élément inactif.',
          'Corrigez les affectations en conflit puis contrôlez de nouveau la période.',
        ],
      },
      {
        title: 'Analyser un conflit d’affectation',
        body: 'Un conflit signifie généralement qu’un véhicule ou un équipier est déjà mobilisé sur une période qui se chevauche. Comparez les heures de début et de fin, le voyage concerné et le rôle attribué avant de modifier l’une des missions.',
      },
      {
        title: 'Conserver la traçabilité',
        body: 'Préférez le passage à l’état inactif à la suppression d’un élément déjà utilisé. Vous conservez ainsi l’historique des voyages et pouvez expliquer les anciennes affectations lors d’un contrôle.',
      },
    ],
  },
  {
    id: 'reports',
    category: 'Pilotage',
    title: 'Comptabilité et rapports',
    description: 'Suivre les ventes, exporter les rapports et contrôler les revenus.',
    audience: ['admin', 'accountant'],
    routes: ['accountant.reports'],
    pathPrefixes: ['/accountant/reports'],
    sections: [
      {
        title: 'Filtres et exports',
        body: 'Les rapports se filtrent par période et permettent de vérifier le volume de tickets, le revenu et les détails par vendeur ou trajet.',
        steps: [
          'Choisissez une période.',
          'Vérifiez les totaux et les lignes de tickets.',
          'Exportez si vous devez partager ou archiver le rapport.',
        ],
      },
    ],
  },
  {
    id: 'financial-control',
    category: 'Pilotage',
    title: 'Contrôler et rapprocher les recettes',
    description: 'Passer du total affiché à un contrôle comptable explicable par période, vendeur et voyage.',
    audience: ['admin', 'accountant'],
    pathPrefixes: ['/accountant/reports'],
    sections: [
      {
        title: 'Méthode de rapprochement',
        body: 'Travaillez toujours sur une période fermée et conservez les mêmes filtres pendant le contrôle. Le total doit pouvoir être expliqué par les tickets vendus, les annulations et les opérations particulières de la période.',
        steps: [
          'Fixez la période et notez les filtres utilisés.',
          'Comparez le total global avec le détail par vendeur ou par voyage.',
          'Isolez les annulations, écarts ou montants atypiques.',
          'Ouvrez le détail des tickets concernés avant de conclure.',
          'Exportez le rapport contrôlé avec une référence de période.',
        ],
      },
      {
        title: 'Traiter un écart',
        body: 'Ne corrigez pas un écart uniquement à partir du total. Identifiez d’abord le ticket, son statut, son vendeur, son heure d’émission et le voyage associé. Cette méthode distingue une annulation normale, une erreur de filtre et une anomalie à faire valider.',
      },
      {
        title: 'Constituer une preuve de contrôle',
        body: 'Conservez l’export, la période, les filtres et une courte justification pour chaque écart accepté. Le contrôle reste ainsi reproductible par un autre comptable ou par la direction.',
      },
    ],
  },
  {
    id: 'analytics',
    category: 'Pilotage',
    title: 'Analytics dirigeant',
    description: 'Lire les tendances commerciales et les indicateurs d’exploitation.',
    audience: ['admin', 'executive'],
    routes: ['executive.analytics'],
    pathPrefixes: ['/executive/analytics'],
    sections: [
      {
        title: 'Lecture rapide',
        body: 'Les indicateurs montrent les ventes, les revenus, l’occupation et les trajets les plus performants pour la période sélectionnée.',
      },
    ],
  },
  {
    id: 'decision-analysis',
    category: 'Pilotage',
    title: 'Transformer les indicateurs en décisions',
    description: 'Comparer les périodes, distinguer tendance et incident, puis formuler une action mesurable.',
    audience: ['admin', 'executive'],
    pathPrefixes: ['/executive/analytics'],
    sections: [
      {
        title: 'Lire une tendance correctement',
        body: 'Un chiffre isolé décrit une situation, pas une tendance. Comparez des périodes de même durée et tenez compte des jours d’exploitation, des changements de capacité et des événements exceptionnels.',
        steps: [
          'Choisissez une période de référence comparable.',
          'Observez ensemble revenu, volume de tickets et taux d’occupation.',
          'Identifiez les trajets qui expliquent l’essentiel de la variation.',
          'Vérifiez si la variation est durable ou liée à un événement ponctuel.',
        ],
      },
      {
        title: 'Passer du constat à l’action',
        body: 'Formulez une action avec un responsable, une échéance et un indicateur de réussite. Exemple : ajuster la capacité d’un trajet pendant deux semaines et mesurer l’évolution de l’occupation et du revenu par départ.',
      },
      {
        title: 'Éviter les conclusions trompeuses',
        body: 'Une hausse du revenu peut venir d’un tarif plus élevé sans hausse de fréquentation. Un bon taux d’occupation peut aussi masquer trop peu de départs. Croisez toujours au moins deux indicateurs avant de décider.',
      },
    ],
  },
  {
    id: 'supervisor',
    category: 'Supervision',
    title: 'Tour de contrôle',
    description: 'Superviser les départs, les alertes et les demandes d’annulation.',
    audience: ['admin', 'supervisor'],
    routes: ['supervisor.dashboard', 'supervisor.control-tower'],
    pathPrefixes: ['/supervisor'],
    sections: [
      {
        title: 'Contrôle opérationnel',
        body: 'La tour de contrôle aide à suivre les voyages proches du départ, l’occupation et les actions nécessitant validation.',
      },
    ],
  },
  {
    id: 'transfer-pool',
    category: 'Exploitation',
    title: 'Gestion des correspondances',
    description: 'Suivre les passagers en transit, valider leur présence et leur attribuer un siège pour la suite du voyage.',
    audience: ['admin', 'supervisor', 'seller'],
    routes: ['seller.transfer-pool.index'],
    pathPrefixes: ['/seller/transfer-pool'],
    sections: [
      {
        title: 'Principe des correspondances',
        body: 'Les correspondances permettent à un passager d’effectuer un trajet combiné avec changement de véhicule dans une gare de transit (gare de transfert). Le passager dispose d’un ticket unique qui couvre tout son voyage.',
      },
      {
        title: 'Valider la présence (Bouton Prêt)',
        body: 'Dès que le passager arrive à la gare de correspondance, le vendeur doit marquer sa présence pour le voyage suivant. Cela signale au système qu’il est prêt à être placé dans le véhicule de connexion.',
        steps: [
          'Ouvrez le menu Correspondances.',
          'Recherchez la correspondance du passager.',
          'Cliquez sur Prêt / Présent pour valider sa présence.',
        ],
      },
      {
        title: 'Affectation automatique ou manuelle',
        body: 'Après validation de la présence, le passager peut être affecté automatiquement ou manuellement à un voyage de connexion.',
        steps: [
          'Sélectionnez le voyage de connexion dans la liste.',
          'L’affectation automatique place les passagers en transit sur les sièges libres les plus adaptés.',
          'L’affectation manuelle permet de choisir précisément le siège pour chaque passager.',
        ],
      },
    ],
  },
];

export const helpLevels = [
  {
    id: 'essential',
    label: 'Essentiel',
    shortLabel: 'Je débute',
    description: 'Comprendre l’interface et réaliser les actions indispensables.',
  },
  {
    id: 'independent',
    label: 'Autonome',
    shortLabel: 'Je pratique',
    description: 'Maîtriser les tâches courantes et résoudre les incidents simples.',
  },
  {
    id: 'advanced',
    label: 'Avancé',
    shortLabel: 'Je maîtrise',
    description: 'Configurer, contrôler et comprendre les règles métier en profondeur.',
  },
];

export const helpRoleLabels = {
  admin: 'Administrateur',
  fleet_manager: 'Responsable de flotte',
  supervisor: 'Superviseur',
  seller: 'Agent de vente',
  accountant: 'Comptable',
  executive: 'Direction',
};

const topicGuidance = {
  'getting-started': { level: 'essential', duration: 5, outcome: 'Vous saurez vous repérer et identifier les modules utiles à votre rôle.' },
  'contextual-help': { level: 'essential', duration: 2, outcome: 'Vous saurez obtenir une réponse sans quitter votre écran de travail.' },
  'interface-flags': { level: 'independent', duration: 6, outcome: 'Vous saurez interpréter les couleurs, badges et états importants.' },
  ticketing: { level: 'essential', duration: 10, outcome: 'Vous saurez vendre et imprimer un ticket en contrôlant chaque étape.' },
  'bluetooth-printer': { level: 'independent', duration: 7, outcome: 'Vous saurez connecter l’imprimante et assurer une solution de secours.' },
  'ticket-cancellation': { level: 'independent', duration: 6, outcome: 'Vous saurez traiter une annulation sans perdre la traçabilité.' },
  'ticketing-common-issues': { level: 'independent', duration: 8, outcome: 'Vous saurez diagnostiquer les blocages les plus courants au guichet.' },
  'seat-reuse': { level: 'independent', duration: 5, outcome: 'Vous comprendrez la disponibilité d’un siège sur plusieurs tronçons.' },
  trips: { level: 'independent', duration: 8, outcome: 'Vous saurez préparer un départ et suivre son occupation.' },
  supervisor: { level: 'independent', duration: 7, outcome: 'Vous saurez repérer les départs et actions qui demandent votre attention.' },
  reports: { level: 'essential', duration: 7, outcome: 'Vous saurez contrôler une période de vente et produire un export.' },
  analytics: { level: 'essential', duration: 5, outcome: 'Vous saurez lire rapidement les indicateurs de pilotage.' },
  'fleet-operations': { level: 'independent', duration: 9, outcome: 'Vous saurez préparer les véhicules et équipages pour l’exploitation.' },
  'fleet-compliance': { level: 'advanced', duration: 9, outcome: 'Vous saurez anticiper un blocage et documenter une décision d’indisponibilité.' },
  'admin-settings': { level: 'essential', duration: 8, outcome: 'Vous connaîtrez l’ordre fiable pour initialiser TIKETI.' },
  'stations-routes': { level: 'independent', duration: 10, outcome: 'Vous saurez structurer un trajet sans fausser les segments de vente.' },
  vehicles: { level: 'independent', duration: 8, outcome: 'Vous saurez distinguer un véhicule de son type et fiabiliser son plan.' },
  fares: { level: 'independent', duration: 5, outcome: 'Vous saurez créer un tarif utilisable dans le bon sens de voyage.' },
  'users-assignments': { level: 'independent', duration: 7, outcome: 'Vous saurez donner à chaque utilisateur le bon périmètre d’accès.' },
  'ticket-settings': { level: 'advanced', duration: 8, outcome: 'Vous saurez personnaliser les tickets et leurs options d’impression.' },
  okohi: { level: 'advanced', duration: 10, outcome: 'Vous comprendrez et saurez sécuriser le parcours de fidélité OKOHI.' },
  'suggestion-algorithm': { level: 'advanced', duration: 12, outcome: 'Vous saurez expliquer les suggestions et analyser un placement inattendu.' },
  'financial-control': { level: 'advanced', duration: 10, outcome: 'Vous saurez expliquer un écart et constituer un contrôle comptable reproductible.' },
  'decision-analysis': { level: 'advanced', duration: 8, outcome: 'Vous saurez relier une tendance à une action mesurable sans surinterpréter un chiffre isolé.' },
  'transfer-pool': { level: 'independent', duration: 7, outcome: 'Vous saurez gérer les voyageurs en transit et affecter leurs places.' },
};

const routeContextLabels = {
  'admin.dashboard': 'Tableau de bord administrateur',
  'admin.trips.index': 'Page Voyages administrateur',
  'seller.dashboard': 'Accueil vendeur',
  'seller.ticketing': 'Page Voyages vendeur',
  'seller.ticketing.horizontal': 'Billetterie horizontale',
  'seller.tickets.index': 'Liste des tickets',
  'supervisor.ticketing': 'Billetterie superviseur',
  'supervisor.dashboard': 'Tour de contrôle',
  'supervisor.control-tower': 'Tour de contrôle',
  'supervisor.settings.index': 'Paramétrage superviseur',
  'supervisor.stations.index': 'Gares supervisées',
  'supervisor.users.index': 'Utilisateurs supervisés',
  'supervisor.assignments.index': 'Affectations des vendeurs',
  'supervisor.compensations.index': 'Compensations et annulations',
  'accountant.reports': 'Page Rapports',
  'executive.analytics': 'Tableau de bord direction',
  'fleet.dashboard': 'Tableau de bord flotte',
  'fleet.vehicles.index': 'Page Véhicules',
  'admin.settings.index': 'Page Paramétrage',
  'admin.ticket-settings.index': 'Paramètres des tickets',
  'profile.edit': 'Mon profil',
  'admin.settings.enterprise': 'Paramètres entreprise',
  'admin.settings.loyalty': 'Paramètres fidélité (Okohi)',
  'admin.destinations.index': 'Gestion des destinations',
  'admin.stations.index': 'Gestion des gares',
  'admin.routes.index': 'Gestion des trajets',
  'admin.routes.stops.index': 'Configuration des arrêts',
  'admin.route-fares.index': 'Gestion des tarifs',
  'admin.users.index': 'Gestion des utilisateurs',
  'admin.assignments.index': 'Affectations des vendeurs',
  'admin.vehicle-types.index': 'Gestion des types de véhicule',
  'admin.vehicles.index': 'Gestion des véhicules',
  'fleet.vehicle-types.index': 'Types de véhicules',
  'fleet.assignments.index': 'Affectations des véhicules',
  'fleet.crew-members.index': 'Membres d’équipage',
  'fleet.crew-assignments.index': 'Affectations des équipages',
  'seller.transfer-pool.index': 'Bassin de correspondances',
};

const roleRouteContextLabels = {
  admin: {
    'seller.ticketing': 'Billetterie administrateur',
    'seller.ticketing.horizontal': 'Billetterie administrateur',
  },
  seller: {
    'seller.ticketing': 'Page Voyages vendeur',
    'seller.ticketing.horizontal': 'Page Voyages vendeur',
  },
  supervisor: {
    'supervisor.ticketing': 'Billetterie superviseur',
  },
};

const enrichTopic = (topic, role = null, context = {}) => {
  const roleVariant = role ? topic.roleVariants?.[role] : null;
  const routeVariant = context.routeName ? topic.routeVariants?.[context.routeName] : null;
  const variant = { ...(roleVariant || {}), ...(routeVariant || {}) };
  const sections = variant.sections
    || [...(variant.prependSections || []), ...(topic.sections || [])];

  return {
    ...topic,
    ...variant,
    sections,
    level: variant.level || topicGuidance[topic.id]?.level || 'independent',
    duration: variant.duration || topicGuidance[topic.id]?.duration || 5,
    outcome: variant.outcome
      || topicGuidance[topic.id]?.outcome
      || `Vous saurez utiliser ${(variant.title || topic.title).toLowerCase()} dans votre travail quotidien.`,
    roleLabel: helpRoleLabels[role] || 'Utilisateur',
    contextLabel: roleRouteContextLabels[role]?.[context.routeName]
      || routeContextLabels[context.routeName]
      || 'Aide adaptée à cette page',
  };
};

export function getHelpTopicsForRole(role, context = {}) {
  return helpTopics
    .filter((topic) => !topic.audience || topic.audience.includes(role))
    .map((topic) => enrichTopic(topic, role, context));
}

export function findHelpTopic({ routeName, path, role }) {
  const context = { routeName, path };
  const availableTopics = getHelpTopicsForRole(role, context);
  const bestMatch = (matches) => matches
    .sort((left, right) => (right.contextPriority || 0) - (left.contextPriority || 0))[0];

  return bestMatch(availableTopics.filter((topic) => topic.routes?.some((route) => route === routeName)))
    || bestMatch(availableTopics.filter((topic) => topic.pathPrefixes?.some((prefix) => path?.startsWith(prefix))))
    || enrichTopic(helpTopics[0], role, context);
}

export function getAllHelpTopics() {
  return helpTopics.map((topic) => enrichTopic(topic));
}

export function getHelpCategories(topics = helpTopics) {
  return [...new Set(topics.map((topic) => topic.category || 'Général'))];
}
