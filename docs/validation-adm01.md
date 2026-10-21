# Validation ADM-01 — 21 octobre 2026

Recette complète réussie : **368 tests PHP / 4 797 assertions** et **4 validations OpenAPI indépendantes**. Les neuf nouveaux scénarios d’audit comptent **126 assertions**. Ils couvrent la consultation, les droits, les contextes publics, les protections d’écriture et les producteurs réels. Les requêtes de mutation volontairement refusées et les pannes injectées des modules existants produisent des messages attendus dans les journaux de test.

- Administrateur : journal global et magasins désactivés ; gestionnaire : seulement magasins actifs affectés ; gestionnaire sans affectation : liste vide ; utilisateur : 403 et absence du lien. Accès anonyme API : 401 ; HTML : redirection connexion.
- Périmètre appliqué avant comptages et pagination, retrait d’affectation pris en compte, identifiants de magasins extérieurs refusés. Options d’actions et d’acteurs limitées aux lignes visibles, sans courriels.
- Mouvement partiellement accessible : acteur, cible et contexte masqués ; filtre par acteur caché sans résultat ni fuite dans les options. Après autorisation des deux magasins, attribution complète visible. Les événements restent rattachés au magasin réellement enregistré lors de l’action.
- Contexte projeté positivement : secrets de test et structures inconnues absents des réponses ; empreintes de connexion non exposées même aux administrateurs ; zéro et absence de seuil préservés. Réponse vérifiée contre le schéma OpenAPI, contexte vide conservé comme objet JSON. Acteurs anonymisés ou absents lisibles, libellés HTML échappés.
- Filtres combinés date/magasin/acteur/action, dates inclusives au changement d’heure, Pacific/Honolulu, tri stable et pagination sans doublon. Paramètres inconnus, imbriqués, dates impossibles et pagination hors limites refusés.
- Routes POST/PATCH/PUT/DELETE refusées, et mises à jour/suppressions SQL ordinaires du journal bloquées par le déclencheur existant. La consultation n’ajoute aucun événement.
- Actions sensibles produites dans un même scénario de recette : changement de rôle, modification de magasin et d’article, entrée de stock, correction avec contre-mouvement/remplacement, seuils et création/démarrage/annulation d’inventaire. Chaque événement est attribué à l’administrateur authentifié et horodaté ; commentaires et motifs libres absents de la réponse. Les tests existants de rollback des producteurs sont réexécutés par la recette complète.

## Vérification locale

Le journal existant est consulté avec le compte de développement, sans création d’action métier de démonstration. Pagination réelle vers la page 2, réinitialisation, combinaison des cinq filtres et contexte déplié au clavier vérifiés. La modification de seuil du 20 octobre apparaît seule pour les filtres choisis : seuils précédemment non configurés, puis bas = 3 et critique = 1. Présentation du contexte en français.

Contrôle ordinateur et téléphone à 390 pixels : aucun débordement de page ; le tableau dispose de son défilement horizontal local et les valeurs ne sont pas comprimées. Le menu et le retour au journal sont accessibles.

Aucune migration ajoutée : 16 migrations installées. Avant/après : cinq mouvements, somme des stocks 12, un inventaire, zéro notification et un lot quotidien préexistant. Les deux reconnexions après reconstruction expliquent le passage de 61 à 63 événements d’audit et de 43 à 45 connexions réussies. Courriels réels désactivés ; app, base, web, worker et scheduler sains. Les tests d’écriture utilisent des schémas isolés annulés après chaque scénario.

Contrat : **61 opérations / 97 schémas**, dont **59 disponibles / 2 prévues**. Aucun contrat d’écriture métier changé. Rétention et anonymisation : RGPD-01, sans modification de la décision d’un an.

[Guide](journal-audit.md) · [Issue #33](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/33).
