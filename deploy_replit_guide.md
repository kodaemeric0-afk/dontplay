# 🚀 Déploiement sur Replit

Ce projet Node.js est prêt pour un déploiement sur Replit.

## 1. Préparation
Le dossier `dontplay-site/dontplay-site` contient :
- `package.json` avec `npm start`
- `server.js` qui écoute `process.env.PORT` ou `3000`
- `.replit` pour configurer l'exécution Replit
- `.gitignore` pour ignorer les fichiers volumineux / secrets

## 2. Fichiers ajoutés
- `.replit`
- `.gitignore`
- `fly.toml` (pour Fly.io, si besoin plus tard)
- `deploy_replit_guide.md`

## 3. Comment déployer
1. Créez un compte Replit sur https://replit.com
2. Cliquez sur "Create" → "Import from GitHub"
3. Sélectionnez ou collez l'URL du dépôt GitHub contenant ce projet
4. Replit va détecter Node.js automatiquement grâce à `package.json`
5. Si nécessaire, éditez la configuration de la repl :
   - Commande de lancement : `npm install && npm start`
6. Ajoutez les variables d'environnement Replit :
   - `NODE_ENV=production`
   - `DB_TYPE=json`
   - `SESSION_SECRET=votre_secret_long`

## 4. URL publique Replit
- Replit fournit un URL du type `https://<nom-de-votre-repl>.<username>.repl.co`
- Durable tant que le projet reste actif sur Replit
- HTTPS automatique

## 5. Utilisation
- Lancer la repl
- Ouvrir l’URL publique fournie par Replit
- Tester `/login` et `/register`

## 6. Si vous n’avez pas encore de dépôt GitHub
1. Initialisez un dépôt dans le dossier `dontplay-site/dontplay-site`
2. `git add .` et `git commit -m "Replit deploy ready"`
3. Poussez sur GitHub
4. Importez le dépôt sur Replit

## 7. Avantage pour votre mini-app
- Replit héberge bien les apps Node.js
- Vous pouvez modifier le code en ligne
- L’URL est sécurisée (`https`)
- Pas besoin de carte bancaire

## 8. Limitation
- Replit gratuit peut auto-suspendre l’app si elle n’est pas utilisée souvent
- Pour un vrai site « à vie », un domaine externe reste préférable
