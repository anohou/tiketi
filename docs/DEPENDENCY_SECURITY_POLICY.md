# Politique de sécurité des dépendances

## Seuil de blocage

Une vulnérabilité de sévérité `high` ou `critical` dans une dépendance de production bloque le build pilote, sauf dérogation écrite précisant le composant, l’exposition réelle, les mesures compensatoires, le responsable et une date d’expiration.

Commandes de référence :

```bash
composer audit --locked
npm audit --omit=dev --audit-level=high
```

Le contrôle est intégré à `scripts/quality-checks-ci.sh`. Les deux applications exposent également `npm run security:audit`.

## Décisions du 17 juillet 2026

- Backend PHP : aucun avis de sécurité connu.
- Interface web : `xlsx`/SheetJS supprimé en raison de deux avis élevés sans correctif npm. L’export utilise désormais un CSV UTF-8 compatible Excel avec neutralisation des formules.
- Application équipage Expo 54 : alertes modérées transitives dans la chaîne de build Expo. `npm audit fix --force` imposerait Expo 57 ; cette montée majeure est différée jusqu’à une campagne Android/iOS dédiée.

## Mise à niveau Expo

La montée Expo doit être réalisée sur une branche dédiée avec : compilation native Android/iOS, caméra QR, SecureStore, file hors ligne, reprise réseau, notifications éventuelles et test sur au moins un appareil physique de chaque plateforme. Une correction forcée du lockfile sans ces tests n’est pas autorisée.
