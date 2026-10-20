# Validation ALT-01 — 20 octobre 2026

La suite complète Docker passe : **258 tests PHP / 3 742 assertions**, avec **4 validations OpenAPI indépendantes**. Les 9 nouveaux tests couvrent les seuils séparés par magasin, zéro et null, absence de valeur globale, bornes, formats invalides, droits vivants, magasin désactivé, article désactivé, CSRF, lien selon le rôle, formulaire périmé et modification des quantités pendant son ouverture.

Les tests isolés vérifient la conservation des quantités et versions, l’absence de mouvement et d’événement, les mises à jour partielles, le retrait des seuils et l’audit uniquement lors d’une modification effective. Une panne injectée de l’audit annule également la première création du solde.

Deux connexions PostgreSQL réelles vérifient les deux ordres de création d’un solde par une entrée et une configuration de seuils, puis deux écritures de seuils identiques simultanées. Les quantités et versions sont conservées, les mouvements et événements ne sont pas dupliqués et l’audit de configuration n’est pas répété.

Interface vérifiée sur ordinateur et à 390 pixels de large, sans débordement horizontal. Formulaire ouvert sur les données existantes sans enregistrement : quantité conservée à 13, seuils laissés non configurés. Application, base, traitements et serveur web sains après mise à jour locale. Aucune nouvelle migration nécessaire.

Les tests d’écriture utilisent des schémas PostgreSQL isolés et supprimés après exécution. Aucun seuil, mouvement ou inventaire de démonstration ajouté aux données réelles. Le cycle persistant des alertes et les courriels restent à développer dans ALT-02 et les lots MAIL.
