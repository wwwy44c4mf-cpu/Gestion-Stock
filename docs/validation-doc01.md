# Validation DOC-01

## Périmètre

Consolidation des guides et livrables CDC §12/16 ; démonstration fictive réservée au développement ; installation sur une copie du source avec nouveaux secrets, volumes et projet Compose. Aucun déploiement de production, aucune fusion ni version stable publiée.

## Résultats locaux du 22 octobre 2026

`sh bin/test-installation` a réussi sur la copie temporaire `gestion-stock-doc01-1792655904-82348` : préparation de secrets neufs, construction depuis le source, démarrage des six services, **17 migrations**, refus de chargement en `prod`, chargement des cinq comptes/deux magasins/vingt articles, second chargement refusé, réconciliation sans écart et `composer validate --strict` sans avertissement de licence. La démo comporte 43 mouvements initiaux cohérents avec les soldes.

**7 tests PHP / 92 assertions** ont réussi : protections de la démo et de l’administrateur de développement, comptes marqués et hachés, refus de connexion et rafraîchissement hors `dev`, absence de remplacement d’une base non vide, rollback intégral d’une panne synthétique. Les **4 contrôles OpenAPI** ont réussi ; le document décrit 61 opérations et 97 schémas, dont 59 opérations disponibles et 2 encore prévues pour AUTH-04. HTTP a confirmé la disponibilité, la page de connexion, la connexion administrative de démo, les deux magasins, les vingt articles et l’écran Stocks.

Les rapports détaillés locaux sont dans `var/delivery/20261022T075824Z-82348/` (ignorés par Git). Le script a supprimé son projet, ses volumes et sa copie temporaire ; la base de travail n’a pas été utilisée. Le contrôle des **87 documents Markdown** confirme l’existence des cibles locales. La CI ajoute un emploi d’installation indépendante. Son premier essai Linux a révélé que la préparation lancée comme root créait des fichiers non modifiables par l’opérateur. La commande du README et du banc utilise désormais explicitement son UID/GID ; la nouvelle recette locale ci-dessus a réussi avec cette correction. L’emploi Linux « Installation vierge et documentation » a ensuite réussi sur le commit `db6c935` (exécution GitHub `39744380458`). Les contrôles du dernier commit restent consultables sur la [PR #88](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/pull/88).

Le premier essai a révélé un refus à tort lorsque l’ordonnanceur crée un audit technique sur une base métier vide. Le contrôle a été corrigé pour examiner les tables métier ; cet audit est conservé. La reprise ci-dessus a réussi sur une autre installation vierge.

Le contrôle `python3 docker/documentation/check.py` vérifie l’existence des cibles de liens Markdown locales, sans consulter les sites externes. Il ne prouve ni la justesse d’une phrase ni la validité d’un serveur de production. Le parcours de présentation et les guides restent à faire valider au responsable fonctionnel lors de la livraison.

## Décisions

Licence propriétaire, tous droits réservés, confirmée par le propriétaire. Le dépôt GitHub est actuellement public ; ce choix de licence n’a pas modifié sa visibilité. Le propriétaire a confirmé le 22 octobre 2026 prendre lui-même la responsabilité du serveur, des sauvegardes et des incidents. AUTH-04 reste absent et son issue #8 ouverte : ce constat est signalé dans les guides et la fiche, sans prétendre livrer cette fonctionnalité dans DOC-01. Les autres paramètres d’ouverture sont listés dans [la fiche de livraison](livraison.md).
