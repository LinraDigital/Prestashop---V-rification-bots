<?php
/**
 * ld_bot_check - Linra Digital
 * Écran de vérification "Je ne suis pas un robot" AVANT tout chargement du
 * site : la requête est interceptée le plus tôt possible (hook
 * actionDispatcher, avant que le contrôleur normal ne commence à construire
 * la page), et on renvoie uniquement une page minimale autonome (aucune
 * requête externe, CSS/JS en ligne). Le vrai site (avec toutes ses
 * ressources) ne se charge qu'après un vrai clic sur le bouton, qui
 * pose un cookie signé puis recharge la page normalement.
 *
 * Ne sont jamais interceptés : le back-office, les employés connectés, les requêtes
 * autres que GET/HEAD (formulaires, retours de paiement, webhooks), les appels ajax,
 * les contrôleurs de modules de type validation/notification/webhook, robots.txt,
 * les sitemaps, et les robots d'indexation authentiques (Google, Bing, Apple).
 *
 * Limite assumée : c'est un frein contre les bots basiques qui exécutent du
 * JavaScript sans simuler un vrai pointeur - pas une protection absolue contre
 * des bots sophistiqués (navigateur headless complet).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Ld_Bot_Check extends Module
{
    const COOKIE_NAME = 'bot';
    const CONFIG_ENABLED = 'LD_BOT_CHECK_ENABLED';
    const CONFIG_DURATION = 'LD_BOT_CHECK_DURATION_HOURS';
    const CONFIG_ALLOW_GOOGLEBOT = 'LD_BOT_CHECK_ALLOW_GOOGLEBOT'; // « robots d'indexation authentiques » (nom conservé : compatibilité)
    const CONFIG_EXCLUDED = 'LD_BOT_CHECK_EXCLUDED_PATHS';

    /** Durée de conservation (secondes) du résultat de la vérification DNS d'une IP. */
    const CRAWLER_CACHE_TTL = 86400;

    /**
     * Robots d'indexation autorisés (si l'option est cochée) : extrait du User-Agent => domaines
     * attendus pour le nom d'hôte obtenu par DNS inverse.
     */
    private static $crawlers = [
        'googlebot' => ['googlebot.com', 'google.com'],
        'bingbot' => ['search.msn.com'],
        'applebot' => ['applebot.apple.com'],
    ];

    /** Évite d'exécuter la vérification deux fois (le module est branché sur deux hooks). */
    private static $checked = false;

    public function __construct()
    {
        $this->name = 'ld_bot_check';
        $this->tab = 'front_office_features';
        $this->version = '2.2.0';
        $this->author = 'Linra Digital';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('LD - Vérification anti-bot');
        $this->description = $this->l('Bloque le chargement du site (clic souris uniquement requis) avant chargement, pour limiter les requêtes des bots.');

        $this->ps_versions_compliancy = ['min' => '1.7', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('actionDispatcher')
            && $this->registerHook('actionDispatcherBefore')
            && Configuration::updateValue(self::CONFIG_ENABLED, 1)
            && Configuration::updateValue(self::CONFIG_ALLOW_GOOGLEBOT, 1)
            && Configuration::updateValue(self::CONFIG_EXCLUDED, '')
            && Configuration::updateValue(self::CONFIG_DURATION, 2160); // 3 mois (90 jours x 24h)
    }

    public function uninstall()
    {
        Configuration::deleteByName(self::CONFIG_ENABLED);
        Configuration::deleteByName(self::CONFIG_DURATION);
        Configuration::deleteByName(self::CONFIG_ALLOW_GOOGLEBOT);
        Configuration::deleteByName(self::CONFIG_EXCLUDED);

        return parent::uninstall();
    }

    /**
     * ------------------------------------------------------------------
     * Back office
     * ------------------------------------------------------------------
     */
    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitLdBotCheck')) {
            Configuration::updateValue(self::CONFIG_ENABLED, Tools::getValue(self::CONFIG_ENABLED) ? 1 : 0);
            Configuration::updateValue(self::CONFIG_ALLOW_GOOGLEBOT, Tools::getValue(self::CONFIG_ALLOW_GOOGLEBOT) ? 1 : 0);

            $duration = (int) Tools::getValue(self::CONFIG_DURATION);
            Configuration::updateValue(self::CONFIG_DURATION, $duration > 0 ? $duration : 24);

            // Une ligne = un extrait d'URL à ne jamais intercepter (ex : /module/monpaiement/retour)
            $lines = preg_split('/\R/', (string) Tools::getValue(self::CONFIG_EXCLUDED));
            $lines = array_filter(array_map('trim', $lines), function ($line) {
                return $line !== '' && Validate::isCleanHtml($line) && strlen($line) <= 200;
            });
            Configuration::updateValue(self::CONFIG_EXCLUDED, implode("\n", array_unique($lines)));

            $output .= $this->displayConfirmation($this->l('Configuration enregistrée.'));
        }

        $this->context->smarty->assign([
            'ld_current_index' => $this->context->link->getAdminLink('AdminModules', false)
                . '&configure=' . $this->name
                . '&tab_module=' . $this->tab
                . '&module_name=' . $this->name,
            'ld_token' => Tools::getAdminTokenLite('AdminModules'),
            'ld_enabled' => (bool) Configuration::get(self::CONFIG_ENABLED),
            'ld_duration' => (int) Configuration::get(self::CONFIG_DURATION),
            'ld_allow_googlebot' => (bool) Configuration::get(self::CONFIG_ALLOW_GOOGLEBOT),
            'ld_excluded' => (string) Configuration::get(self::CONFIG_EXCLUDED),
        ]);

        $output .= $this->context->smarty->fetch(
            _PS_MODULE_DIR_ . $this->name . '/views/templates/admin/configure.tpl'
        );

        return $output;
    }

    /**
     * ------------------------------------------------------------------
     * Jeton signé (cookie)
     * ------------------------------------------------------------------
     * Le cookie contient « expiration.signature » : la signature (HMAC avec la clé secrète de
     * la boutique) empêche de fabriquer un cookie à la main, contrairement à une simple valeur « 1 ».
     */
    private function signToken($expires)
    {
        return hash_hmac('sha256', 'ld_bot_check|' . (int) $expires, _COOKIE_KEY_);
    }

    private function makeToken()
    {
        $hours = max(1, (int) Configuration::get(self::CONFIG_DURATION));
        $expires = time() + $hours * 3600;

        return $expires . '.' . $this->signToken($expires);
    }

    private function isValidToken($token)
    {
        if (!is_string($token) || !preg_match('/^(\d{10,})\.([a-f0-9]{64})$/', $token, $m)) {
            return false;
        }

        return (int) $m[1] >= time() && hash_equals($this->signToken((int) $m[1]), $m[2]);
    }

    /**
     * ------------------------------------------------------------------
     * Interception avant chargement
     * ------------------------------------------------------------------
     */
    private function shouldDisplayGate()
    {
        if (!(bool) Configuration::get(self::CONFIG_ENABLED)) {
            return false;
        }

        // Cookie signé valide : le site charge normalement
        if (isset($_COOKIE[self::COOKIE_NAME]) && $this->isValidToken($_COOKIE[self::COOKIE_NAME])) {
            return false;
        }

        // Seules les pages consultées sont concernées : un POST (panier, formulaire, retour de
        // paiement, webhook) ne doit jamais recevoir l'écran de vérification.
        if (!$this->isPageRequest()) {
            return false;
        }

        // Jamais sur le back-office : vérification directe sur le chemin de l'URL
        // (la plus fiable, indépendante du moment où le hook se déclenche)...
        if (defined('_PS_ADMIN_DIR_')) {
            $admin_dir_name = basename(_PS_ADMIN_DIR_);
            $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
            if ($admin_dir_name !== '' && strpos($uri, '/' . $admin_dir_name) !== false) {
                return false;
            }
        }

        // ... et en repli, jamais non plus pour un employé authentifié (au cas
        // où il naviguerait sur le front en étant connecté).
        if (isset($this->context->employee) && $this->context->employee && $this->context->employee->id) {
            return false;
        }

        if ($this->isExcludedPath()) {
            return false;
        }

        // Jamais pour un robot d'indexation authentique (sinon plus aucune page ne
        // s'indexe et le référencement s'effondre avec le temps).
        if ((bool) Configuration::get(self::CONFIG_ALLOW_GOOGLEBOT) && $this->isVerifiedCrawler()) {
            return false;
        }

        return true;
    }

    /**
     * Vrai pour une page consultée (GET/HEAD, hors ajax / JSON).
     */
    private function isPageRequest()
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';

        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }

        if (Tools::getValue('ajax') || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            return false;
        }

        $accept = isset($_SERVER['HTTP_ACCEPT']) ? strtolower($_SERVER['HTTP_ACCEPT']) : '';

        return strpos($accept, 'application/json') === false;
    }

    /**
     * Chemins jamais interceptés : fichiers techniques, contrôleurs de modules « machine à machine »
     * (validation de paiement, notification, webhook...) et chemins ajoutés dans la configuration.
     */
    private function isExcludedPath()
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? strtolower(rawurldecode($_SERVER['REQUEST_URI'])) : '';
        $path = (string) parse_url($uri, PHP_URL_PATH);

        $technical = '#(^|/)(robots\.txt|favicon\.ico|sitemap[^/]*\.xml|\.well-known/)#';
        $machine = '#/module/[^/]+/(validation|notification|callback|webhook|ipn|return|retour|cron|hook|ajax|response|cancel|success|error|confirm\w*)#';

        if (preg_match($technical, $path) || preg_match($machine, $path)) {
            return true;
        }

        // URL non réécrite : index.php?fc=module&module=xxx&controller=validation
        if (Tools::getValue('fc') === 'module'
            && preg_match('/^(validation|notification|callback|webhook|ipn|return|retour|cron|hook|ajax|response|cancel|success|error|confirm\w*)$/i', (string) Tools::getValue('controller'))
        ) {
            return true;
        }

        foreach (preg_split('/\R/', (string) Configuration::get(self::CONFIG_EXCLUDED)) as $fragment) {
            $fragment = strtolower(trim($fragment));
            if ($fragment !== '' && strpos($uri, $fragment) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Vérifie qu'un visiteur prétendant être un robot d'indexation (Googlebot, Bingbot, Applebot)
     * l'est VRAIMENT, via la méthode recommandée par ces moteurs (le simple User-Agent est
     * trivialement falsifiable) :
     * 1. DNS inverse : l'IP doit résoudre vers un nom d'hôte du domaine attendu ;
     * 2. DNS direct de confirmation : ce nom d'hôte doit revenir vers exactement la même IP
     *    (IPv4 ou IPv6).
     *
     * Les recherches DNS ne sont faites QUE si le User-Agent prétend déjà être un de ces robots,
     * et le résultat est conservé 24 h par IP pour ne pas ralentir chaque requête du robot.
     */
    private function isVerifiedCrawler()
    {
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower($_SERVER['HTTP_USER_AGENT']) : '';
        $domains = null;

        foreach (self::$crawlers as $token => $allowed) {
            if ($user_agent !== '' && strpos($user_agent, $token) !== false) {
                $domains = $allowed;
                break;
            }
        }

        $ip = Tools::getRemoteAddr();

        if ($domains === null || !$ip) {
            return false;
        }

        $cache_dir = _PS_CACHE_DIR_ . 'ld_bot_check/';
        $cache_file = $cache_dir . sha1($ip . '|' . implode(',', $domains)) . '.txt';

        if (is_file($cache_file) && (time() - (int) @filemtime($cache_file)) < self::CRAWLER_CACHE_TTL) {
            return @file_get_contents($cache_file) === '1';
        }

        $verified = $this->resolvesTo($ip, $domains);

        if (is_dir($cache_dir) || @mkdir($cache_dir, 0755, true)) {
            @file_put_contents($cache_file, $verified ? '1' : '0');
        }

        return $verified;
    }

    private function resolvesTo($ip, array $domains)
    {
        $hostname = @gethostbyaddr($ip);

        if (!$hostname || $hostname === $ip) {
            return false;
        }

        $hostname = strtolower(rtrim($hostname, '.'));
        $allowed = false;

        foreach ($domains as $domain) {
            $suffix = '.' . ltrim(strtolower($domain), '.');
            if (substr($hostname, -strlen($suffix)) === $suffix) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            return false;
        }

        $forward = [];

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            foreach ((array) @dns_get_record($hostname, DNS_AAAA) as $record) {
                if (!empty($record['ipv6'])) {
                    $forward[] = $record['ipv6'];
                }
            }
        } else {
            $forward = (array) @gethostbynamel($hostname);
        }

        $packed = @inet_pton($ip);

        foreach ($forward as $candidate) {
            if ($packed !== false && @inet_pton($candidate) === $packed) {
                return true;
            }
        }

        return false;
    }

    /**
     * Un des tout premiers hooks déclenchés dans le cycle de vie d'une requête
     * front - avant que le contrôleur normal ne commence à charger ses
     * données/assets. On coupe ici court à tout le reste : aucune requête vers
     * une image produit, un CSS de thème, etc. ne part tant que la page n'a
     * pas été validée.
     */
    public function hookActionDispatcher($params)
    {
        $this->interceptIfNeeded();
    }

    public function hookActionDispatcherBefore($params)
    {
        $this->interceptIfNeeded();
    }

    private function interceptIfNeeded()
    {
        if (self::$checked) {
            return;
        }
        self::$checked = true;

        if (!$this->shouldDisplayGate()) {
            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        // La page de vérification ne doit être ni mise en cache (CDN, proxy, cache de page) ni indexée.
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex, nofollow');
        header('Vary: Cookie');

        echo $this->renderStandaloneGate();
        exit;
    }

    private function renderStandaloneGate()
    {
        $this->context->smarty->assign([
            'ld_cookie_name' => self::COOKIE_NAME,
            'ld_cookie_hours' => (int) Configuration::get(self::CONFIG_DURATION),
            'ld_cookie_value' => $this->makeToken(),
        ]);

        return $this->context->smarty->fetch(
            _PS_MODULE_DIR_ . $this->name . '/views/templates/hook/standalone_gate.tpl'
        );
    }
}
