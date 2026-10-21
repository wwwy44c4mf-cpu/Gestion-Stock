# Validation OPS-03 — 21 octobre 2026

Recette applicative complète : **385 tests PHP / 4 952 assertions**, plus **4 contrôles OpenAPI** réussis sous Docker. Les **13 tests de supervision comptent 99 assertions**. Aucune dépendance ni migration ajoutée ; contrat HTTP inchangé : 61 opérations / 97 schémas.

## Journaux, accès et indicateurs

- JSON UTC, niveaux, identifiants générés côté serveur pour HTML/API et corrélation de l’erreur API avec l’en-tête. Un identifiant fourni par le client n’est pas utilisé comme autorité.
- Projection positive du contexte : messages d’exception, DSN, destinataires, corps, cookies, jetons, commandes libres et objets arbitraires absents. Un objet dont `__toString()` échoue peut être fourni au logger sans être sérialisé.
- Niveau minimal, codes de résultat bornés, échec Messenger sans payload, accès HTTP aux routes de diagnostic absent (404).
- Classification réelle de messages disponibles, différés, en cours et à reprendre après expiration ; délai spécifique notifications 120 secondes, autres transports 3 600 secondes. Les noms inconnus sont regroupés sans révéler leur contenu.
- Notifications échouées, tentatives épuisées et compteurs SMTP visibles ; retard de mise en file signalé uniquement si les envois sont activés. Contrôle SQL en lecture seule, transaction appelante conservée, panne de base et seuil invalide expurgés.
- Battement manquant, périmé ou futur refusé. Contrôle des services arrêtés, suspendus, malsains ou absents, sauvegarde et proxy requis selon le mode.

Les tests de cookies/sessions natives gardent leurs 41 assertions dans des processus séparés ; leur niveau de journalisation de test est réglé à `emergency` pour préserver le protocole PHPUnit sur stderr. La journalisation réelle est testée séparément, notamment par les recettes ci-dessous, sans changement du niveau `info` de l’application déployée.

## Pannes et confidentialité sur installation isolée

`sh bin/test-operations` terminé avec succès. Projet unique, faux secrets, SMTP sur le port 1 du conteneur, aucun port publié, cleanup de tous les profils et volumes du projet.

1. Tous les services deviennent sains ; le diagnostic complet renvoie 0.
2. Worker arrêté avant mise en file : le contrôle complet échoue et le rapport voit un message disponible, sans lire son corps.
3. Véritable consommateur Messenger, envois activés uniquement pour la recette : refus SMTP local, notification en échec et compteur SMTP incrémenté. Tentative et événement `message_failed` présents, sans identifiants SMTP ni contenu du courriel.
4. Erreur de commande avec argument confidentiel factice : aucun réaffichage de cet argument dans le rendu ni les journaux.
5. Erreur SQL d’unicité avec données de ligne factices : aucun détail de ligne ni requête complète dans les journaux PostgreSQL collectés. Réglages `terse`, niveau de requêtes en erreur `panic`, paramètres liés désactivés.
6. Marqueurs dans URL, Authorization et Cookie ; réponse Nginx 403 et requête HTTPS normale : marqueurs absents des journaux app/worker/scheduler/web/proxy/base. Les erreurs HTTP restent visibles dans les accès structurés.
7. Amont web arrêté : réponse Caddy 502 et accès structuré 502 conservés, sans objet requête ou en-têtes de réponse dans le journal du proxy.
8. Base arrêtée : rapport `unavailable`, code `database_or_schema_unavailable`, sortie non nulle et absence de diagnostic SQL original.

Les battements et états de conteneurs ont aussi leurs tests automatisés indépendants. Les données/volumes du projet principal ne sont pas utilisés par les incidents.

## Compatibilité de production et reprise

`sh bin/test-production` réussi : deux passages de cinq tests HTTPS, certificat local vérifié, connexion native de production, CSRF et compte de développement refusés, identifiant de réponse unique et non imposé par le client. Migration 15 → 16 sur données présentes et restauration de l’instantané à 15 migrations dans un volume séparé, empreintes et stocks/journal/audit/file vérifiés. Les ajustements de journaux natifs ont ensuite leur propre recette HTTP/SQL/SMTP décrite ci-dessus.

## Installation locale

Diagnostic complet sain, six services réels avec rotation `local` à trois fichiers de 10 Mio ; sauvegarde chiffrée avant redémarrage et inspection réussie, 33 tables. Les volumes sont conservés et les envois réels restent désactivés.

Avant/après : **16 migrations, cinq mouvements, somme des stocks 12, un inventaire, zéro notification, un lot quotidien, 63 audits dont 45 connexions réussies**, aucun écart de réconciliation. Les cinq événements durables de stock déjà présents sont signalés comme information : ils ne sont pas assimilés à la file Messenger et n’ont pas été modifiés pour rendre le diagnostic sain.

La supervision extérieure et sa notification ne sont pas configurées : responsable, disponibilité cible, fréquence de contrôle et canal restent à choisir avant production. Les commandes et leurs codes de sortie sont utilisables ; elles ne détectent pas à elles seules l’extinction complète du serveur.

[Guide de supervision](supervision-diagnostic.md) · [Issue #36](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/36).
