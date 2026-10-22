# Authentification — AUTH-01

## Parcours utilisateur

L’entrée `/` et les pages `/espace/*` demandent une connexion. `/connexion` propose un formulaire français utilisable sans JavaScript ; une erreur ne révèle pas si le compte existe ou est désactivé. Après succès, le tableau de bord affiche les menus issus du rôle lu en base. Le sélecteur de magasin et les contrôles métier par affectation sont raccordés par AUTH-03.

`/profil` affiche l’identité, le rôle et le nombre de magasins actifs affectés. Seul le fuseau horaire IANA peut être modifié. Le nom, le courriel, le rôle et les affectations relèvent de l’administration AUTH-02/03. La déconnexion utilise un formulaire POST protégé par CSRF.

La récupération du mot de passe sera livrée dans AUTH-04 ; l’écran indique actuellement de contacter l’administrateur.

## Premier administrateur

Après les migrations, sur le terminal de l’exploitant :

```sh
docker compose exec app php bin/console app:user:create-admin administrateur@example.org "Administrateur"
```

Remplacer le courriel et le nom par ceux du compte souhaité. Le mot de passe et sa confirmation sont demandés **en saisie masquée**, jamais comme argument, variable d’environnement ou valeur par défaut. Il doit comporter de 12 à 128 caractères. Si le terminal ne permet pas la saisie masquée, la commande refuse de continuer. Ne pas utiliser `--no-interaction` ni rediriger les secrets dans l’historique du terminal.

La commande refuse de créer un compte si un compte normal existe déjà, y compris désactivé/anonymisé. Les comptes marqués comme réservés au développement ne bloquent pas cette initialisation. Un verrou transactionnel protège aussi deux initialisations simultanées. Aucun compte n’est créé automatiquement au démarrage. L’administration courante des comptes est livrée par AUTH-02.

## Sessions et mots de passe

- Symfony Security, provider DBAL : recherche du courriel insensible à la casse et aux espaces extérieurs ; relecture du compte par UUID à chaque requête authentifiée.
- Argon2id avec le hasher Symfony, 64 Mio, quatre passes, un thread. Un hachage plus ancien accepté par le hasher est renouvelé après connexion. Le mot de passe et son hachage ne figurent jamais dans une réponse ; seule une empreinte SHA-256 du hachage entre dans la session serveur.
- Un compte désactivé/anonymisé, une modification de mot de passe, du rôle, du courriel ou de `auth_version` invalide sa session lors de la requête suivante. L’écran d’administration qui réalise ces changements est décrit dans le [guide des comptes](administration-comptes.md).
- Renouvellement d’identifiant après toute connexion réussie ; les anciens fichiers de session sont détruits. Les jetons CSRF antérieurs à la connexion sont supprimés.
- Expiration serveur après **30 minutes d’inactivité**, ou **12 heures** depuis la connexion même avec activité. Le navigateur reçoit un cookie de session sans durée persistante. La fermeture du navigateur n’est pas une garantie de déconnexion : utiliser le bouton dédié.
- Cookie `GESTION_STOCK_SESSION`, HttpOnly, SameSite=Lax et Secure si HTTPS est reconnu. La configuration du proxy/HTTPS réel reste dans OPS-02 ; l’environnement local est HTTP sur loopback.
- Stockage natif dans `/app/var/sessions`, temporaire : recréer le conteneur invalide ses sessions. La déconnexion détruit la session, retire le cookie et interdit son rejeu.
- Pages de compte et réponses API non mises en cache (`no-store`, `private`). Aucun mécanisme « rester connecté » ni jeton Bearer.

## API disponible

| Méthode | Chemin | Usage |
|---|---|---|
| GET | `/api/v1/auth/csrf` | Initialiser le cookie et obtenir `{ "token": "…" }` |
| POST | `/api/v1/auth/login` | JSON `{ "email": "…", "password": "…" }`, avec `X-CSRF-Token` et cookie |
| GET | `/api/v1/auth/me` | Profil du compte connecté au format `{ "data": … }` |
| PATCH | `/api/v1/auth/me` | JSON `{ "timezone": "Europe/Paris" }`, avec CSRF |
| POST | `/api/v1/auth/logout` | Déconnexion avec CSRF, réponse 204 |

Après connexion, conserver le **nouveau** cookie et redemander `/auth/csrf` avant l’écriture suivante. Un jeton d’avant connexion est refusé. Une session absente/invalide donne 401 pour une lecture protégée ; les écritures passent aussi le contrôle CSRF, qui peut donner 403 en l’absence de jeton valable. Identifiants incorrects, compte inconnu ou inactif : même réponse 401 `invalid_credentials`. Un corps invalide donne 400/415/422 selon les conventions SOC-03. Seul le fuseau peut être écrit dans le profil ; les champs supplémentaires sont refusés.

Toutes les routes API enregistrées exigent une identité active, sauf la liste publique déclarée dans le pare-feu. Les routes techniques restent disponibles. Les fixtures `_test` ne sont enregistrées que dans l’environnement de test ; aucune route de test n’est livrée dans le routeur de production. Les endpoints métier encore planifiés ne sont pas simulés par AUTH-01. Le contrôle des rôles/magasins pour chaque opération métier relève d’AUTH-03 et du lot métier correspondant.

## Tentatives et audit

L’interface et l’API partagent les compteurs : cinq tentatives par paire **courriel normalisé + adresse IP** sur quinze minutes, et trente tentatives par IP sur quinze minutes, tous courriels confondus. Un succès remet à zéro le compteur courriel/IP, sans effacer le plafond IP. Le dépassement donne 429 et `Retry-After` en API ; le formulaire affiche un message français. Le calcul Argon2id est aussi exécuté pour un compte inconnu afin de réduire l’écart de durée avec un mot de passe incorrect.

Les compteurs et verrous Symfony sont partagés entre les processus du conteneur applicatif. Ils sont temporaires et remis à zéro lors de sa recréation. Le déploiement prévu comporte un seul conteneur applicatif ; plusieurs réplicas nécessiteraient un stockage de limites et de sessions commun. Les proxys de confiance doivent être configurés explicitement dans OPS-02 ; ne pas faire confiance à un en-tête client pour déterminer l’adresse IP.

Événements append-only dans `audit_logs` : `auth.login_failed`, `auth.login_throttled`, `auth.login_succeeded`, `auth.logout`, `auth.bootstrap_admin`. Pour un échec, aucun acteur n’est attribué ; le contexte ne contient que des pseudonymes HMAC-SHA256 du courriel normalisé et de l’IP, avec le secret applicatif. Aucun mot de passe, courriel saisi, adresse IP brute, cookie, jeton ou contenu JSON n’est enregistré dans cet audit. Ces pseudonymes restent des données à traiter selon la politique de conservation d’un an et RGPD-01. La consultation de l’audit est livrée par ADM-01.

## Vérification

```sh
docker compose --profile test build app web test contract-test
docker compose run --rm test
docker compose run --rm test composer audit
docker compose exec app php bin/console lint:container
docker compose exec app php bin/console lint:yaml config
```

Les tests AUTH utilisent PostgreSQL dans des schémas isolés. Les scénarios courants sont annulés par transaction ; le scénario PHP natif utilise un schéma indépendant supprimé en fin de test. Ils ne créent aucun compte dans les données applicatives. Voir [la recette AUTH-01](validation-auth01.md).

Référence de conception : [Symfony Security et rafraîchissement des utilisateurs](https://symfony.com/doc/current/security.html).

## Compte de démonstration

Un [administrateur de test](administrateur-test.md) peut être créé explicitement en mode `dev`. Son marqueur persistant interdit la connexion et le rafraîchissement des sessions dans tout autre environnement, même si la base est conservée.
