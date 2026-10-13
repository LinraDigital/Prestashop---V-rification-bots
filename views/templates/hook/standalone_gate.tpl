<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Vérification</title>
<style>
* { box-sizing: border-box; }
html, body {
    margin: 0;
    padding: 0;
    height: 100%;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
}
body {
    display: flex;
    align-items: center;
    justify-content: center;
    background: radial-gradient(circle at center, #f4fbf7 0%, #ffffff 70%);
    padding: 20px;
}
.ld-bot-check {
    max-width: 420px;
    width: 100%;
    text-align: center;
    padding: 20px;
}
.ld-bot-check__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 90px;
    height: 90px;
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.05), 0 10px 30px rgba(0, 0, 0, 0.06);
    color: #3ea67a;
    margin-bottom: 25px;
}
.ld-bot-check__title {
    font-size: 1.15rem;
    font-weight: 600;
    color: #222;
    margin-bottom: 25px;
    line-height: 1.4;
}
.ld-bot-check__button {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    background: #fff;
    border: 1px solid #dcdcdc;
    border-radius: 50px;
    padding: 14px 28px;
    font-size: 1rem;
    font-weight: 700;
    color: #222;
    cursor: pointer;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.05);
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}
.ld-bot-check__button:hover {
    border-color: #3ea67a;
    box-shadow: 0 4px 14px rgba(62, 166, 122, 0.2);
}
.ld-bot-check__button svg { color: #3ea67a; flex-shrink: 0; }
.ld-bot-check__note {
    margin-top: 20px;
    font-size: 0.8rem;
    color: #999;
}
.ld-bot-check__loading {
    display: none;
    margin-top: 20px;
    font-size: 0.85rem;
    color: #3ea67a;
}
</style>
</head>
<body>

<div class="ld-bot-check">
    <div class="ld-bot-check__icon">
        <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5">
            <circle cx="12" cy="12" r="11" stroke="currentColor" stroke-width="1.5"/>
            <path d="M9 8h6a1 1 0 0 1 1 1v6a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1Z"/>
            <circle cx="9.5" cy="11.5" r="0.8" fill="currentColor" stroke="none"/>
            <circle cx="14.5" cy="11.5" r="0.8" fill="currentColor" stroke="none"/>
            <path d="M4 4l16 16" stroke-width="1.5"/>
        </svg>
    </div>

    <p class="ld-bot-check__title">Veuillez confirmer que vous n'êtes pas un robot pour continuer vers le site.</p>

    <button type="button" class="ld-bot-check__button" id="ldBotCheckBtn">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="4 12 9 17 20 6"/>
        </svg>
        Je ne suis pas un robot
    </button>

    <p class="ld-bot-check__loading" id="ldBotCheckLoading">Chargement du site...</p>

    <p class="ld-bot-check__note">Cette étape permet de protéger notre site contre les spams et les abus.</p>
</div>

<script>
(function () {
    var btn = document.getElementById('ldBotCheckBtn');
    var loading = document.getElementById('ldBotCheckLoading');
    var cookieName = {$ld_cookie_name|json_encode nofilter};
    var cookieHours = {$ld_cookie_hours|intval};

    var cookieValue = {$ld_cookie_value|json_encode nofilter};

    function setCookie(name, value, hours) {
        var expires = '';
        if (hours > 0) {
            var date = new Date();
            date.setTime(date.getTime() + hours * 60 * 60 * 1000);
            expires = '; expires=' + date.toUTCString();
        }
        // Cookie signé par le serveur (impossible à fabriquer à la main) ; « Secure » dès que le site est en HTTPS.
        document.cookie = name + '=' + encodeURIComponent(cookieValue) + expires + '; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
    }
    // Ne réagit qu'à un vrai clic/tap (souris ou tactile), jamais un événement
    // simulé par un script :
    // - e.isTrusted : rejette tout événement généré par un script (synthétique)
    //   -> c'est la vraie protection anti-bot, un bot basique ne peut pas
    //   déclencher un événement "de confiance", peu importe le type d'appareil
    // - pointerType 'mouse' ou 'touch' : souris ET tactile acceptés (mobile
    //   n'a pas de souris) - seul un clic clavier (Entrée/Espace sur un bouton
    //   focus, qui déclenche 'click' mais jamais 'pointerdown') reste ignoré
    btn.addEventListener('pointerdown', function (e) {
        if (!e.isTrusted || (e.pointerType !== 'mouse' && e.pointerType !== 'touch')) {
            return;
        }

        setCookie(cookieName, cookieValue, cookieHours);

        btn.style.display = 'none';
        loading.style.display = 'block';

        // Rechargement réel de la page : le cookie étant désormais posé, le
        // serveur laissera passer le vrai site cette fois-ci.
        window.location.reload();
    });
})();
</script>

</body>
</html>
