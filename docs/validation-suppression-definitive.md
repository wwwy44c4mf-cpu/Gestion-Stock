# Recette — ART-04 et UI-04

Demandes : [suppression définitive #114](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/114) et [dimensions des boutons #115](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/115). Mise à jour du 13 novembre 2026. Toutes les données manipulées pendant les essais sont fictives.

## Périmètre

Suppression définitive de vrais articles comme d’articles de test, avec leurs stocks et historiques, par administrateurs et gestionnaires autorisés sur tous les magasins concernés. Avertissement explicite avant confirmation. Aucun effacement sur le serveur réel.

Les cinq actions de stock utilisent la même grille et les mêmes dimensions : transfert, ajustement, entrée, consommation et casages. L’export manuel est retiré.

## Contrôles

La suite générale et les parcours navigateur sont réussis ; voir [la validation complète](validation-maquette.md). Les scénarios de suppression couvrent retours groupés, corrections, inventaires et alertes partagés, refus des utilisateurs, CSRF, référence recopiée, consentement explicite, conflit d’aperçu, annulation complète après erreur et réactivation des protections.

L’extension aux gestionnaires vérifie la suppression d’un article simulant une utilisation réelle avec toutes les affectations, le refus d’un magasin non affecté même à zéro, les anciens inventaires sans solde, le retrait d’une affectation et l’apparition d’un magasin non autorisé après aperçu.

## Maquette locale

Adresse : `http://127.0.0.1:18791`. Comptes de recette uniquement. La maquette sera complétée avec les prochaines modifications de l’exploitant avant validation d’ensemble, publication et production. La production reste sur `a9d72275fc83e79795fbabde6cdd1ee7818dcbe8`.
