# UI-06 — Suppression d’un article désactivé depuis les stocks

Demande du 16 novembre 2026, suivie par [#125](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/125).

Un raccourci « Supprimer définitivement l’article » est proposé pour les articles désactivés dans la liste des stocks et dans le détail de leur stock. Les administrateurs et gestionnaires retrouvent le parcours ART-04 existant, sans réactiver l’article. Le contrôle serveur de tous les magasins, le récapitulatif, l’avertissement irréversible, la référence recopiée et la confirmation restent applicables. L’utilisateur simple ne reçoit pas le raccourci.

Le parcours efface la fiche, les stocks et tout l’historique de cet article dans tous les magasins concernés. Le magasin de la ligne affichée ne limite pas la portée de l’effacement. Aucune nouvelle migration, aucune suppression de données réelles.

Essais dans la maquette locale avec données fictives : visibilité selon le rôle et l’état de l’article, accès au récapitulatif depuis le détail, confirmation puis suppression effective sans réactivation, conservation exacte du stock d’un autre article et rapprochement des stocks sans écart. Les protections d’immutabilité restent réactivées après l’opération.

Cette modification n’est pas encore installée en production.
