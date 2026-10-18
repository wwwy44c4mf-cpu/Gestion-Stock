# Validation ART-02 — 18 octobre 2026

## Résultat

**124 tests PHP / 1 717 assertions** réussis depuis les images Docker finales, ainsi que les quatre validations indépendantes OpenAPI. Migration `Version20261018150000` appliquée explicitement sur la base locale.

## Cas vérifiés

- Soumission par un gestionnaire sans modification du catalogue ; publication d’une création ou modification uniquement par ADMIN.
- Liste, compteurs et pagination filtrés par auteur pour MANAGER ; accès direct à une proposition étrangère refusé. USER n’accède pas aux propositions ; noms utiles sans profil complet.
- Approbation et refus, commentaire de décision visible par l’auteur, refus sans motif ou avec texte vide/invalide rejeté.
- Référence/unité immuables, catégorie existante et active, changements effectifs et version de départ contrôlés à la soumission ; nouvelle vérification à l’approbation.
- Article modifié depuis la proposition : publication refusée, nouvelles valeurs conservées, proposition toujours refusable. Référence publiée entre-temps ou catégorie devenue inactive : pas de publication partielle.
- Rejeu de soumission restituant le résultat initial après décision ; GET montrant l’état courant. Rejeu identique de décision sans second article ni second audit ; clés en conflit et nouvelle décision sur une proposition déjà examinée refusées.
- CSRF, champs et types, identifiants invalides, échappement des commentaires, encodage UTF-8 des textes et affichage des références valides telles que « 0 ».
- **Deux processus PHP réels simultanés** : même soumission sans doublon, même approbation sans double publication, deux administrateurs sur une proposition avec un seul gagnant, deux propositions de même référence avec une seule création publiée.
- Panne PostgreSQL injectée après l’étape de publication : article, résultat de création du catalogue, décision, résultat de revue et audit sont annulés ensemble. La proposition reste en attente.
- Formulaires de soumission, d’approbation et de refus exécutés sans JavaScript ; motif reçu par le gestionnaire ; version initiale conservée après conflit de formulaire.
- Retour arrière de migration refusé en présence de résultats de propositions.
- Non-régression complète du catalogue, des catégories et cascades, des comptes, sessions natives, magasins, périmètres et invariants existants.

Les tests utilisent des schémas PostgreSQL temporaires, annulés ou supprimés après exécution. Les erreurs de migration et d’API délibérément déclenchées font partie des cas de refus attendus.

## Vérification locale

Catalogue, accès aux propositions, file administrative et formulaire de proposition ouverts dans le navigateur avec l’administrateur de développement. Formulaire vérifié sur ordinateur et mobile : largeur utile de 375 pixels sans débordement horizontal. La décision et sa publication sont vérifiées par les tests fonctionnels et les processus concurrents isolés, sans approuver de données métier réelles dans le navigateur.

Application, serveur web, base, worker et scheduler sains. Aucune proposition de démonstration ajoutée à la base locale ; données existantes du catalogue et des magasins conservées. Voir [le guide](propositions.md).
