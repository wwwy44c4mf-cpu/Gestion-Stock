# Validation MAIL-01 — 20 octobre 2026

La suite complète Docker passe : **270 tests PHP / 3 870 assertions**, ainsi que **4 validations OpenAPI indépendantes**. Les 12 nouveaux tests apportent 128 assertions.

## Preuves couvertes

- Transaction métier : création d’un événement idempotent, conflit si contenu différent, disparition après rollback. L’insertion ne contacte jamais SMTP.
- File durable : insertion Messenger et état `queued` atomiques ; rollback des deux ; message limité à l’identifiant opaque ; arrêt de la répartition quand l’envoi est désactivé.
- Livraison : versions HTML et texte, échappement, date Europe/Paris, cinq types de modèles, Message-ID stable et absence de renvoi d’un événement déjà envoyé.
- Panne SMTP : une entrée de stock réelle dans un schéma isolé reste validée et le solde reste à 7 ; quatre essais bornés et codes techniques sans diagnostic sensible.
- Véritable worker Messenger : trois reprises puis transport d’échecs, sans identifiant SMTP dans le message d’échec ; disponibilité forcée dans le passé uniquement dans le test pour éviter l’attente des délais de production.
- Concurrence : deux répartiteurs et deux workers sur des connexions PostgreSQL distinctes ; une seule mise en file et un seul envoi simulé.
- SMTP : dialogue TCP local réel avec acceptation du courriel multipart ; connexion refusée ; refus d’un transport qui détruirait silencieusement les messages. Aucun service externe contacté.
- Reprise opérateur : nouveau cycle borné, audit, historique conservé, refus de reprendre une notification envoyée.
- Destinataire : affectation retirée avant livraison refusée, interruption de l’envoi désactivé sans perte de l’événement ; validation des types, valeurs et liens.

Migration 12 (`Version20261021010000`) appliquée localement. Avant et après : 5 mouvements, somme des quantités 12, 1 inventaire, 0 notification ; après migration, 0 tentative. Aucun ajout de démonstration dans les données réelles. Répartition locale : 0 notification mise en file. App, base, worker, scheduler et web sont sains.

Les tests d’écriture utilisent des schémas isolés supprimés ou annulés après exécution. Les identifiants SMTP des tests sont fictifs. Le contrôle avant publication compare les fichiers autorisés et recherche les valeurs réelles des secrets sans les afficher. L’envoi réel reste désactivé ; le service SMTP existant doit être renseigné sur le serveur avant activation.

Limite SMTP documentée : une acceptation distante suivie d’une panne avant confirmation locale peut entraîner une répétition ; aucune garantie d’envoi exactement une fois n’est annoncée. Les producteurs d’alertes et les audiences ne sont pas encore raccordés (ALT-02/ALT-03/MAIL-02/MAIL-03).

[Guide de configuration et exploitation](courriels.md).

Livraison : [PR #74](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/pull/74), issue [#27](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/27). Base temporaire : PR #73 ; intégrer cette dernière avant MAIL-01.
