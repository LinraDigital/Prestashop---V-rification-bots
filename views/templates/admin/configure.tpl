<div class="panel">
    <div class="panel-heading">
        <i class="icon-shield"></i> Vérification anti-bot
    </div>
    <div class="panel-body">
        <p class="help-block">
            Écran "Je ne suis pas un robot" affiché avant le chargement du site pour les
            visiteurs sans cookie de vérification valide. Seul un vrai clic ou toucher (souris ou écran tactile),
            ni clavier ni script, valide l'écran. Le cookie de validation est signé : il ne peut pas être fabriqué à la main.
        </p>

        <form action="{$ld_current_index}&token={$ld_token}" method="post">
            <div class="checkbox">
                <label>
                    <input type="checkbox" name="LD_BOT_CHECK_ENABLED" value="1" {if $ld_enabled}checked{/if}>
                    Activer la vérification
                </label>
            </div>

            <div class="checkbox">
                <label>
                    <input type="checkbox" name="LD_BOT_CHECK_ALLOW_GOOGLEBOT" value="1" {if $ld_allow_googlebot}checked{/if}>
                    Toujours laisser passer les robots d'indexation authentiques : Googlebot, Bingbot, Applebot (vérifiés par double résolution DNS, pas juste le User-Agent)
                </label>
                <p class="help-block" style="margin-left: 20px;">
                    Indispensable pour le référencement - sans ça, les moteurs ne peuvent jamais indexer le site
                    (leurs robots ne peuvent pas cliquer sur le bouton).
                </p>
            </div>

            <div class="form-group" style="margin-top: 15px;">
                <label>Durée de validité du cookie (en heures)</label>
                <input type="number" name="LD_BOT_CHECK_DURATION_HOURS" class="form-control"
                       style="max-width: 150px;" min="1" value="{$ld_duration}">
                <p class="help-block">Une fois ce délai écoulé, l'écran sera de nouveau affiché au visiteur.</p>
            </div>

            <div class="form-group" style="margin-top: 15px;">
                <label>Adresses jamais bloquées (facultatif)</label>
                <textarea name="LD_BOT_CHECK_EXCLUDED_PATHS" class="form-control" rows="4" style="max-width: 500px;" placeholder="/module/monpaiement/retour">{$ld_excluded|escape:'html':'UTF-8'}</textarea>
                <p class="help-block">
                    Une adresse (ou un extrait d'adresse) par ligne. Les formulaires (POST), les appels ajax, robots.txt,
                    les sitemaps et les contrôleurs de modules de type validation / notification / webhook ne sont
                    déjà jamais bloqués : ajoutez ici d'éventuelles adresses de retour de paiement particulières.
                </p>
            </div>

            <button type="submit" name="submitLdBotCheck" class="btn btn-primary" style="margin-top: 10px;">
                <i class="icon-check"></i> Enregistrer
            </button>
        </form>
    </div>
</div>
