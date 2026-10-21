# Production HTTPS, sécurité et mises à jour — OPS-02

Choix validé : serveur unique Docker Compose avec **Caddy intégré**, certificat HTTPS géré automatiquement. La configuration pour un proxy déjà installé reste fournie. Aucun serveur ni DNS n’a été modifié par cette livraison. Avant production : choisir domaine, adresse du serveur, ressources, responsable d’exploitation, destination des sauvegardes extérieures et modalités SMTP.

## Conditions préalables

Docker Engine et Compose >= 2.24.4 sont nécessaires (les overlays utilisent `!override` et `!reset`). Prévoir DNS A/AAAA cohérents vers le serveur, ports 80 et 443 accessibles pour HTTPS et les validations ACME, accès sortant DNS/HTTPS, horloge synchronisée et disque surveillé. Ne pas publier d’AAAA si IPv6 n’atteint pas réellement ce serveur. Vérifier qu’aucun autre service n’utilise les ports 80/443 et que le sous-réseau Docker choisi ne chevauche pas les réseaux du serveur.

Utiliser une copie du dépôt par version, conserver les versions précédentes et les images locales correspondantes. Copier `.env.production.example` vers `.env.production` (ignoré par Git et les images), puis remplacer toutes les valeurs d’exemple :

- `COMPOSE_PROJECT_NAME` stable sur le serveur ; le changer crée de nouveaux volumes.
- `APP_DOMAIN=stock.votre-domaine.fr`, sans schéma, port ni chemin ; `APP_TRUSTED_HOST_PATTERN='^stock[.]votre-domaine[.]fr\z'`, motif exact de ce même domaine. Les points s’écrivent `[.]`, la fin `\z` évite toute interpolation du caractère dollar par Compose.
- `RELEASE_ID` : identifiant immuable de la version applicative validée, par exemple son commit. Ne jamais réutiliser un identifiant pour une image différente. `DATABASE_RELEASE_ID` reste celui de l’image PostgreSQL 18 validée tant qu’une mise à jour de base séparée n’a pas été testée.
- `APP_TRUSTED_PROXIES` : adresse exacte de Caddy, par défaut `10.253.240.2`. Le réseau TLS est `10.253.240.0/24`, son allocation dynamique `10.253.240.128/25`, réservant `.2` au proxy. Si changement, adapter ensemble `TLS_SUBNET`, `TLS_DYNAMIC_RANGE`, `TLS_PROXY_ADDRESS` et l’adresse de confiance. Ne jamais utiliser `0.0.0.0/0`, `private_ranges` ou une adresse fournie par le client.
- `ACME_EMAIL` : courriel réel du responsable des certificats. `HTTPS_BIND` : interface d’écoute voulue ; les exemples utilisent toutes les interfaces IPv4. Adapter explicitement le pare-feu et IPv6 au serveur.
- Nom de base, rôle, expéditeur SMTP et quatre fichiers secrets existants sous `.secrets/`. Ne pas copier les identifiants de développement en production. Le compte de test et ses comptes marqués `development_only` sont refusés en production, même si présents dans une base restaurée.

Conserver `.secrets` en 0700, les fichiers accessibles uniquement aux identités nécessaires. Avec les secrets Compose sur fichiers, l’UID applicatif 10001 doit pouvoir lire les trois secrets qui lui sont montés ; limiter l’accès au répertoire hôte, sans modifier aveuglément leurs permissions au point de bloquer le conteneur. Sur Linux, on peut conserver le répertoire privé en 0700, affecter le groupe 10001 au fichier `.env.production` et aux quatre secrets de reprise, puis leur donner le mode 0640 : l’opérateur propriétaire et les conteneurs autorisés peuvent les lire. Garder la clé privée age en 0600 et séparée. Adapter ces droits au déploiement et les tester avec la sauvegarde. Ne jamais enregistrer secrets ou configuration complète contenant des valeurs confidentielles dans un ticket. Les commandes de contrôle n’affichent que des codes d’erreur.

## Préparer les images et contrôles

```sh
export PRODUCTION_PROXY_MODE=integrated
sh bin/production config
sh bin/production build
sh bin/production check
```

Le contrôle refuse mode debug, environnement non productif, HTTPS désactivé, URL publique incorrecte, domaine de confiance trop large, proxy absent/trop large et secrets manquants. Il ne contacte pas le domaine ni un serveur SMTP. `build` construit app, web, base, sauvegarde et proxy ; l’installation initiale doit donc disposer des images avant de lancer `check`. Pour une mise à jour applicative, conserver `DATABASE_RELEASE_ID` et ne pas reconstruire/remplacer la base par inadvertance.

Caddy 2.11.7 est recompilé avec Go 1.27.3 et `golang.org/x/net` 0.60.0, versions et dépendances verrouillées, pour corriger les vulnérabilités du binaire officiel détectées pendant la recette. Aucun module tiers ajouté. L’alerte UNKNOWN [GO-2026-5932](https://pkg.go.dev/vuln/GO-2026-5932) concerne les paquets OpenPGP de `golang.org/x/crypto`. Le module reste requis pour ses autres paquets ; la construction vérifie que ni `openpgp` ni ses sous-paquets ne figurent dans les dépendances compilées et conserve cette liste dans `/usr/share/gestion-stock/proxy-dependencies.txt`. L’alerte reste visible dans le rapport, sans exclusion globale. Les couches amont sont épinglées par empreinte ; les paquets Alpine reçoivent les correctifs disponibles à la construction. Une reconstruction constitue une nouvelle version à tester et à nommer distinctement.

L’analyse des dépendances PHP se lance dans le conteneur de test :

```sh
docker compose run --rm --no-deps test composer audit
```

Pour les images, lancer `bin/scan-images` avec les noms locaux exacts de la version. Le script télécharge uniquement la base publique de vulnérabilités dans un conteneur ne recevant aucune image applicative. Il exporte ensuite chaque image dans un répertoire privé, puis l’analyse **sans réseau**, sans socket Docker, sans secrets hôte. Le scanner Trivy 0.76.1 est épinglé par empreinte. Les rapports complets restent locaux ; le script échoue si une vulnérabilité HIGH ou CRITICAL est présente, même sans correctif. Il ne masque pas les résultats MEDIUM/LOW/UNKNOWN.

```sh
SECURITY_SCAN_DIRECTORY=/chemin/prive/audits sh bin/scan-images \
  gestion-stock-production-app:VERSION \
  gestion-stock-production-web:VERSION \
  gestion-stock-production-db:VERSION_BASE \
  gestion-stock-production-backup:VERSION \
  gestion-stock-production-proxy:VERSION
```

Les exports sont supprimés après analyse réussie ; vérifier le répertoire après une interruption. Les rapports et bases de vulnérabilités dépendent de leur date : refaire l’analyse avant chaque déploiement. Une analyse sans résultat connu ne garantit pas l’absence de vulnérabilité.

## Première installation

Les commandes ci-dessous décrivent une installation neuve. En cas de données existantes, appliquer la procédure de mise à jour plus bas. Préparer les quatre secrets avec la procédure initiale du README et créer la paire age pour les sauvegardes ; mettre la clé privée séparément hors serveur. Aucune installation vierge ne doit remplacer un volume contenant des données.

Utiliser systématiquement les mêmes fichiers et paramètres :

```sh
docker compose --env-file .env.production -f compose.yaml -f compose.production.yaml -f compose.proxy-integrated.yaml up -d --wait db
docker compose --env-file .env.production -f compose.yaml -f compose.production.yaml -f compose.proxy-integrated.yaml run --rm --no-deps app php bin/console doctrine:migrations:migrate --no-interaction
docker compose --env-file .env.production -f compose.yaml -f compose.production.yaml -f compose.proxy-integrated.yaml run --rm --no-deps app php bin/console messenger:setup-transports --no-interaction
docker compose --env-file .env.production -f compose.yaml -f compose.production.yaml -f compose.proxy-integrated.yaml run --rm --no-deps app php bin/console app:user:create-admin administrateur@votre-domaine.fr "Administrateur"
```

Le mot de passe est saisi interactivement ; ne pas le passer dans les arguments. Vérifier ensuite le démarrage via `sh bin/production update` : une première sauvegarde est exigée avant migration, les images sont déjà construites. Ne pas activer SMTP avant vérification de l’expéditeur, du secret et des destinataires. Ne pas initialiser de données de démonstration sur ce serveur.

Caddy s’exécute sans root, en lecture seule et sans capacités Linux ; il écoute 8080/8443 dans le conteneur, publiés sur 80/443. Une initialisation ponctuelle affecte ses deux volumes privés à l’UID 10001. Les certificats et leur état persistent dans `tls_data`/`tls_config`. Ils peuvent être réémis depuis les paramètres sauvegardés, mais leur perte peut provoquer une interruption et des limites ACME : conserver ces volumes lors des mises à jour. Ne pas supprimer la base, ses secrets ou les volumes de sauvegarde/certificats.

## Protocole HTTPS et navigateur

Le backend web n’a aucun port publié en mode intégré. Caddy remplace les en-têtes de schéma, client et hôte avant de transmettre ; Symfony ne fait confiance qu’aux adresses explicitement configurées et uniquement à `X-Forwarded-For`/`X-Forwarded-Proto`. Les en-têtes de domaine, port et préfixe fournis par un client ne sont pas utilisés comme autorité.

La production refuse les requêtes applicatives reconnues HTTP avant authentification, sans créer de session. Seules les deux sondes internes `/health/live` et `/health/ready` restent utilisables en HTTP avec le domaine autorisé. Le proxy redirige HTTP vers HTTPS. Cookies de session Secure, HttpOnly, SameSite=Lax, HSTS un an sans preload ni sous-domaines imposés, pas de mise en cache des pages privées. La CSP refuse scripts externes/inline, objets et intégration en iframe ; les styles inline restent autorisés pour les barres de statistiques existantes. Les protections CSRF, Argon2id, contrôle d’accès, limitation des tentatives et requêtes SQL paramétrées existantes restent actives. Les fichiers `.env`, secrets, sources PHP et répertoires privés ne sont pas servis par Nginx.

Les accès Nginx journalisent la méthode et le chemin, sans arguments de requête, cookies ni en-têtes d’autorisation. Le binaire PHP ne divulgue pas sa version ni ses arguments d’exception. Le moindre privilège PostgreSQL et la traçabilité sont vérifiés par les tests existants.

## Mise à jour contrôlée

1. Annoncer une maintenance ; conserver l’ancienne version, ses images immuables, la configuration privée et les secrets. Fermer temporairement l’accès public si une validation manuelle doit précéder la réouverture. Réaliser et vérifier une sauvegarde **depuis l’ancienne version avant de remplacer ses sources**, puis sa copie extérieure.
2. Préparer la nouvelle version dans un répertoire distinct, avec le même projet, mêmes secrets et mêmes volumes. Examiner les migrations et leur compatibilité ; ne jamais changer simultanément la version majeure PostgreSQL. Refaire tests, audit et restauration sur installation de recette. Construire les nouvelles images avec un nouveau `RELEASE_ID` ; ne pas retaguer l’ancienne image.
3. Exécuter `sh bin/production check`, puis `sh bin/production update`. Le script arrête proxy intégré, web, app, worker, scheduler et sauvegarde ; prend une sauvegarde supplémentaire ; exécute les migrations explicitement ; vérifie leur état ; redémarre app/web, proxy, puis file/scheduler/sauvegarde après les sondes. Un échec de sauvegarde ou migration conserve la maintenance. La sauvegarde supplémentaire contient les sources candidates et la base avant migration : pour revenir à l’ancienne version complète, préférer le paquet de l’étape 1 et conserver les anciennes images.
4. Vérifier HTTPS et certificat réel, redirection, connexion d’un compte productif, refus du compte de développement, consultation des stocks, absence d’écart de réconciliation, inventaires, file et sauvegardes. Ne pas envoyer de mouvement fictif sur une base réelle. Vérifier les services avec `sh bin/production status`, puis lever la maintenance et réactiver les envois autorisés.

`PRODUCTION_ENV_FILE` permet de choisir un fichier de configuration privé différent. `PRODUCTION_OVERRIDE_FILE` permet un overlay supplémentaire, utilisé par la recette isolée : examiner impérativement sa configuration fusionnée, car un overlay peut annuler les protections. Ne pas utiliser `docker compose up` sans les overlays sur une installation productive.

## Retour arrière sans perte silencieuse

Arrêter les écritures et les consommateurs avant toute décision. Conserver une sauvegarde de l’état en échec et l’heure de l’incident ; ne pas la purger ni supprimer les volumes. Le script ne lance **aucune migration descendante** et ne supprime aucune donnée. Les migrations existantes refusent certains retours destructifs si des données sont présentes.

- Si aucune migration n’a été appliquée et si le schéma est strictement identique à celui de l’ancienne version, réutiliser ses sources/images immuables et sa configuration, puis reprendre les contrôles avant ouverture. Ne pas présumer la compatibilité à partir du seul fait que le conteneur démarre.
- Si le schéma a changé ou si une migration a partiellement réussi, conserver l’installation en maintenance. Restaurer le paquet de l’ancienne version dans un **nouveau projet et de nouveaux volumes** selon [OPS-01](sauvegardes-restauration.md), PostgreSQL 18 et images compatibles. Si les noms d’images contiennent l’ancien projet, les retaguer explicitement vers le projet de reprise, sans écraser l’original. Vérifier empreintes, réconciliation et accès applicatif avant bascule.
- Une restauration revient à l’instantané sélectionné. Les écritures postérieures peuvent manquer : comparer l’état conservé, identifier les mouvements à reprendre et faire valider cette perte/reprise par le responsable métier avant réouverture. Ne pas annoncer un retour sans perte si ces écritures ne sont pas réconciliées.

La restauration sur base vierge, les refus sur cible non vide et l’annulation d’un import SQL en erreur sont exécutés par `sh bin/test-backup-recovery`. La recette de production ci-dessous applique réellement la seizième migration à une base contenant déjà 15 migrations et des données, exécute une seconde mise à jour sans remise à zéro, puis restaure dans un autre volume l’instantané à 15 migrations avec vérification de toutes les empreintes. Le retour arrière d’une future migration destructive doit être testé avec les données représentatives de cette migration avant son déploiement.

## Variante avec proxy existant

Utiliser `PRODUCTION_PROXY_MODE=external`. Le backend est limité à `127.0.0.1:8080`, sans écoute publique. Le proxy doit être sur le même serveur ; pour un proxy dans un autre conteneur ou une autre machine, définir et vérifier une topologie privée spécifique au lieu de publier le port sur toutes les interfaces.

Le modèle `docker/production/nginx-external.conf.example` montre les en-têtes à remplacer et les redirections ; adapter domaine et certificats, puis tester la configuration du proxy. Définir comme adresse de confiance **l’adresse effectivement vue par Nginx dans le conteneur web** (une connexion depuis l’hôte peut être vue comme la passerelle Docker). Ne pas supposer que `127.0.0.1` est cette adresse. HTTPS obligatoire et motif d’hôte exact restent inchangés ; une mauvaise adresse de confiance provoque un refus sûr des requêtes.

## Recette locale reproductible

```sh
sh bin/test-production
sh bin/test-backup-recovery
```

La première utilise la véritable composition de production avec un overlay jetable : faux secrets, volumes dédiés, aucun port publié, certificat Caddy interne et CA utilisée uniquement par le client de test. Elle teste redirection, certificat/nom, connexion de production, refus du compte dev et du CSRF manquant, cookies, en-têtes, usurpation de proxy, fichiers privés et deux mises à jour consécutives. Aucun certificat local n’est installé dans le système hôte. La seconde prouve la restauration et la conservation des données après recréation. Aucun de ces tests ne valide le DNS, l’ACME public, le pare-feu ou les ressources du futur serveur : effectuer cette recette sur l’hôte choisi avant ouverture.

Références : [Symfony et proxys de confiance](https://symfony.com/doc/current/deployment/proxies.html), [fusion des fichiers Compose](https://docs.docker.com/reference/compose-file/merge/), [HTTPS automatique Caddy](https://caddyserver.com/docs/automatic-https), [en-têtes du reverse proxy Caddy](https://caddyserver.com/docs/caddyfile/directives/reverse_proxy), [analyse Trivy hors ligne](https://trivy.dev/docs/latest/advanced/air-gap/).
