# 🧪 Système de Paiement - Guide de Test

## État actuel ✅

Votre système de paiement est **95% complet** ! Voici ce qui est en place :

### Backend (Server.js)
- ✅ Routes API pour plans (`/api/plans`, `/api/plans/purchase`)
- ✅ Routes crypto avec NowPayments (`/api/payments/crypto/*`)
- ✅ Webhook IPN (`/api/payments/crypto/webhook`)
- ✅ Gestion de la base de données avec transactions
- ✅ Sécurité CSRF sur tous les paiements

### Frontend (HTML/JS)
- ✅ Page Plans (plans.html) - sélection et achat
- ✅ Modal paiement plan (débit du solde)
- ✅ Page Compte (compte.html) - recharge crypto
- ✅ Modal recharge crypto avec QR code
- ✅ Estimateur de taux de change
- ✅ Polling du statut de paiement
- ✅ Copie d'adresse crypto

### Base de données
- ✅ Structure utilisateur avec balance et transactions
- ✅ Transactions atomiques pour éviter les race conditions
- ✅ Historique des paiements en attente

---

## 🧪 Flux de Test Complet

### Test 1 : Vérifier les routes API

```bash
# 1. Authentification
curl -X POST http://localhost:3000/api/auth/register \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: <csrf_token>" \
  -d '{"username":"testuser123","password":"Test@1234pass","confirmPassword":"Test@1234pass"}'

# Réponse attendue : {"success":true,"redirect":"/dashboard"}

# 2. Récupérer l'utilisateur
curl -X GET http://localhost:3000/api/auth/me \
  -H "Cookie: dmskeujdlsodkejfns5638209DksncHD.sid=..."

# Réponse attendue : {
#   "id":"...",
#   "username":"testuser123",
#   "balance":0,
#   "totalSpent":0,
#   "plan":null,
#   "transactions":[],
#   ...
# }

# 3. Récupérer les plans
curl -X GET http://localhost:3000/api/plans

# Réponse attendue : {
#   "starter":{"id":"starter","name":"Starter","price":150,...},
#   "premium":{"id":"premium","name":"Premium","price":230,...},
#   "max":{"id":"max","name":"Max","price":399,...}
# }

# 4. Récupérer les devises supportées
curl -X GET http://localhost:3000/api/payments/crypto/currencies

# Réponse attendue : {
#   "currencies":["btc","eth","sol","ltc"]
# }

# 5. Estimer montant crypto
curl -X POST http://localhost:3000/api/payments/crypto/estimate \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: <csrf_token>" \
  -d '{"amount":50,"currency":"btc"}'

# Réponse attendue : {
#   "estimatedAmount":"0.00123456",
#   "currency":"btc"
# }

# 6. Créer un paiement
curl -X POST http://localhost:3000/api/payments/crypto/create \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: <csrf_token>" \
  -d '{"amount":50,"currency":"btc"}'

# Réponse attendue : {
#   "success":true,
#   "payment":{
#     "id":"...",
#     "orderId":"dontplay_...",
#     "amount":50,
#     "currency":"btc",
#     "cryptoAmount":"0.00123456",
#     "cryptoAddress":"1A1z7agoat...",
#     "status":"waiting",
#     "invoiceUrl":"...",
#     "expiresAt":"2026-05-26T12:34:56.000Z"
#   }
# }

# 7. Vérifier le statut du paiement
curl -X GET http://localhost:3000/api/payments/crypto/status/<paymentId>

# Réponse attendue : {
#   "paymentId":"...",
#   "status":"waiting|confirming|finished",
#   "cryptoAmount":"0.00123456",
#   "actuallyPaid":"0.00123456",
#   "confirmed":false|true
# }
```

### Test 2 : Workflow utilisateur dans le navigateur

#### 2a. Recharge de solde (Crypto)

1. Aller à `http://localhost:3000/compte`
2. Scroller jusqu'à "Recharger votre solde"
3. Cliquer sur "Recharger (Crypto)"
4. Sélectionner une devise (Bitcoin, Ethereum, etc.)
5. Entrer un montant (minimum 1€)
6. Cliquer "Continuer"
7. Vérifier que :
   - QR code s'affiche
   - Adresse crypto s'affiche
   - Le polling lance automatiquement la vérification

#### 2b. Achat d'un plan

1. Aller à `http://localhost:3000/plans`
2. Voir le solde en haut à droite
3. Cliquer sur "Choisir ce plan" (starter, premium ou max)
4. Modal apparaît avec détails
5. Si solde insuffisant → voir "Recharger" en rouge
6. Si solde suffisant → cliquer "Confirmer"
7. Vérifier que :
   - Le plan devient "Plan actuel"
   - La date d'expiration s'affiche
   - Le solde est mis à jour
   - Une transaction est enregistrée

#### 2c. Visualiser les transactions

1. Aller à `http://localhost:3000/compte`
2. Scroller jusqu'à "Historique des transactions"
3. Vérifier que chaque paiement/achat est listé

---

## 🔧 Ce qui reste à faire (OPTIONNEL)

### Nice-to-have features:

1. **Autres méthodes de paiement** (Stripe, PayPal)
   - Intégration facile, suivre le même pattern que NowPayments

2. **Factures PDF**
   - Générer des PDFs pour les achats

3. **Notifications email**
   - Envoyer confirmation paiement reçu

4. **Admin panel recharge manuelle**
   - Permettre aux admins de créditer manuellement

5. **Refund/Remboursement**
   - Route pour rembourser un paiement

6. **Historique des paiements crypto détaillé**
   - Voir le détail de chaque transaction NowPayments

---

## 🚀 Déploiement

### Sur production:

```bash
# 1. Vérifier les variables .env
PORT=3000
NOWPAYMENTS_API_KEY=<votre_vraie_clé>
NOWPAYMENTS_IPN_SECRET=<votre_vraie_clé>

# 2. Lancer le serveur
npm start

# 3. Configurer le webhook IPN dans NowPayments admin:
# URL: https://votre-domaine.com/api/payments/crypto/webhook
# Secret: <valeur_NOWPAYMENTS_IPN_SECRET>

# 4. Tester un vrai paiement (montant de test)

# 5. Monitorer les logs
tail -f logs/*.log
```

---

## 📊 Structure de données utilisateur

```json
{
  "id": "uuid",
  "username": "testuser",
  "passwordHash": "bcrypted",
  "role": "user|admin",
  "createdAt": "2026-05-19T...",
  
  "balance": 100.50,
  "totalSpent": 500.00,
  "transactions": [
    {
      "id": "uuid",
      "type": "crypto_deposit|plan_buy",
      "amount": 50,
      "label": "Dépôt crypto (BTC)",
      "createdAt": "2026-05-19T...",
      "by": "user|admin|system"
    }
  ],
  
  "plan": "starter|premium|max",
  "planStartedAt": "2026-05-19T...",
  "planExpiresAt": "2026-06-19T...",
  
  "pendingPayments": [
    {
      "id": "payment_123",
      "orderId": "dontplay_userid_timestamp_hex",
      "amount": 50,
      "currency": "btc",
      "cryptoAmount": "0.00123",
      "cryptoAddress": "1A1z7agoat...",
      "status": "waiting|confirming|finished|failed|expired",
      "createdAt": "2026-05-19T...",
      "expiresAt": "2026-05-19T13:34:56Z",
      "invoiceUrl": "https://..."
    }
  ],
  
  "domains": [],
  "pages": [],
  "cloudflareToken": null,
  ...
}
```

---

## 🐛 Dépannage

### "Solde insuffisant" lors d'achat de plan
→ Recharger le solde via crypto d'abord

### Paiement crypto n'est pas confirmé
→ Vérifier que l'adresse reçoit les fonds sur le blockchain
→ NowPayments peut prendre quelques minutes pour confirmer

### Erreur "Token CSRF invalide"
→ Vérifier que le token CSRF est envoyé dans les headers
→ Rafraîchir la page pour en générer un nouveau

### Webhook ne reçoit pas les confirmations
→ Vérifier l'URL du webhook dans NowPayments admin
→ Vérifier le secret IPN dans .env
→ Vérifier les logs du serveur

---

## ✨ Prochaines étapes recommandées

1. **Tester le système complet** via les tests ci-dessus
2. **Ajouter des logs** pour déboguer facilement
3. **Configurer les alertes** pour les paiements importants
4. **Mettre en place un monitoring** pour vérifier que les webhooks arrivent
5. **Ajouter une page admin** pour voir tous les paiements

---

## 📞 Support

Si vous avez besoin d'aide pour tester ou déployer, vérifiez:
- Les logs du serveur: `tail -f logs/server.log`
- L'état de NowPayments: https://nowpayments.io/dashboard
- La base de données: `data/users.json`

