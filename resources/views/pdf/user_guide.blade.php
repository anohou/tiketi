<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guide Utilisateur TIKETI</title>
    <style>
        @page {
            margin: 2cm;
        }
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            line-height: 1.6;
            color: #333;
            font-size: 11pt;
        }
        .header {
            text-align: center;
            margin-bottom: 2cm;
            border-bottom: 2px solid #10b981;
            padding-bottom: 1cm;
        }
        .logo {
            font-size: 28pt;
            font-weight: 900;
            color: #059669;
            letter-spacing: -1px;
            margin-bottom: 10px;
        }
        .subtitle {
            font-size: 14pt;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        h1 {
            color: #059669;
            font-size: 22pt;
            margin-top: 1.5cm;
            border-left: 5px solid #10b981;
            padding-left: 15px;
        }
        h2 {
            color: #374151;
            font-size: 16pt;
            margin-top: 1cm;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 5px;
        }
        .step-number {
            display: inline-block;
            background: #10b981;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            text-align: center;
            line-height: 30px;
            margin-right: 10px;
            font-weight: bold;
        }
        .menu-path {
            display: inline-block;
            background: #f3f4f6;
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 9pt;
            color: #4b5563;
            margin-bottom: 15px;
            font-style: italic;
        }
        ul {
            padding-left: 20px;
        }
        li {
            margin-bottom: 10px;
        }
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 8pt;
            color: #9ca3af;
            border-top: 1px solid #e5e7eb;
            padding-top: 10px;
        }
        .tip {
            background: #ecfdf5;
            border: 1px solid #10b981;
            padding: 15px;
            border-radius: 10px;
            margin-top: 2cm;
        }
        .tip-title {
            font-weight: bold;
            color: #059669;
            margin-bottom: 5px;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo">TIKÊTI</div>
        <div class="subtitle">Guide d'Utilisation Compagnie</div>
    </div>

    <p>Bienvenue sur <strong>TIKETI</strong>, la plateforme tout-en-un pour la gestion de votre billetterie et de votre flotte de transport. Ce guide vous accompagne pas à pas dans le paramétrage initial de votre espace.</p>

    <h1>Étapes de Paramétrage</h1>

    <div class="step">
        <h2><span class="step-number">1</span> Identité & Paramètres Ticket</h2>
        <div class="menu-path">Menu : Paramètres > Paramètres Ticket</div>
        <p>Définissez les informations essentielles qui apparaîtront sur les billets de vos clients :</p>
        <ul>
            <li><strong>Nom de l'entreprise</strong> : Ex: "TSR Transport".</li>
            <li><strong>Coordonnées</strong> : Vos numéros de téléphone pour le service client.</li>
            <li><strong>Messages</strong> : Mentions légales ou promotionnelles en bas de ticket.</li>
            <li><strong>QR Code</strong> : Activez-le pour permettre la validation mobile par vos superviseurs.</li>
        </ul>
    </div>

    <div class="step">
        <h2><span class="step-number">2</span> Gestion des Gares (Stations)</h2>
        <div class="menu-path">Menu : Infrastructure > Gares</div>
        <p>Enregistrez les stations de votre réseau avec leurs positions GPS pour permettre le suivi en temps réel.</p>
    </div>

    <div class="step" style="page-break-before: always;">
        <h2><span class="step-number">3</span> Gestion de la Flotte</h2>
        <div class="menu-path">Menu : Flotte > Types & Véhicules</div>
        <p>Enregistrez vos modèles de bus et affectez les immatriculations physiques à chacun d'eux. N'oubliez pas de configurer le plan de salle (2+2, etc.).</p>
    </div>

    <div class="step">
        <h2><span class="step-number">4</span> Configuration des Lignes</h2>
        <div class="menu-path">Menu : Réseau > Lignes</div>
        <p>Créez vos itinéraires en sélectionnant les gares de départ et d'arrivée, ainsi que toutes les escales intermédiaires.</p>
    </div>

    <div class="step">
        <h2><span class="step-number">5</span> Grille Tarifaire</h2>
        <div class="menu-path">Menu : Réseau > Tarification</div>
        <p>Saisissez le prix des billets pour chaque segment possible de la ligne. TIKETI gère automatiquement les prix entre toutes les escales.</p>
    </div>

    <div class="step">
        <h2><span class="step-number">6</span> Planification des Voyages</h2>
        <div class="menu-path">Menu : Opérations > Voyages</div>
        <p>Programmez les départs concrets en associant une ligne, un véhicule et une heure. Choisissez entre le placement libre ou attribué.</p>
    </div>

    <div class="step">
        <h2><span class="step-number">7</span> Gestion du Personnel</h2>
        <div class="menu-path">Menu : Utilisateurs</div>
        <p>Créez les comptes Vendeurs (affectés à une gare précise) et les comptes Superviseurs pour le contrôle terrain.</p>
    </div>

    <div class="tip">
        <div class="tip-title">💡 Prêt pour la Vente !</div>
        <p>Vos vendeurs peuvent désormais accéder à leur interface de billetterie et commencer à émettre des tickets en temps réel.</p>
    </div>

    <div class="footer">
        Document généré le {{ date('d/m/Y') }} par le système TIKETI • &copy; {{ date('Y') }} TIKETI
    </div>
</body>
</html>
