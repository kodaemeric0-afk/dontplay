(function () {

    document.addEventListener('keydown', function (e) {
        const key = (e.key || '').toLowerCase();
        const ctrl = e.ctrlKey || e.metaKey; 

        // F12
        if (key === 'f12') {
            e.preventDefault();
            e.stopPropagation();
            return false;
        }

        // Ctrl/Cmd + Shift + I / J / C / K
        if (ctrl && e.shiftKey && ['i', 'j', 'c', 'k'].includes(key)) {
            e.preventDefault();
            e.stopPropagation();
            return false;
        }

        // Ctrl/Cmd + U (view-source)
        if (ctrl && key === 'u') {
            e.preventDefault();
            e.stopPropagation();
            return false;
        }

        // Ctrl/Cmd + S (save), Ctrl/Cmd + P (print)
        if (ctrl && (key === 's' || key === 'p')) {
            e.preventDefault();
            e.stopPropagation();
            return false;
        }

        // Ctrl/Cmd + Shift + G (si vous souhaitez le garder)
        if (ctrl && e.shiftKey && key === 'g') {
            e.preventDefault();
            e.stopPropagation();
            return false;
        }
    }, true);

    // Bloque clic droit
    document.addEventListener('contextmenu', function (e) {
        e.preventDefault();
        e.stopPropagation();
        return false;
    }, true);

    // Bloque sélection, copier/coller, glisser-déposer
    document.addEventListener('selectstart', function (e) {
        e.preventDefault();
        e.stopPropagation();
        return false;
    }, true);

    ['copy', 'cut', 'paste', 'dragstart', 'drop'].forEach(function (evt) {
        document.addEventListener(evt, function (e) {
            e.preventDefault();
            e.stopPropagation();
            return false;
        }, true);
    });

   
    document.addEventListener('mousedown', function (e) {
        // bloque clic milieu et droit
        if (e.button === 1 || e.button === 2) {
            e.preventDefault();
            e.stopPropagation();
            return false;
        }
    }, true);

    
    var devtoolsOpen = false;
    var overlay = null;

    function showAntiInspectOverlay() {
        if (overlay) return;
        overlay = document.createElement('div');
        overlay.setAttribute('style',
            'position:fixed;inset:0;'+
            'background:#fff;z-index:2147483647;'+
            'display:flex;align-items:center;justify-content:center;color:#000;'+
            'font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;'+
            'text-align:center;padding:24px;'
        );
        overlay.textContent = 'Ahaha tu es bloqué <3';
        document.documentElement.appendChild(overlay);
        document.documentElement.style.pointerEvents = 'none';
    }
    function hideAntiInspectOverlay() {
        if (!overlay) return;
        overlay.remove();
        overlay = null;
        document.documentElement.style.pointerEvents = '';
    }

    function checkSizeHeuristic() {
      
        var threshold = 160; 
        var widthDiff = Math.abs((window.outerWidth || 0) - (window.innerWidth || 0));
        var heightDiff = Math.abs((window.outerHeight || 0) - (window.innerHeight || 0));
        return widthDiff > threshold || heightDiff > threshold;
    }

    function checkDebuggerTiming() {
        var start = performance.now();
      
        for (var i = 0; i < 5; i++) {
            
            debugger;
        }
        var delta = performance.now() - start;
      
        return delta > 200;
    }

    function evaluateDevTools() {
        try {
            var bySize = checkSizeHeuristic();
            var byTiming = checkDebuggerTiming();
            devtoolsOpen = bySize || byTiming;
            if (devtoolsOpen) showAntiInspectOverlay();
            else hideAntiInspectOverlay();
        } catch (e) {
            // ignorer
        }
    }

   
    var intervalId = setInterval(evaluateDevTools, 1500);
   
    ['resize', 'focus', 'blur', 'visibilitychange'].forEach(function (evt) {
        window.addEventListener(evt, evaluateDevTools, true);
    });

   
    window.addEventListener('beforeunload', function () {
        try { clearInterval(intervalId); } catch (e) {}
    }, { once: true, capture: true });

})();