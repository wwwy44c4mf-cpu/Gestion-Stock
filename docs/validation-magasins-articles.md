# Recette de la maquette des magasins — 16 novembre 2026

Périmètre : [articles, fonctions CA et affectations](magasins-articles-perimetres.md), issues #126, #127 et #128. Le raccourci de suppression d’un article désactivé (#125) reste inclus dans cette maquette.

## Vérifications

- Formulaires : sorties et ajustements limités au magasin choisi, transfert au magasin de départ ; entrées et retours sur le catalogue complet. Changement de magasin : références actualisées et quantité effacée. Saisie forgée refusée sans modification des stocks.
- Articles et stocks limités au magasin choisi, filtre avant pagination, conservation des références épuisées et des casages sans quantité.
- Nouvelle fiche associée au magasin choisi à zéro sans casage imposé ni mouvement fictif ; magasin non attribué refusé et création annulée intégralement.
- Utilisateurs et gestionnaires limités aux affectations actives ; administrateurs sur tous les magasins. Compte sans magasin, favori non autorisé, retrait d’affectation et identifiants directs vérifiés.
- Réglage Curatif Antennaire enregistré par l’administration. Fonctions masquées dans le magasin Conso sur Consommations, Sortie et Retour ; changement de magasin dans le formulaire suivi immédiatement.
- Sélecteurs du tableau de bord, de l’historique et des statistiques limités aux affectations actuelles, sans effacer les données personnelles historiques autorisées.

Recette générale : 516 tests PHP / 6 216 assertions, contrôles OpenAPI et scénarios HTTP réussis ; 920 requêtes à cinq sessions, aucun stock négatif et aucun écart de rapprochement. Rapport : `var/qa/20261116T161116Z-4466/`.

Les dernières adaptations des sélecteurs d’historique, statistiques et tableau de bord ont aussi passé une recette ciblée de 33 tests / 347 assertions. Affichage et navigation vérifiés en 375 et 1 280 pixels ; état des boutons CA, changement de magasin du formulaire, articles propres à chaque magasin, référence à zéro et refus d’un magasin non attribué contrôlés au navigateur.

## Exemples locaux

Dans la maquette http://127.0.0.1:18791 :

- **Blaise Pascal CA — démonstration** contient `DEMO-CA-ONLY`.
- **Blaise Pascal Conso — démonstration** contient `DEMO-CONSO-ONLY` et `DEMO-CONSO-ZERO` (épuisé, toujours affiché).
- Les références QA communes aux deux magasins restent visibles dans les deux, car elles possèdent réellement un stock et un historique dans chacun.
- `technician-a@example.test` n’a que le magasin CA ; `technician-b@example.test` n’a que le magasin Conso. Le compte administrateur peut consulter les deux.

Ces exemples ne sont pas ajoutés aux données réelles. Aucune migration ni modification de production. Filtrage confirmé : sorties et ajustements selon le magasin, transferts selon le départ ; entrées et retours ouverts au catalogue. Changement de magasin et saisie forgée contrôlés.

## Livraison autorisée

Maquette validée par l’exploitant le 16 novembre 2026. Publication autorisée. Pour cette mise à jour applicative, conserver `DATABASE_RELEASE_ID` à `99cbb3b54fa0cb98b43f3f71541a86a5d2322dc3` ; seul `RELEASE_ID` prend le nouveau commit. Aucune migration supplémentaire. Effectuer une sauvegarde chiffrée récente copiée hors serveur avant le redéploiement Portainer.
