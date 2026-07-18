# Frontière de l’API publique Tiketi

## Catalogue public intentionnel

Les seuls endpoints métier accessibles sans authentification sont :

- `GET /api/routes`
- `GET /api/routes/{id}`
- `GET /api/trips/{route_id}/{date}`

Ils exposent uniquement les lignes actives, les arrêts, les tarifs actifs et les horaires commercialisables. Le DTO horaire ne contient ni nombre de billets, ni occupation, ni identifiant interne du véhicule, ni plan de sièges. Ces réponses sont limitées à 60 requêtes par minute et disposent d’un cache public court.

## Données opérationnelles authentifiées

Les ressources suivantes exigent un compte interne actif et refusent explicitement les jetons équipage :

- inventaire, types et plans des véhicules ;
- détail interne d’un voyage ;
- suggestions de sièges et statistiques d’occupation ;
- création, consultation, annulation et export des tickets ;
- statistiques du tableau de bord.

Les rôles sont vérifiés séparément pour la vente, la flotte, les exports financiers et les statistiques. La limite est de 120 requêtes par minute et par compte/tenant.

## Canal équipage

Les jetons possédant la capacité `crew` utilisent exclusivement `/api/crew/*`. Ils ne peuvent pas être employés sur l’API générale, même lorsque le jeton Sanctum est techniquement valide.

## Okohi — décision différée

`GET /api/okohi/verify` et `DELETE /api/okohi/delete` restent dans leur état actuel. La signature HMAC, le timestamp et le nonce sont volontairement reportés par décision produit. Ils devront faire l’objet d’un chantier séparé avant le pilote public.
