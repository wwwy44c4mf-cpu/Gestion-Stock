# Validation QA-01

Recette exécutée le 22 octobre 2026 sur données fictives isolées, sans modification des données de développement. PR #87, dépendante de RGPD-01 (#86).

## Résultats

- 400 tests PHP : 5 053 assertions, aucune erreur ni exclusion.
- 4 contrôles OpenAPI et 5 tests du banc de mesure réussis.
- 15 contrôles HTTP réels : droits, concurrence, transferts, idempotence, inventaire, retours, correction, catalogue et révocation de session.
- Matrice REC-01 à REC-18 vérifiée à partir des résultats PHPUnit et du test de sauvegarde/restauration.
- Sauvegarde/restauration et déploiement HTTPS/reprise réussis.
- Le premier lancement local complet s’est interrompu au diagnostic initial du test d’incidents. Sa cause n’a pas été établie. La reprise isolée de `bin/test-operations` a réussi : panne worker, refus SMTP, panne base, diagnostics et expurgation des journaux. Le lancement complet initial ne doit donc pas être présenté comme réussi sans reprise.
- Les deux emplois de la [CI sur le commit 6d5f16a](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/actions/runs/39699676187) ont réussi, y compris le test d’incidents.

## Performances locales

5 sessions, 2 magasins, 20 articles, 360 jours de 10 mouvements historiques quotidiens. 920 requêtes mesurées après 18 lectures de chauffe exclues ; 40 cycles par session. Le tableau de bord est mesuré uniquement avec les trois comptes autorisés.

| Parcours | Échantillons | p95 (ms) | Cible (ms) |
| --- | ---: | ---: | ---: |
| Stocks | 200 | 63,246 | < 2 000 |
| Historique | 200 | 85,309 | < 2 000 |
| Statistiques | 200 | 77,850 | < 2 000 |
| Tableau de bord | 120 | 67,802 | < 2 000 |
| Consommation | 200 | 68,659 | < 3 000 |

Mesure interne Docker en HTTP, PHP 8.4.26 en mode production, PostgreSQL 18.6, aarch64 ; hôte Docker doté de 8 CPU et 4 106 604 544 octets de mémoire. Durée mesurée : 9,095 s. Aucun stock négatif ni écart entre solde et journal après la charge.

Ces mesures valident le jeu et la machine testés ; elles n’incluent ni latence Internet ni TLS et ne garantissent pas les temps du futur serveur. [Rapport agrégé reproductible](qa01-performance.json) et [méthode de recette](recette-qa01.md).

Les rapports détaillés locaux sont conservés dans `var/qa/20261022T002401Z-77430/` (ignorés par Git). Les conteneurs, volumes et secrets temporaires sont supprimés par les recettes.
