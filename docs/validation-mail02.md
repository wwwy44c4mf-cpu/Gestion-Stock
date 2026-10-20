# Validation MAIL-02 — 21 octobre 2026

Recette complète réussie : **311 tests PHP / 4 212 assertions** et **4 validations OpenAPI indépendantes**. Les **14 nouveaux tests / 113 assertions** comprennent un test avec deux connexions PostgreSQL réelles. Les messages de panne injectée et de retour arrière refusé dans les journaux sont attendus par les tests.

- Critères magasin et rôle cumulés ; union au sein de chaque critère, doublons d’affectation éliminés et aucun administrateur ajouté implicitement à un magasin.
- Comptes inactifs et réservés au développement exclus ; audience vide visible mais non envoyable. Prévisualisation sans écriture ni appel SMTP.
- Confirmation HTML explicite, CSRF, formulaires tronqués, champs inconnus et valeurs imbriquées refusés. Audience ou message modifiés, signature falsifiée et expiration refusés.
- API réservée aux administrateurs, réponses 202 confrontées au schéma publié, reçu et historique protégés, limite de cinq appels par minute et accès anonyme contrôlé.
- Même clé et même contenu : mêmes événements ; autre contenu : conflit. Deux confirmations simultanées : une campagne, un événement par personne et un seul audit.
- Campagne, audience, événements et audit annulés ensemble lors d’une panne d’audit injectée. Historique immuable et retour arrière refusé si une campagne existe.
- Objet de 200 caractères accentués et message de 10 000 caractères acceptés ; dépassement et injection d’en-tête refusés, texte échappé dans l’interface.
- Livraison simulée : personne sortie de la cible non envoyée, personne maintenue envoyée une seule fois. Rôle, adresse, activité du compte, compte de développement, affectation et activité du magasin recontrôlés avant livraison.

Le contrat comporte 61 opérations et 96 schémas : 55 disponibles, 6 prévues. Aucune extension du contrat de création n’a été nécessaire.

Migration 15 appliquée localement. Avant/après : 5 mouvements, somme des quantités 12, un inventaire, zéro notification. Après vérification visuelle : zéro campagne ; aucune donnée fictive ajoutée aux données réelles et aucun courriel réel envoyé. App, base, worker, scheduler et web sains. Envoi SMTP réel conservé désactivé.

Contrôle visuel du formulaire, de la prévisualisation vide et de l’historique sur ordinateur ; formulaire à 390 pixels, largeur de page 390 pixels, sans débordement horizontal. Les essais d’écriture et de concurrence utilisent des schémas PostgreSQL isolés, annulés ou supprimés à la fin.

[Guide](messages-information.md) · [Issue #28](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/28).
