# Adresse neutre de partage — Workers

Adresse à configurer : `https://gestion-stock.materiel-reseau.workers.dev`.
Origine existante conservée sur Home Assistant, via son tunnel Cloudflare. Ce relais ne transforme pas le nom de domaine en secret et ne remplace pas l’authentification de Gestion-Stock.

## Installation

Dans Cloudflare, Worker `gestion-stock`, remplacer entièrement Hello World par [worker.mjs](../docker/cloudflare/worker.mjs), puis déployer. L’hôte accepté par le code est exact : si le nom de Worker ou le sous-domaine du compte change, adapter `PUBLIC_ORIGIN`. Aucune clé ni mot de passe utilisateur n’est à insérer. Ne changer ni les ports du serveur, ni le tunnel, ni les restrictions du serveur domotique.

## Mesures et vérifications

Origine HTTPS fixée dans le code ; pas de paramètre permettant un autre serveur. Les redirections ne sont jamais suivies côté relais avec les cookies : elles sont renvoyées sur le nom public ou refusées si externes. Les écritures provenant d’un autre Origin sont refusées. Les cookies sont renvoyés séparément, limités à l’hôte public et forcés Secure ; HttpOnly et SameSite sont conservés. CSP et Permissions-Policy restent celles de l’application. Toutes les réponses sont privées et non stockables par les caches. Aucune journalisation du contenu des requêtes, des cookies ou des identifiants.

Cloudflare peut utiliser une IP de service pour les sous-requêtes entre zones ; le contrôle de débit par IP du serveur peut donc être partagé entre les utilisateurs de ce relais. Les contrôles de compte, sessions, CSRF et droits restent ceux de l’application. Vérifier le comportement réel de la connexion après déploiement. Ne pas accepter des en-têtes de client arbitraires comme source IP de confiance pour contourner cette limite.

Le plan gratuit est limité à 100 000 requêtes quotidiennes ; workers.dev est prévu pour des projets personnels/de test, sans engagement de disponibilité métier. Les données restent sur le mini-PC ; les requêtes, comme celles du tunnel existant, transitent par Cloudflare. Un compte applicatif reste nécessaire après lecture du QR code.

Les notifications utilisent encore APP_PUBLIC_URL défini pour l’adresse d’origine. Si l’envoi de courriels est activé ultérieurement, préparer séparément l’adresse publique de génération des liens ; ne pas remplacer APP_DOMAIN sans adapter le routage existant.

## État de recette

- Six tests Node du relais réussis (origine fixe, corps POST/CSRF/session, redirections, écritures externes, cookies multiples et erreurs neutres).
- Test navigateur contre l’application locale avec données fictives et adaptateur de nom : connexion, cookie Secure/HttpOnly/SameSite, caméra simulée, sortie puis retour inter-magasins et déconnexion réussis. Aucune donnée ni caméra réelle utilisée.
- Confirmé par l’utilisateur sur le vrai Worker : page de connexion, accès authentifié au tableau de bord sans changement du nom neutre, caméra et reconnaissance d’un code depuis cette adresse. Les opérations de stock et la déconnexion via le relais ont été vérifiées sur les données fictives locales ; ne pas les présenter comme un essai réel supplémentaire sur cette origine.
- QR code PNG et SVG générés ; le fichier PNG final a été décodé vers l’adresse exacte. [Version vectorielle pour impression](partage/gestion-stock-qr.svg). Le scan du QR ouvre le site mais ne connecte pas automatiquement un utilisateur.
- Les essais Android Firefox et Edge restent suivis dans #97.

Sources : [workers.dev](https://developers.cloudflare.com/workers/configuration/routing/workers-dev/), [limites](https://developers.cloudflare.com/workers/platform/limits/), [redirections et requêtes](https://developers.cloudflare.com/workers/runtime-apis/request/), [en-têtes IP](https://developers.cloudflare.com/fundamentals/reference/http-headers/).
