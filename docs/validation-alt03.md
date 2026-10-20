# Validation ALT-03 — 21 octobre 2026

Recette complète Docker : **297 tests PHP / 4 099 assertions** et **4 validations OpenAPI indépendantes** réussis. Quatorze nouveaux tests couvrent l’écran, les API, les droits, les formulaires et les mises à jour concurrentes.

- Listes actives et historiques, filtres par magasin, état et gravité, tri stable et pagination. Totaux et labels limités aux magasins autorisés ; articles échappés, aucun événement créé par lecture.
- Administrateur : tous les magasins en lecture ; gestionnaire : magasins actifs affectés ; utilisateur simple refusé. Magasin désactivé non configurable. Retrait d’affectation pris en compte immédiatement.
- Destinataires par défaut, personnes sélectionnables sans adresses ni affectations extérieures, préférences personnalisées par type, liste vide, restauration dynamique et répétitions sans doublon d’audit.
- Préférences appliquées aux nouveaux franchissements ; pas de renvoi lors d’une édition. Retrait après mise en file pris en compte avant livraison. Aucun compte d’un autre magasin, utilisateur ordinaire ou doublon accepté.
- CSRF, requêtes malformées, champs inconnus, formulaires incomplets et sélections imbriquées refusés. Formulaire périmé et ETag périmé refusés sans écrasement.
- Échec d’audit injecté : configuration, version et liste explicite annulées ensemble.
- Deux connexions PostgreSQL modifiant la même configuration depuis la même révision : une écriture acceptée, l’autre refusée, un seul audit.
- Réponses confrontées aux schémas publiés. Le contrat contient maintenant 61 opérations et 96 schémas, dont 54 opérations disponibles et 7 prévues. Une route dédiée aux personnes sélectionnables évite d’exposer le répertoire complet des comptes aux gestionnaires.

La migration 14 est appliquée localement. Avant et après : 5 mouvements, somme des quantités 12, 1 inventaire, 0 alerte, 0 notification, 0 abonnement explicite et 0 configuration. Aucun enregistrement de démonstration ajouté aux données réelles. App, base, worker, scheduler et web sains. SMTP réel conservé désactivé.

Contrôle visuel en lecture seule sur ordinateur et à 390 pixels : liste, filtres et formulaire, sans débordement horizontal de page. Les états vides correspondent aux données réelles ; aucun destinataire de test n’a été ajouté. L’exception des comptes réservés au développement est expliquée dans le formulaire. Les essais d’écriture utilisent des schémas isolés, annulés ou supprimés après exécution.

Choix utilisateur du 21 octobre : possibilité de retirer des administrateurs ou gestionnaires de la liste par défaut, pour chaque magasin et type. Le rappel quotidien est configurable mais son envoi appartient à MAIL-03.

[Guide](alertes-et-destinataires.md).
