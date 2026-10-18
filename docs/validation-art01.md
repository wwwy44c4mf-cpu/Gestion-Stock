# Validation ART-01 — 18 octobre 2026

Livraison : [PR #60](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/pull/60).

## Résultat

**109 tests PHP / 1 526 assertions** réussis depuis les images Docker finales, ainsi que les quatre validations indépendantes OpenAPI. La migration `Version20261018140000` a été appliquée explicitement à la base locale.

## Cas vérifiés

- Articles : champs et statuts à la création, référence unique sans distinction de casse/espaces et non réutilisable après archivage, référence immuable en API et en base, unité unique, description et catégorie facultatives.
- Version obligatoire, modification périmée refusée, formulaire conservant sa version originale après conflit et lien de rechargement. Pas de nouvel audit ni version pour une modification sans changement effectif.
- Rejeu persistant restituant le résultat de création initial après archivage ; conflit si la clé est réutilisée avec un autre contenu.
- Catégories : parent facultatif, cycles directs et indirects refusés, retrait du parent, nouvelles affectations à une catégorie inactive refusées.
- Désactivation et réactivation de **toute la branche**, catégories et articles compris. La réactivation reprend les articles auparavant désactivés. Les autres branches restent intactes. Versions et événements d’audit par élément ; rollback complet si une version ne peut plus changer.
- Nouvelle consommation d’un article inactif refusée par une écriture PostgreSQL réelle, données historiques et stocks conservés, retours possibles, consommation de nouveau possible après réactivation. Suppression physique d’un article mouvementé refusée.
- Droits ADMIN pour les écritures, catalogue global en lecture pour MANAGER et USER, CSRF API/formulaires, filtres et pagination, recherches littérales, texte échappé et absence de données de stock dans les réponses du catalogue.
- **Deux processus PHP simultanés** : rejeu de création sans doublon ; conflit de référence ; une seule modification pour une version donnée ; impossibilité de former un cycle de catégories par deux changements concurrents.
- Migration vierge/réexécution/retour arrière. Retour arrière refusé en présence de résultats de création ou d’articles inactifs ; protection de consommation conservée.
- Non-régression : comptes et sessions natives, administrateur de développement, magasins et contrôles de périmètre, invariants existants du schéma.

Les tests utilisent des schémas PostgreSQL isolés, annulés ou supprimés à la fin. Les messages d’erreur déclenchés par les tests de refus sont attendus.

## Interface locale

Catalogue, formulaire d’article, liste et formulaire de catégorie ouverts dans le navigateur avec le compte de développement. Formulaire d’article vérifié sur ordinateur et mobile : largeur utile de 375 pixels, aucun débordement horizontal. L’information sur la propagation de statut et la réactivation des articles auparavant désactivés est présente dans le formulaire de catégorie. Les soumissions, modifications et conflits sont vérifiés par les tests fonctionnels isolés.

Application, serveur web, base, worker et scheduler sains après mise à jour. Aucun article ou catégorie de démonstration n’a été ajouté à la base locale. Les données existantes des magasins sont conservées.

## Lots suivants

La gestion des propositions reste dans ART-02. La liste et ses filtres de base sont disponibles ; ART-03 conserve sa recette dédiée de recherche/consultation. Les stocks par article et les autres règles de mouvements appartiennent aux lots STK ; aucun endpoint métier simulé n’est ajouté.
