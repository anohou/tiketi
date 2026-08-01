const pptxgen = require("pptxgenjs");
const React = require("react");
const ReactDOMServer = require("react-dom/server");
const sharp = require("sharp");

const {
  FaBus, FaChair, FaBluetoothB, FaQrcode, FaMobileAlt, FaCloud,
  FaChartLine, FaCogs, FaTicketAlt, FaUsers, FaBuilding, FaShieldAlt,
  FaSyncAlt, FaLayerGroup, FaThermometerHalf, FaCheckCircle,
  FaArrowRight, FaStar, FaPrint, FaMobile,
} = require("react-icons/fa");

function renderIconSvg(IconComponent, color = "#000000", size = 256) {
  return ReactDOMServer.renderToStaticMarkup(
    React.createElement(IconComponent, { color, size: String(size) })
  );
}

async function iconToBase64Png(IconComponent, color, size = 256) {
  const svg = renderIconSvg(IconComponent, color, size);
  const pngBuffer = await sharp(Buffer.from(svg)).png().toBuffer();
  return "image/png;base64," + pngBuffer.toString("base64");
}

const C = {
  darkNavy: "0F1B2D",
  primary: "1A5276",
  primaryLight: "2980B9",
  accent: "E67E22",
  accentLight: "F39C12",
  success: "27AE60",
  white: "FFFFFF",
  offWhite: "F0F4F8",
  lightGray: "CBD5E1",
  gray: "64748B",
  darkText: "1E293B",
  bodyText: "334155",
  cardBg: "FFFFFF",
};

const FONT_TITLE = "Arial Black";
const FONT_BODY = "Calibri";

const mkShadow = () => ({ type: "outer", color: "000000", blur: 8, offset: 3, angle: 135, opacity: 0.12 });

async function main() {
  const pres = new pptxgen();
  pres.layout = "LAYOUT_16x9";
  pres.author = "TIKETI";
  pres.title = "TIKETI - Présentation Commerciale";

  const iconTicket = await iconToBase64Png(FaTicketAlt, C.white);
  const iconBus = await iconToBase64Png(FaBus, C.white);
  const iconChart = await iconToBase64Png(FaChartLine, C.white);
  const iconChair = await iconToBase64Png(FaChair, C.white);
  const iconBluetooth = await iconToBase64Png(FaBluetoothB, C.white);
  const iconQr = await iconToBase64Png(FaQrcode, C.white);
  const iconMobile = await iconToBase64Png(FaMobileAlt, C.white);
  const iconCloud = await iconToBase64Png(FaCloud, C.white);
  const iconCogs = await iconToBase64Png(FaCogs, C.white);
  const iconUsers = await iconToBase64Png(FaUsers, C.white);
  const iconBuilding = await iconToBase64Png(FaBuilding, C.white);
  const iconShield = await iconToBase64Png(FaShieldAlt, C.white);
  const iconSync = await iconToBase64Png(FaSyncAlt, C.white);
  const iconLayer = await iconToBase64Png(FaLayerGroup, C.white);
  const iconCheck = await iconToBase64Png(FaCheckCircle, C.success);
  const iconArrow = await iconToBase64Png(FaArrowRight, C.primaryLight);
  const iconStar = await iconToBase64Png(FaStar, C.accent);
  const iconPrint = await iconToBase64Png(FaPrint, C.white);
  const iconMobileFull = await iconToBase64Png(FaMobile, C.white);
  const iconThermo = await iconToBase64Png(FaThermometerHalf, C.accent);

  // ========================================
  // SLIDE 1 — Title
  // ========================================
  {
    const slide = pres.addSlide();
    slide.background = { color: C.darkNavy };

    slide.addShape(pres.shapes.RECTANGLE, {
      x: 0, y: 0, w: 10, h: 0.08, fill: { color: C.accent },
    });

    slide.addText("TIKETI", {
      x: 0.8, y: 1.2, w: 8.4, h: 1.0,
      fontSize: 54, fontFace: FONT_TITLE, color: C.white,
      bold: true, margin: 0,
    });

    slide.addText("Plateforme de billetterie transport\nintelligente et multi-tenant", {
      x: 0.8, y: 2.3, w: 7, h: 1.0,
      fontSize: 20, fontFace: FONT_BODY, color: C.lightGray,
      margin: 0,
    });

    slide.addText("De la vente au guichet jusqu'au contrôle à l'embarquement,\n pilotez vos lignes, vos correspondances et chaque siège en temps réel.", {
      x: 0.8, y: 3.4, w: 8.4, h: 1.0,
      fontSize: 13, fontFace: FONT_BODY, color: C.gray,
      margin: 0,
    });

    slide.addImage({ data: iconTicket, x: 8.2, y: 1.3, w: 1.0, h: 1.0 });

    slide.addText("Une seule plateforme pour fluidifier le voyage et sécuriser vos revenus.", {
      x: 0.8, y: 4.6, w: 8.4, h: 0.5,
      fontSize: 12, fontFace: FONT_BODY, color: C.gray, italic: true,
      margin: 0,
    });
  }

  // ========================================
  // SLIDE 2 — Le constat
  // ========================================
  {
    const slide = pres.addSlide();
    slide.background = { color: C.offWhite };

    slide.addShape(pres.shapes.RECTANGLE, {
      x: 0, y: 0, w: 0.08, h: 5.625, fill: { color: C.accent },
    });

    slide.addText("LE CONSTAT", {
      x: 0.8, y: 0.4, w: 8.4, h: 0.6,
      fontSize: 32, fontFace: FONT_TITLE, color: C.darkNavy, bold: true, margin: 0,
    });

    const painPoints = [
      { title: "Ventes manuelles", desc: "Billets papier, files d'attente, erreurs de caisse et doublons de sièges." },
      { title: "Aucune visibilité en temps réel", desc: "Impossible de savoir combien de places sont vendues sur un voyage avant le départ." },
      { title: "Gestion éclatée", desc: "Chaque gare opère en silo, sans coordination des correspondances ni répartition des ventes." },
    ];

    const startY = 1.4;
    const cardH = 1.1;
    const cardGap = 0.2;

    painPoints.forEach((p, i) => {
      const cy = startY + i * (cardH + cardGap);
      slide.addShape(pres.shapes.RECTANGLE, {
        x: 0.8, y: cy, w: 8.4, h: cardH,
        fill: { color: C.cardBg }, shadow: mkShadow(),
      });
      slide.addShape(pres.shapes.RECTANGLE, {
        x: 0.8, y: cy, w: 0.07, h: cardH, fill: { color: C.primary },
      });
      slide.addImage({ data: iconStar, x: 1.1, y: cy + 0.25, w: 0.5, h: 0.5 });
      slide.addText(p.title, {
        x: 1.8, y: cy + 0.15, w: 7.2, h: 0.4,
        fontSize: 18, fontFace: FONT_BODY, color: C.darkNavy, bold: true, margin: 0,
      });
      slide.addText(p.desc, {
        x: 1.8, y: cy + 0.55, w: 7.0, h: 0.45,
        fontSize: 13, fontFace: FONT_BODY, color: C.bodyText, margin: 0,
      });
    });
  }

  // ========================================
  // SLIDE 3 — Solution Overview
  // ========================================
  {
    const slide = pres.addSlide();
    slide.background = { color: C.primary };

    slide.addText("TIKETI, la solution", {
      x: 0.8, y: 0.4, w: 8.4, h: 0.7,
      fontSize: 36, fontFace: FONT_TITLE, color: C.white, bold: true, margin: 0,
    });

    slide.addText("Une plateforme SaaS centralisée qui connecte l'ensemble de vos gares\net vous donne une visibilité complète sur votre activité.", {
      x: 0.8, y: 1.2, w: 8.4, h: 0.8,
      fontSize: 15, fontFace: FONT_BODY, color: C.lightGray, margin: 0,
    });

    const features = [
      { icon: iconBus, label: "Vente guichet\nintelligente" },
      { icon: iconSync, label: "Synchronisation\ntemps réel" },
      { icon: iconLayer, label: "Correspondances\nmulti-voyages" },
      { icon: iconChart, label: "Tableaux de bord\n& reportings" },
    ];

    const cardW = 2.0;
    const cardH = 2.0;
    const totalW = features.length * cardW + (features.length - 1) * 0.2;
    const startX = (10 - totalW) / 2;
    const cardY = 2.5;

    features.forEach((f, i) => {
      const cx = startX + i * (cardW + 0.2);
      slide.addShape(pres.shapes.RECTANGLE, {
        x: cx, y: cardY, w: cardW, h: cardH,
        fill: { color: C.white, transparency: 10 }, shadow: mkShadow(),
      });
      slide.addImage({ data: f.icon, x: cx + 0.65, y: cardY + 0.3, w: 0.7, h: 0.7 });
      slide.addText(f.label, {
        x: cx + 0.15, y: cardY + 1.2, w: cardW - 0.3, h: 0.7,
        fontSize: 13, fontFace: FONT_BODY, color: C.white, align: "center", margin: 0,
      });
    });
  }

  // ========================================
  // SLIDE 4 — Modules clés
  // ========================================
  {
    const slide = pres.addSlide();
    slide.background = { color: C.offWhite };

    slide.addShape(pres.shapes.RECTANGLE, {
      x: 0, y: 0, w: 0.08, h: 5.625, fill: { color: C.accent },
    });

    slide.addText("MODULES CLÉS", {
      x: 0.8, y: 0.3, w: 8.4, h: 0.6,
      fontSize: 30, fontFace: FONT_TITLE, color: C.darkNavy, bold: true, margin: 0,
    });

    const modules = [
      { icon: iconTicket, title: "Billetterie", desc: "Plan de bus interactif, suggestion automatique des meilleures places, vente multi-quantité." },
      { icon: iconBus, title: "Voyages", desc: "Planification, duplication quotidienne, contrôle des ventes par segment." },
      { icon: iconLayer, title: "Correspondances", desc: "Billets combinés avec changement de véhicule, suivi du passager en transit." },
      { icon: iconCogs, title: "Administration", desc: "Configuration des gares, routes, tarifs, véhicules et utilisateurs." },
      { icon: iconChart, title: "Supervision", desc: "Tour de contrôle multi-gares, alertes opérationnelles, départs." },
      { icon: iconBuilding, title: "Comptabilité", desc: "Rapports financiers, rapprochement de caisse, exportations." },
    ];

    const cardWMod = 4.2;
    const cardHMod = 1.25;
    const gapX = 0.4;
    const gapY = 0.25;
    const startXMod = 0.8;
    const startYMod = 1.2;

    modules.forEach((m, i) => {
      const col = i % 2;
      const row = Math.floor(i / 2);
      const cx = startXMod + col * (cardWMod + gapX);
      const cy = startYMod + row * (cardHMod + gapY);

      slide.addShape(pres.shapes.RECTANGLE, {
        x: cx, y: cy, w: cardWMod, h: cardHMod,
        fill: { color: C.cardBg }, shadow: mkShadow(),
      });
      slide.addShape(pres.shapes.RECTANGLE, {
        x: cx, y: cy, w: 0.06, h: cardHMod, fill: { color: C.primary },
      });
      slide.addImage({ data: m.icon, x: cx + 0.25, y: cy + 0.3, w: 0.55, h: 0.55 });
      slide.addText(m.title, {
        x: cx + 1.0, y: cy + 0.15, w: 3.0, h: 0.4,
        fontSize: 16, fontFace: FONT_BODY, color: C.darkNavy, bold: true, margin: 0,
      });
      slide.addText(m.desc, {
        x: cx + 1.0, y: cy + 0.55, w: 3.0, h: 0.6,
        fontSize: 11, fontFace: FONT_BODY, color: C.bodyText, margin: 0,
      });
    });
  }

  // ========================================
  // SLIDE 5 — Attribution intelligente
  // ========================================
  {
    const slide = pres.addSlide();
    slide.background = { color: C.darkNavy };

    slide.addShape(pres.shapes.RECTANGLE, {
      x: 0, y: 0, w: 10, h: 0.08, fill: { color: C.accent },
    });

    slide.addImage({ data: iconChair, x: 0.8, y: 0.5, w: 0.7, h: 0.7 });
    slide.addText("ATTRIBUTION INTELLIGENTE DES SIÈGES", {
      x: 1.7, y: 0.5, w: 7.5, h: 0.7,
      fontSize: 26, fontFace: FONT_TITLE, color: C.white, bold: true, margin: 0,
    });

    slide.addText("Notre algorithme propriétaire suggère automatiquement le meilleur siège pour chaque passager, en optimisant le remplissage du véhicule.", {
      x: 0.8, y: 1.3, w: 8.4, h: 0.6,
      fontSize: 14, fontFace: FONT_BODY, color: C.lightGray, margin: 0,
    });

    const algos = [
      { label: "Proximité de destination", desc: "Regroupe les passagers par gare d'arrivée pour minimiser les dérangements." },
      { label: "Anti-blocage", desc: "Évite de coincer un passager côté fenêtre quand un siège couloir est libre." },
      { label: "Zonage intelligent", desc: "Avant du bus pour les longues distances, arrière pour les courts trajets." },
      { label: "Groupes voyageurs", desc: "Place les familles et groupes sur des rangées contiguës." },
    ];

    const startYAlgo = 2.2;
    const algoH = 0.7;
    const algoGap = 0.15;

    algos.forEach((a, i) => {
      const cy = startYAlgo + i * (algoH + algoGap);
      slide.addShape(pres.shapes.RECTANGLE, {
        x: 0.8, y: cy, w: 4.0, h: algoH,
        fill: { color: C.white, transparency: 10 },
        shadow: mkShadow(),
      });
      slide.addImage({ data: iconCheck, x: 1.0, y: cy + 0.15, w: 0.35, h: 0.35 });
      slide.addText(a.label, {
        x: 1.5, y: cy + 0.05, w: 3.1, h: 0.3,
        fontSize: 13, fontFace: FONT_BODY, color: C.white, bold: true, margin: 0,
      });
      slide.addText(a.desc, {
        x: 1.5, y: cy + 0.35, w: 3.1, h: 0.3,
        fontSize: 10, fontFace: FONT_BODY, color: C.lightGray, margin: 0,
      });
    });

    slide.addShape(pres.shapes.RECTANGLE, {
      x: 5.5, y: 2.2, w: 3.7, h: 2.5,
      fill: { color: C.primaryLight, transparency: 15 },
      shadow: mkShadow(),
    });
    slide.addText("+30%", {
      x: 5.5, y: 2.5, w: 3.7, h: 0.9,
      fontSize: 48, fontFace: FONT_TITLE, color: C.accent, bold: true, align: "center", margin: 0,
    });
    slide.addText("de remplissage moyen\npar trajet", {
      x: 5.5, y: 3.4, w: 3.7, h: 0.6,
      fontSize: 14, fontFace: FONT_BODY, color: C.white, align: "center", margin: 0,
    });
    slide.addText("Contre 15% avec une\nattribution manuelle", {
      x: 5.5, y: 4.0, w: 3.7, h: 0.5,
      fontSize: 10, fontFace: FONT_BODY, color: C.gray, align: "center", margin: 0,
    });
  }

  // ========================================
  // SLIDE 6 — Vente & Billetterie
  // ========================================
  {
    const slide = pres.addSlide();
    slide.background = { color: C.offWhite };

    slide.addShape(pres.shapes.RECTANGLE, {
      x: 0, y: 0, w: 0.08, h: 5.625, fill: { color: C.accent },
    });

    slide.addText("VENTE & BILLETTERIE", {
      x: 0.8, y: 0.3, w: 8.4, h: 0.6,
      fontSize: 30, fontFace: FONT_TITLE, color: C.darkNavy, bold: true, margin: 0,
    });

    const featuresSell = [
      { icon: iconTicket, title: "Plan de bus interactif", desc: "Visualisez en un coup d'œil les sièges occupés et disponibles, avec code couleur par destination." },
      { icon: iconChair, title: "Suggestion automatique", desc: "L'algorithme propose la meilleure place dès la sélection de la destination." },
      { icon: iconPrint, title: "Impression thermique Bluetooth", desc: "Imprimez les tickets directement depuis le navigateur vers une imprimante thermique ESC/POS." },
      { icon: iconMobile, title: "Vente multi-quantité", desc: "Vendez jusqu'à 10 places en une seule transaction, avec attribution groupée." },
    ];

    const startY2 = 1.2;
    const cardH2 = 0.95;
    const gap2 = 0.15;

    featuresSell.forEach((f, i) => {
      const cy = startY2 + i * (cardH2 + gap2);
      slide.addShape(pres.shapes.RECTANGLE, {
        x: 0.8, y: cy, w: 8.4, h: cardH2,
        fill: { color: C.cardBg }, shadow: mkShadow(),
      });
      slide.addImage({ data: f.icon, x: 1.1, y: cy + 0.2, w: 0.5, h: 0.5 });
      slide.addText(f.title, {
        x: 1.8, y: cy + 0.1, w: 7.0, h: 0.35,
        fontSize: 16, fontFace: FONT_BODY, color: C.darkNavy, bold: true, margin: 0,
      });
      slide.addText(f.desc, {
        x: 1.8, y: cy + 0.45, w: 7.0, h: 0.4,
        fontSize: 12, fontFace: FONT_BODY, color: C.bodyText, margin: 0,
      });
    });
  }

  // ========================================
  // SLIDE 7 — Optimisation par segment
  // ========================================
  {
    const slide = pres.addSlide();
    slide.background = { color: C.primary };

    slide.addText("OPTIMISATION PAR SEGMENT", {
      x: 0.8, y: 0.4, w: 8.4, h: 0.7,
      fontSize: 30, fontFace: FONT_TITLE, color: C.white, bold: true, margin: 0,
    });

    slide.addText("Un même siège peut être vendu à plusieurs passagers sur des segments non chevauchants du même voyage.", {
      x: 0.8, y: 1.2, w: 8.4, h: 0.5,
      fontSize: 14, fontFace: FONT_BODY, color: C.lightGray, margin: 0,
    });

    // Visual segment diagram
    const segY = 2.0;
    const segH = 0.5;
    const segW = 2.4;
    const segGap = 0.15;
    const segStartX = 0.8;

    const segments = [
      { label: "Abidjan → Yamoussoukro", color: "27AE60" },
      { label: "Yamoussoukro → Bouaké", color: "2980B9" },
      { label: "Bouaké → Korhogo", color: "E67E22" },
    ];

    segments.forEach((s, i) => {
      const sx = segStartX + i * (segW + segGap);
      slide.addShape(pres.shapes.RECTANGLE, {
        x: sx, y: segY, w: segW, h: segH,
        fill: { color: s.color },
      });
      slide.addText(s.label, {
        x: sx, y: segY, w: segW, h: segH,
        fontSize: 11, fontFace: FONT_BODY, color: C.white, align: "center", valign: "middle", margin: 0,
      });
    });

    slide.addText("Siège 12", {
      x: segStartX + 0.3, y: segY + segH + 0.15, w: 8.4, h: 0.4,
      fontSize: 14, fontFace: FONT_BODY, color: C.white, bold: true, margin: 0,
    });
    slide.addText("Vendu 3 fois sur le même voyage — un revenue maximum par trajet.", {
      x: segStartX + 0.3, y: segY + segH + 0.55, w: 8, h: 0.4,
      fontSize: 12, fontFace: FONT_BODY, color: C.lightGray, margin: 0,
    });

    // Revenue callout
    slide.addShape(pres.shapes.RECTANGLE, {
      x: 0.8, y: 3.6, w: 4.0, h: 1.3,
      fill: { color: C.white, transparency: 10 },
      shadow: mkShadow(),
    });
    slide.addText("+40%", {
      x: 0.8, y: 3.7, w: 4.0, h: 0.7,
      fontSize: 40, fontFace: FONT_TITLE, color: C.accent, bold: true, align: "center", margin: 0,
    });
    slide.addText("de revenu par trajet\ngrâce à la revente par segment", {
      x: 0.8, y: 4.3, w: 4.0, h: 0.5,
      fontSize: 12, fontFace: FONT_BODY, color: C.white, align: "center", margin: 0,
    });

    slide.addImage({ data: iconThermo, x: 5.5, y: 3.7, w: 3.5, h: 1.0 });
  }

  // ========================================
  // SLIDE 8 — Correspondances
  // ========================================
  {
    const slide = pres.addSlide();
    slide.background = { color: C.offWhite };

    slide.addShape(pres.shapes.RECTANGLE, {
      x: 0, y: 0, w: 0.08, h: 5.625, fill: { color: C.accent },
    });

    slide.addText("CORRESPONDANCES MULTI-VOYAGES", {
      x: 0.8, y: 0.3, w: 8.4, h: 0.6,
      fontSize: 28, fontFace: FONT_TITLE, color: C.darkNavy, bold: true, margin: 0,
    });

    slide.addText("Un billet unique pour un voyage avec changement de véhicule. Le système gère l'intégralité du parcours passager.", {
      x: 0.8, y: 1.0, w: 8.4, h: 0.5,
      fontSize: 13, fontFace: FONT_BODY, color: C.bodyText, margin: 0,
    });

    const connItems = [
      { icon: iconTicket, title: "Billet unique", desc: "Le passager achète un seul billet pour l'ensemble de son parcours, même avec changement de bus." },
      { icon: iconUsers, title: "Suivi en transit", desc: "Le système suit le passager à la gare de correspondance et valide sa présence." },
      { icon: iconChair, title: "Place réservée", desc: "Un siège est automatiquement attribué sur le voyage de correspondance." },
      { icon: iconShield, title: "Anti-conflit", desc: "Détection des conflits d'horaires, réattribution automatique en cas de retard." },
    ];

    const startY3 = 1.7;
    const cardH3 = 0.82;
    const gap3 = 0.12;

    connItems.forEach((item, i) => {
      const cy = startY3 + i * (cardH3 + gap3);
      slide.addShape(pres.shapes.RECTANGLE, {
        x: 0.8, y: cy, w: 8.4, h: cardH3,
        fill: { color: C.cardBg }, shadow: mkShadow(),
      });
      slide.addImage({ data: item.icon, x: 1.1, y: cy + 0.15, w: 0.45, h: 0.45 });
      slide.addText(item.title, {
        x: 1.75, y: cy + 0.08, w: 7.0, h: 0.3,
        fontSize: 15, fontFace: FONT_BODY, color: C.darkNavy, bold: true, margin: 0,
      });
      slide.addText(item.desc, {
        x: 1.75, y: cy + 0.4, w: 7.0, h: 0.35,
        fontSize: 11, fontFace: FONT_BODY, color: C.bodyText, margin: 0,
      });
    });
  }

  // ========================================
  // SLIDE 9 — Écosystème
  // ========================================
  {
    const slide = pres.addSlide();
    slide.background = { color: C.darkNavy };

    slide.addShape(pres.shapes.RECTANGLE, {
      x: 0, y: 0, w: 10, h: 0.08, fill: { color: C.accent },
    });

    slide.addText("ÉCOSYSTÈME COMPLET", {
      x: 0.8, y: 0.4, w: 8.4, h: 0.6,
      fontSize: 28, fontFace: FONT_TITLE, color: C.white, bold: true, margin: 0,
    });

    const ecoItems = [
      { icon: iconPrint, title: "Impression Bluetooth", desc: "Impression thermique directe ESC/POS. Bascule automatique vers l'impression navigateur." },
      { icon: iconQr, title: "Fidélité OKOHI", desc: "QR codes sur les tickets, accumulation de points et récompenses via l'application OKOHI." },
      { icon: iconMobileFull, title: "Tiketi Control", desc: "Application mobile embarquement : scan de QR, manifeste numérique, mode hors-ligne." },
      { icon: iconCloud, title: "Synchronisation temps réel", desc: "WebSockets Laravel Reverb : toute vente est instantanément répercutée sur toutes les gares." },
    ];

    ecoItems.forEach((e, i) => {
      const col = i % 2;
      const row = Math.floor(i / 2);
      const cx = 0.8 + col * 4.6;
      const cy = 1.3 + row * 1.9;

      slide.addShape(pres.shapes.RECTANGLE, {
        x: cx, y: cy, w: 4.2, h: 1.6,
        fill: { color: C.white, transparency: 10 },
        shadow: mkShadow(),
      });
      slide.addImage({ data: e.icon, x: cx + 0.25, y: cy + 0.3, w: 0.5, h: 0.5 });
      slide.addText(e.title, {
        x: cx + 0.95, y: cy + 0.15, w: 3.0, h: 0.35,
        fontSize: 15, fontFace: FONT_BODY, color: C.white, bold: true, margin: 0,
      });
      slide.addText(e.desc, {
        x: cx + 0.95, y: cy + 0.55, w: 3.0, h: 0.85,
        fontSize: 11, fontFace: FONT_BODY, color: C.lightGray, margin: 0,
      });
    });
  }

  // ========================================
  // SLIDE 10 — Multi-tenant SaaS
  // ========================================
  {
    const slide = pres.addSlide();
    slide.background = { color: C.offWhite };

    slide.addShape(pres.shapes.RECTANGLE, {
      x: 0, y: 0, w: 0.08, h: 5.625, fill: { color: C.accent },
    });

    slide.addText("ARCHITECTURE MULTI-TENANT", {
      x: 0.8, y: 0.3, w: 8.4, h: 0.6,
      fontSize: 28, fontFace: FONT_TITLE, color: C.darkNavy, bold: true, margin: 0,
    });

    slide.addText("Chaque compagnie de transport dispose de sa propre base de données isolée, sur une plateforme mutualisée.", {
      x: 0.8, y: 1.0, w: 8.4, h: 0.5,
      fontSize: 13, fontFace: FONT_BODY, color: C.bodyText, margin: 0,
    });

    const saasItems = [
      { icon: iconBuilding, title: "Isolation totale", desc: "Données de chaque client strictement séparées. Pas de risque de fuite entre tenants." },
      { icon: iconCogs, title: "Configuration flexible", desc: "Chaque compagnie personnalise ses gares, routes, tarifs et véhicules." },
      { icon: iconShield, title: "Sécurité renforcée", desc: "Rôle dédié provisionner, rôle backup lecture-seule, accès par domaine." },
      { icon: iconChart, title: "Évolutivité", desc: "Infrastructure scalable par tenant. Pas d'impact des pics d'un client sur les autres." },
    ];

    const startY4 = 1.7;
    const cardH4 = 0.85;
    const gap4 = 0.1;

    saasItems.forEach((s, i) => {
      const cy = startY4 + i * (cardH4 + gap4);
      slide.addShape(pres.shapes.RECTANGLE, {
        x: 0.8, y: cy, w: 8.4, h: cardH4,
        fill: { color: C.cardBg }, shadow: mkShadow(),
      });
      slide.addShape(pres.shapes.RECTANGLE, {
        x: 0.8, y: cy, w: 0.06, h: cardH4, fill: { color: C.primary },
      });
      slide.addImage({ data: s.icon, x: 1.1, y: cy + 0.18, w: 0.45, h: 0.45 });
      slide.addText(s.title, {
        x: 1.75, y: cy + 0.1, w: 7.0, h: 0.3,
        fontSize: 15, fontFace: FONT_BODY, color: C.darkNavy, bold: true, margin: 0,
      });
      slide.addText(s.desc, {
        x: 1.75, y: cy + 0.42, w: 7.0, h: 0.35,
        fontSize: 11, fontFace: FONT_BODY, color: C.bodyText, margin: 0,
      });
    });
  }

  // ========================================
  // SLIDE 11 — CTA / Contact
  // ========================================
  {
    const slide = pres.addSlide();
    slide.background = { color: C.primary };

    slide.addText("PRÊT À MODERNISER\nVOTRE BILLETTERIE ?", {
      x: 0.8, y: 1.0, w: 8.4, h: 1.5,
      fontSize: 38, fontFace: FONT_TITLE, color: C.white, bold: true, align: "center", margin: 0,
    });

    slide.addText("Rejoignez les transporteurs qui optimisent leurs ventes,\nsécurisent leurs revenus et fluidifient l'expérience voyageur.", {
      x: 0.8, y: 2.6, w: 8.4, h: 0.8,
      fontSize: 14, fontFace: FONT_BODY, color: C.lightGray, align: "center", margin: 0,
    });

    slide.addShape(pres.shapes.RECTANGLE, {
      x: 3.0, y: 3.7, w: 4.0, h: 0.7,
      fill: { color: C.accent }, shadow: mkShadow(),
    });
    slide.addText("CONTACTEZ-NOUS", {
      x: 3.0, y: 3.7, w: 4.0, h: 0.7,
      fontSize: 18, fontFace: FONT_BODY, color: C.white, bold: true, align: "center", valign: "middle", margin: 0,
    });

    slide.addText("contact@tiketi.com  ·  www.tiketi.com", {
      x: 0.8, y: 4.6, w: 8.4, h: 0.4,
      fontSize: 12, fontFace: FONT_BODY, color: C.gray, align: "center", margin: 0,
    });

    slide.addImage({ data: iconStar, x: 4.5, y: 0.6, w: 1.0, h: 1.0 });
  }

  // ========================================
  // Write file
  // ========================================
  await pres.writeFile({ fileName: "TIKETI_Presentation_Commerciale.pptx" });
  console.log("✅ Presentation created: TIKETI_Presentation_Commerciale.pptx");
}

main().catch(console.error);
