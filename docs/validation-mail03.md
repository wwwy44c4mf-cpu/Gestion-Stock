# Validation MAIL-03 — 21 octobre 2026

Recette complète réussie : **326 tests PHP / 4 319 assertions** et **4 validations OpenAPI indépendantes**. Les **15 nouveaux tests / 107 assertions** couvrent la génération, la livraison et deux connexions PostgreSQL concurrentes. Les journaux d’échecs injectés et de retours arrière refusés sont attendus par la recette.

## Périmètre vérifié

- Planification réelle enregistrée : 08 h Europe/Paris, plus contrôle chaque minute pour le rattrapage du jour. Messages reliés au générateur dans Messenger. Tests des passages à l’heure d’été et d’hiver et des instants UTC correspondant à 08 h.
- Aucun lot avant 08 h. Rattrapage à 15 h, aucune création de journées anciennes, répétition du même jour sans doublon et génération du lendemain. Une matinée sans alerte est enregistrée sans message vide ; une alerte survenant ensuite conserve son courriel immédiat.
- Un seul courriel par personne pour tous ses magasins autorisés, tous les articles inclus, niveau et quantités exacts. Test de 1 001 alertes sans troncature. Magasins désactivés, utilisateurs simples, gestionnaires non affectés, comptes de développement et inactifs exclus.
- Préférences personnalisées prises en compte à la génération et avant livraison. Retrait d’un magasin dans un récapitulatif regroupé : autres magasins conservés, données retirées absentes.
- Avant envoi : rôle, affectation, adresse, état du compte, activité du magasin, abonnement et statut des alertes relus. Quantités rafraîchies. Alerte résolue puis rouverte non ajoutée au lot initial. Message d’un jour précédent refusé même si ses alertes sont encore actives ; lot vidé non envoyé.
- Livraison simulée par le véritable handler : un seul envoi après redélivrance, HTML échappé et version texte avec niveau de stock. Aucun appel SMTP réel.
- Échec d’audit injecté : événements, lot, exécutions par personne et liens aux alertes annulés ensemble ; reprise réussie. Deux connexions PostgreSQL concurrentes : une génération effective, un seul lot, un événement par personne et un seul audit.
- Historique protégé contre modification/suppression. Migration intégralement réversible sur schéma vide ; retour arrière refusé après création d’un lot.

## Installation locale

Migration 16 appliquée. Avant/après : 5 mouvements, somme des quantités 12, un inventaire et zéro notification. Zéro lot quotidien et zéro exécution par destinataire après contrôle avant 08 h ; aucune donnée fictive ajoutée aux données réelles. La commande installée renvoie zéro avant 08 h. L’envoi SMTP réel demeure désactivé.

La génération n’appelle pas SMTP et ne modifie pas les stocks ; les alertes immédiates existantes restent indépendantes. Les essais d’écriture utilisent des schémas PostgreSQL isolés, annulés ou supprimés après exécution. La nouvelle dépendance horaire est verrouillée dans Composer, sans mise à jour des autres paquets ; aucune vulnérabilité signalée lors de sa résolution.

Aucune route API ajoutée : contrat inchangé, 61 opérations / 96 schémas, dont 55 disponibles et 6 prévues.

Décision utilisateur : rattraper uniquement le jour courant après un arrêt à 08 h, sans rappel des journées précédentes.

[Guide](recapitulatif-quotidien.md) · [Issue #29](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/29).

Livraison : [PR #78](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/pull/78), empilée sur la PR #77.
