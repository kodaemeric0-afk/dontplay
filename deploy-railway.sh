#!/bin/bash
set -e

# ═══════════════════════════════════════════════════════════════
# Dontplay — Rail way Deployment Script
# Sécurisé et automatisé
# ═══════════════════════════════════════════════════════════════

echo "🚀 Démarrage du déploiement Dontplay sur Railway..."
echo ""

# ── Vérifier les prérequis ─────────────────────────────────
if ! command -v railway &> /dev/null; then
    echo "❌ Railway CLI non trouvé. Installation..."
    npm install -g @railway/cli
fi

echo "✓ Railway CLI présent"

# ── Vérifier la configuration ──────────────────────────────
if [ ! -f ".env.production" ]; then
    echo "❌ .env.production manquant!"
    exit 1
fi

if [ ! -f "railway.json" ]; then
    echo "❌ railway.json manquant!"
    exit 1
fi

echo "✓ Fichiers de config présents"

# ── Vérifier node_modules ──────────────────────────────────
if [ ! -d "node_modules" ]; then
    echo "📦 Installation des dépendances..."
    npm install --production
fi

echo "✓ Dépendances installées"

# ── Tests syntaxe ─────────────────────────────────────────
echo ""
echo "🔍 Vérification de la syntaxe Node.js..."
node -c server.js && echo "✓ server.js OK" || (echo "❌ Erreur syntaxe server.js!"; exit 1)
node -c config.js && echo "✓ config.js OK" || (echo "❌ Erreur syntaxe config.js!"; exit 1)
node -c db.js && echo "✓ db.js OK" || (echo "❌ Erreur syntaxe db.js!"; exit 1)

# ── Déployer sur Railway ────────────────────────────────
echo ""
echo "📤 Push vers Railway..."

# Utiliser railway pour le déploiement
railway link  # S'assurer qu'on est lié au projet
railway up    # Pousser et déployer

echo ""
echo "✓ Déploiement lancé!"
echo "⏳ Vérification du statut en cours..."
sleep 10

# ── Vérifier l'état du déploiement ─────────────────────
railway logs --tail 50

echo ""
echo "✅ Déploiement Dontplay complété!"
echo ""
echo "📍 URL de production: https://dontplay-panel-production.up.railway.app"
echo ""
