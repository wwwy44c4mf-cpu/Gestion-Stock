# Magasin sélectionné, articles et affectations

Évolutions du 16 novembre 2026, en maquette locale avant mise en production : [CAT-04 #126](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/126), [UI-07 #127](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/127) et [AUTH-05 #128](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/128).

## Articles du magasin sélectionné

Les pages **Articles** et **Stocks** limitent leurs résultats au magasin choisi. Le filtre est appliqué avant les totaux et la pagination. Un article est lié au magasin s’il possède un solde, un casage ou des mouvements dans ce magasin. Les articles épuisés restent affichés à zéro. Les références jamais utilisées ou configurées dans ce magasin ne reçoivent plus une ligne artificielle à zéro dans sa liste.

La création d’un article permet de choisir son magasin initial, même sans casage. L’article y apparaît à zéro ; aucune entrée de matériel n’est inventée. Un casage et des seuils peuvent aussi établir le lien avec un magasin. La première véritable entrée, ou un retour dans un magasin autorisé, établit le lien naturellement. Les articles créés sans magasin initial restent dans le catalogue commun.

Le détail d’un stock peut être ouvert pour configurer une référence à zéro dans un magasin autorisé. Cela ne crée pas de stock par simple consultation. Les formulaires de **sortie** et d’**ajustement** proposent seulement les références de ce magasin, y compris à zéro. Le **transfert** se base sur le magasin de départ. Les **entrées** et **retours** gardent le catalogue complet pour introduire une référence dans le magasin autorisé. Un changement de magasin actualise la liste et efface la quantité saisie.

## Fonctions Curatif Antennaire

La fiche d’un magasin comporte le réglage **Magasin Curatif Antennaire**, modifiable par un administrateur. Il active les boutons **Outillage CA** et **Tourets de câble** dans Consommations, Sortie et Retour. Il doit rester désactivé pour **Blaise Pascal Conso** et activé pour les magasins CA. Le réglage utilise le champ existant `ca_enabled` et ne dépend pas du nom du magasin. Les boutons suivent aussi un changement de magasin dans les formulaires.

## Affectations

Les administrateurs conservent l’accès à tous les magasins. Les gestionnaires et utilisateurs ne peuvent choisir que leurs magasins actifs attribués par un administrateur, y compris pour les sorties, dépôts, casages et favoris. Un compte sans affectation ne reçoit aucun magasin de remplacement. Les contrôles serveur refusent un magasin non attribué même si son identifiant est fourni directement ; les anciennes sélections sont revalidées.

Cette décision remplace l’ancienne autorisation des techniciens à retirer ou déposer dans tout magasin actif. Elle ne supprime aucun mouvement et ne retire pas la lecture de l’historique personnel déjà autorisée. Un nouveau dépôt doit être effectué dans un magasin actuellement attribué, ou être saisi par un administrateur ou gestionnaire habilité.

Pas de nouvelle migration. Les données et autorisations de production ne sont pas modifiées pendant les essais de maquette.
