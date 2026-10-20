# Validation ALT-02 — 21 octobre 2026

Recette complète Docker réussie : **283 tests PHP / 3 981 assertions** et **4 validations OpenAPI indépendantes**. Les 13 nouveaux tests apportent 111 assertions.

## Couverture

- Normal vers bas à la limite exacte, bas vers critique, critique vers bas, retour à normal ; priorité critique et seuils égaux à zéro.
- Distinction entre zéro et non configuré ; changement de seuil sans changement de niveau ; seuil de l’alerte active actualisé sans nouveau franchissement.
- Consultations HTML et API répétées et écritures identiques : aucun événement ou courriel supplémentaire.
- Entrées, consommations, transferts, retours, ajustements, corrections et annulations : alertes calculées sur les soldes nets.
- Inventaire : comptage et soumission sans effet sur l’alerte ; validation avec ajustement et rejeu idempotent.
- Destinataires par magasin : administrateurs et gestionnaires actifs affectés ; autres magasins, utilisateurs ordinaires, comptes inactifs et comptes de développement exclus. Payload rendu avec un nom accentué à sa longueur autorisée.
- Échec injecté lors de l’insertion d’une notification : mouvement, solde, alerte et transition annulés ensemble.
- SMTP indisponible après validation : mouvement et alerte conservés, notification en échec prête pour reprise, sans diagnostic SMTP brut.
- Deux connexions PostgreSQL : requête de consommation rejouée simultanément, deux consommations distinctes franchissant les seuils et deux éditions identiques des seuils ; une seule alerte active et aucun doublon d’événement ou de notification.
- Historique des transitions et alertes résolues protégé contre l’altération et la suppression.
- Migration depuis un stock déjà configuré : initialisation de l’alerte et des notifications, quantité, version et journal conservés.

La migration 13 a été appliquée localement. Avant et après : 5 mouvements, somme des quantités 12, 1 inventaire, 0 alerte et 0 notification. Le solde est resté à 12, version 5, seuil bas 3 et critique 1. Aucun événement initial requis ; 0 transition créée. App, base, worker, scheduler et web sains. Aucun mouvement, seuil ou inventaire de démonstration ajouté aux données réelles ; envoi SMTP réel toujours désactivé.

Les écritures de recette utilisent des schémas PostgreSQL isolés, annulés ou supprimés après exécution. Aucun service SMTP externe n’a été contacté. Le contrôle avant publication recherche les valeurs réelles des secrets sans les afficher et compare les fichiers autorisés avec l’arbre GitHub final.

L’écran des alertes et les destinataires configurables restent dans ALT-03 ; le rappel quotidien dans MAIL-03. ALT-02 n’ajoute aucune nouvelle route API.

[Guide du cycle des alertes](cycle-alertes.md).
