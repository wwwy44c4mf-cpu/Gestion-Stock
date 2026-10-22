# Réinitialiser un mot de passe — AUTH-04

## Utilisateur

Depuis **Connexion → Mot de passe oublié ?**, saisir son adresse. L’écran donne toujours la même confirmation, que l’adresse soit inconnue, inactive ou autorisée. Vérifier la boîte de réception et les indésirables ; si aucun message n’arrive, contacter l’administrateur. L’envoi dépend de la configuration SMTP et de son activation : la confirmation n’atteste pas de la livraison.

Le lien est valable **30 minutes à partir de la demande**, à usage unique. Une nouvelle demande autorisée remplace les anciens liens ; il faut attendre au moins une minute entre deux demandes pour le même compte. Ouvrir le lien ne consomme rien : le serveur retire le jeton de l’URL par une redirection et le conserve dans la session le temps du formulaire. Cela permet aussi aux outils de prévisualisation d’ouvrir le lien sans changer le mot de passe.

Saisir deux fois un mot de passe de 12 à 128 caractères. En cas d’erreur, les champs de mot de passe sont vidés. Après succès, revenir à la connexion avec le nouveau mot de passe : **aucune connexion automatique**, les anciennes sessions sont invalidées et un courriel d’information est mis en file. Un lien déjà utilisé, expiré ou remplacé donne la même erreur générique. Ne pas partager ni publier un lien reçu.

## Administration et exploitation

Configurer SMTP, l’expéditeur et **APP_PUBLIC_URL** suivant [le guide des courriels](courriels.md). L’adresse du lien vient de cette configuration, jamais de l’en-tête Host du navigateur. En production, HTTPS est obligatoire. Aucun nouveau secret ou paramètre de durée à saisir : les valeurs du MVP sont 30 minutes et une minute entre demandes.

La requête publique est limitée à cinq tentatives par IP et opération par minute, avec le même compteur pour interface et API. La limite par compte reste invisible dans la réponse. Les demandes connues, inconnues, inactives et déjà limitées passent par une durée minimale de traitement de 150 ms pour réduire les écarts de temps ; ce plancher ne garantit pas des latences strictement identiques sous contention ou panne.

Les comptes inactifs ou anonymisés ne reçoivent pas de lien. Les comptes `development_only` ne peuvent utiliser ce parcours qu’en `dev`. Une modification de l’adresse, de la version d’authentification ou du hachage de mot de passe invalide les liens, y compris ceux déjà en attente d’envoi. Une désactivation bloque immédiatement leur utilisation ; une nouvelle réinitialisation après réactivation reste soumise à l’état courant du compte.

La base existante `password_reset_tokens` suffit : **aucune migration supplémentaire**. Elle conserve une empreinte SHA-256 du jeton. Le jeton combine un sélecteur UUID aléatoire et un HMAC secret lié à l’état du compte ; le sélecteur seul ne permet pas de réinitialiser. L’outbox conserve uniquement ce sélecteur sous `reset_id`, et Messenger uniquement l’identifiant de notification. Le lien utilisable est reconstitué en mémoire lors du rendu, après relecture du compte, du destinataire et de l’échéance. Il n’est pas enregistré en clair dans la base ou la file. Une rotation du secret applicatif invalide tous les liens non consommés : demander un nouveau lien.

Le mot de passe Argon2id, la révocation des sessions, la consommation des jetons, l’audit et la notification de confirmation sont validés dans une transaction. Une panne annule tout. Les demandes et confirmations concurrentes sont sérialisées avec l’administration des comptes ; une seule confirmation d’un même jeton peut réussir. Le SMTP reste hors de cette transaction ; un message périmé n’est pas livré et apparaît en erreur de rendu dans les diagnostics. Le transport conserve les protections de reprise de MAIL-01 ; une acceptation SMTP ambiguë peut conduire à un doublon de courriel, mais jamais à une seconde utilisation du lien.

Les journaux ne contiennent ni corps de formulaire, ni jeton, ni mot de passe. Les pages de réinitialisation portent `no-store, private` et `Referrer-Policy: no-referrer`. Le jeton reçu dans le lien est conservé temporairement dans la session native protégée, hors des rapports et de la base. Les jetons expirés sont purgés par le traitement de conservation existant ; voir [RGPD-01](conservation-donnees.md).

Ces choix suivent les principes de réponse générique, limitation des demandes, lien à usage unique et absence de connexion automatique décrits par [OWASP — Forgot Password](https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html).

## API

- `POST /api/v1/auth/password-reset/request` : JSON `{"email":"personne@example.test"}`, session CSRF anonyme requise ; réponse **202** `{"message":"…"}`, jamais de jeton ni d’identifiant de compte.
- `POST /api/v1/auth/password-reset/confirm` : JSON `{"token":"LIEN_RECU","password":"NOUVEAU_MOT_DE_PASSE"}`, session CSRF requise ; **204** après succès, **422** générique pour lien inutilisable.

Récupérer le CSRF via `/api/v1/auth/csrf` et conserver son cookie. Formats, tailles et champs sont contrôlés selon [OpenAPI](openapi.json). CSRF invalide : 403 ; limites IP : 429 ; JSON incorrect : 400/415/422. Aucun paramètre de recherche n’est accepté sur les écritures API. Les écrans `/mot-de-passe/oublie` et `/mot-de-passe/reinitialiser` fonctionnent sans JavaScript, avec leurs formulaires POST protégés.
