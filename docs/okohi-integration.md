# Intégration Okohi — Fidélisation des clients

## C'est quoi Okohi ?

Okohi est une application de fidélisation. Elle permet à vos clients de gagner des points ou des visites à chaque voyage. Quand un ticket est imprimé, un QR code est généré dessus. Le client scanne ce QR code avec l'application Okohi et ses points sont crédités automatiquement.

---

## Vue d'ensemble du flux

```
[Guichetier imprime ticket]
        │
        ▼
[QR code généré avec lien Okohi]
        │
        ▼
[Client scanne le QR code avec l'app Okohi]
        │
        ▼
[Okohi appelle Tiketi pour vérifier le ticket]
        │
        ▼
[Tiketi confirme → Okohi crédite les points]
```

---

## Étape 1 — Connecter Okohi à Tiketi (à faire une seule fois)

### Qui fait quoi

| Action | Responsable |
|--------|-------------|
| Générer le code de connexion | Le propriétaire dans l'app Okohi |
| Saisir le code dans Tiketi | L'administrateur Tiketi |

### Procédure

**Dans l'app Okohi :**

1. Allez dans **Modification de l'établissement → Intégration API → Apps Partenaires**
2. Cliquez **Connecter** à côté de *Tiketi*
3. Okohi génère un **code à 4 chiffres** valable 24 heures
4. Notez ce code

**Dans Tiketi :**

1. Connectez-vous en tant qu'administrateur
2. Allez dans **Paramètres → Fidélisation (Okohi)**
3. Saisissez le code à 4 chiffres
4. Cliquez **Connecter**

Tiketi envoie alors automatiquement à Okohi :
- **`verify_url`** — l'URL que Okohi appellera pour vérifier les tickets
- **`delete_url`** — l'URL que Okohi appellera si l'intégration est supprimée depuis son côté

Okohi répond avec l'**URL d'intégration** (ex: `https://okohi.anohou.dev/.../scan/.../s1hkbyhc/{ticket_id}/{amount}/{timestamp}`) que Tiketi stocke en base de données.

L'intégration est maintenant **active**.

---

## Étape 2 — Ce qui se passe à chaque impression de ticket

Quand un guichetier imprime un ticket, Tiketi génère le QR code en remplaçant les placeholders dans l'URL d'intégration :

| Placeholder | Valeur injectée | Exemple |
|-------------|-----------------|---------|
| `{ticket_id}` | Numéro du ticket | `TKT-AXCSQIRO` |
| `{amount}` | Prix en FCFA | `5000` |
| `{timestamp}` | Date Unix de création | `1718880000` |

**Exemple d'URL finale dans le QR code :**
```
https://okohi.anohou.dev/.../scan/.../s1hkbyhc/TKT-AXCSQIRO/5000/1718880000
```

---

## Étape 3 — Vérification du ticket par Okohi

Quand le client scanne le QR code, Okohi appelle Tiketi pour confirmer que le ticket est valide avant de créditer les points.

**Appel Okohi → Tiketi :**
```
GET https://tiketi.ci/api/okohi/verify?tenant=abc&ticket_id=TKT-AXCSQIRO
```

> Le paramètre `tenant` est ajouté automatiquement par Tiketi dans la `verify_url`. Il identifie directement la base de données du bon établissement — **une seule requête SQL**, sans parcourir tous les tenants.

**Réponse Tiketi si le ticket est valide :**
```json
{
  "valid": true,
  "data": {
    "ticket_id": "TKT-AXCSQIRO",
    "amount": 5000,
    "timestamp": 1718880000
  }
}
```

**Réponse Tiketi si le ticket est invalide ou annulé :**
```json
{
  "valid": false,
  "message": "Ticket not found or cancelled"
}
```

Okohi ne crédite les points que si `"valid": true` est présent dans la réponse.

---

## Suppression de l'intégration

La suppression peut être initiée des deux côtés et est **synchronisée automatiquement**.

### Depuis Tiketi

1. Allez dans **Paramètres → Fidélisation (Okohi)**
2. Cliquez **Déconnecter Okohi**
3. Confirmez dans le popup

Tiketi supprime l'intégration en local, puis notifie Okohi via :
```
DELETE https://okohi.anohou.dev/.../api/v1/partner-integrations/revoke
Header: X-Okohi-Integration-Key: s1hkbyhc
```

### Depuis Okohi

Quand le propriétaire supprime l'intégration depuis l'app Okohi, Okohi appelle automatiquement :
```
DELETE https://tiketi.ci/api/okohi/delete
Header: X-Okohi-Integration-Key: s1hkbyhc
```

Tiketi vérifie la clé et supprime l'intégration en base de données.

---

## Configuration serveur (.env)

| Variable | Description | Exemple local | Exemple prod |
|----------|-------------|---------------|--------------|
| `OKOHI_BASE_URL` | URL du serveur Okohi | `http://192.168.0.228:8001` | `https://okohi.anohou.dev/okohi-api-prod-xxxx` |
| `APP_URL` | URL publique de Tiketi (utilisée pour verify_url et delete_url) | `http://192.168.0.228:8006` | `https://tiketi.ci` |

> **Important :** `APP_URL` doit être l'URL accessible depuis l'extérieur (depuis le serveur Okohi). En local, utiliser l'IP LAN et non `localhost`.

---

## Architecture multi-établissements

Tiketi gère plusieurs établissements (tenants) avec des bases de données séparées. Chaque établissement a sa propre intégration Okohi stockée dans sa base (`okohi_integration_url` et `okohi_integration_key`).

Lors de la connexion, Tiketi encode l'identifiant du tenant dans la `verify_url` :
```
https://tiketi.ci/api/okohi/verify?tenant=abc&ticket_id={ticket_id}
```

Ainsi, quand Okohi appelle pour vérifier un ticket, Tiketi sait immédiatement dans quelle base de données chercher — sans avoir à interroger tous les établissements.

---

## Résumé des endpoints exposés par Tiketi

| Endpoint | Méthode | Appelé par | Rôle |
|----------|---------|-----------|------|
| `/api/okohi/verify` | `GET` | Okohi | Vérifier qu'un ticket est valide |
| `/api/okohi/delete` | `DELETE` | Okohi | Supprimer l'intégration (initié depuis Okohi) |

Ces endpoints sont publics (sans authentification) et accessibles via l'IP ou le domaine central de Tiketi.
