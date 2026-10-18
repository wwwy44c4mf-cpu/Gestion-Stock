# Validation SOC-03

Date : 18 octobre 2026, Europe/Paris. Recette Docker Linux/arm64, PHP 8.4.26, PostgreSQL 18. SOC-02 est intégré à main par la PR #52 ; SOC-03 est livré pour revue séparée.

## Résultats

| Exigence | Preuve vérifiée | Résultat |
|---|---|---|
| Contrats du MVP | OpenAPI 3.1, 58 opérations, 87 schémas ; propositions, bénéficiaires, retours et totaux autorisés inclus | Livrés |
| Structure OpenAPI | Schéma officiel conservé localement, validateur indépendant sans réseau | Réussie |
| Schémas et références | JSON Schema 2020-12, références locales résolues ; documents invalides rejetés | Réussis |
| Erreurs normalisées | Réponses réelles 400, 403, 404, 405, 413, 415, 422, 429 et 500 ; schéma Problem, identifiant de requête, Allow et Retry-After | Vérifiées |
| Absence de fuite | Contenu client et détail d’exception non renvoyés ; identifiant client non repris | Vérifiée |
| Corps JSON | Objet, types, champs inconnus, taille, quantité zéro/négative/fraction infime/exposant/débordement | Refus attendus |
| Filtres et pagination | Bornes page/limit, clés de tri autorisées, paramètres inconnus, tableaux inattendus, UUID/date/période invalides | Refus attendus |
| Dates inclusives | Conversion des journées de changement d’heure Europe/Paris | 23 h et 25 h correctes |
| CSRF | Sans jeton ou avec jeton d’une autre session : 403 ; requête valide acceptée sur sonde de test | Vérifié |
| Cookie natif | Processus isolé, Kernel prod, requête HTTPS reconnue | Nom configuré, Secure, HttpOnly, SameSite=Lax et no-store vérifiés |
| Débit | Cinq appels sensibles autorisés puis 429 avec délai ; verrouillage flock disponible | Vérifié |
| Idempotence préparée | Format d’en-tête, empreinte canonique stable selon ordre des objets et distincte par acteur, méthode/chemin/contenu | Vérifiés |
| Précision des agrégats | Somme supérieure à la précision entière JavaScript représentée par chaîne décimale | Vérifiée |
| Information ciblée | Au moins une cible magasin/rôle non vide | Vérifiée |
| Régressions | Infrastructure, queue durable et tests du schéma SOC-02 | Réussies |
| Suite PHP | PHPUnit | 34 tests, 528 assertions |
| Validation indépendante | Conteneur contract-test | 4 tests réussis |
| Configuration et dépendances | lint:container, lint:yaml config, composer audit | Réussis ; 8 fichiers YAML ; aucune vulnérabilité PHP connue signalée |
| Exposition réelle | Table de routes en prod | Seulement health, openapi.json et auth/csrf sous /api/v1 ; aucune route de test |
| HTTP depuis l’hôte | Jeton CSRF natif et contrat servis sur loopback | Cookie HttpOnly/SameSite=Lax, no-store ; contrat identique au fichier versionné |

## Portée et limites

Les refus de données concernent les outils partagés testés dans des contrôleurs de sonde réservés à APP_ENV=test. Les 55 opérations métier marquées planned ne sont pas encore actives. Les droits de rôle et magasin sont documentés, mais leur exécution et les vérifications 401/403/404 d’accès aux données seront développées dans AUTH-03 et les lots métier. Les contrôles de stock/concurrence ne sont pas remplacés par JSON Schema.

L’empreinte et l’en-tête d’idempotence sont prêts ; aucune protection de rejeu persistante fictive n’est revendiquée. Elle sera branchée dans les transactions des opérations concernées, notamment STK-02. La réinitialisation, l’expiration des sessions et l’invalidation des comptes restent dans AUTH-01/02/06/07 selon le découpage.

Le cookie HTTPS est vérifié avec une requête reconnue HTTPS par Symfony et le stockage PHP natif ; l’environnement exposé reste HTTP local. Le domaine, TLS et la reconnaissance des proxys restent dans OPS-02. Les limites et sessions sont locales au conteneur applicatif, conformément au déploiement mono-serveur ; plusieurs réplicas nécessiteraient un stockage partagé.

Le document décrit les règles croisées et périmètres à appliquer côté serveur ; le fait qu’une opération figure dans OpenAPI ne signifie pas qu’elle est disponible. Les tests indépendants garantissent la structure du contrat, pas la future réalisation de chacun de ses traitements.

Reproduction : [api.md](api.md), [README](../README.md). Le schéma tiers et sa licence sont archivés dans [schemas/NOTICE.md](schemas/NOTICE.md).
